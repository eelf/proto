<?php

class GpbsRpc
{
    const ERRNO_NO_ERROR           = 0;
    const ERRNO_GENERIC            = -1;
    const ERRNO_RUN_FAILED         = -2;
    const ERRNO_ALREADY_RUNNING    = -3;
    const ERRNO_NOT_FOUND          = -4;
    const ERRNO_WORKING            = -5;
    const ERRNO_NO_MEMORY          = -6;
    const ERRNO_SUCCESS_FINISHED   = -7;
    const ERRNO_FAILED_FINISHED    = -8;
    const ERRNO_WAIT_FOR_FREE      = -9;
    const ERRNO_START_CHILD_FAILED = -10;
    const ERRNO_TOO_BUSY           = -11;
    const ERRNO_SCRIPT_NOT_ALLOWED = -12;

    function run()
    {
        $this->so = GPBS::import('GpbsRpc');
        $this->messages = array_map('strtolower', gpbs_enum_values($this->so->request_msgid));

        $backend = 0;
        if (Ev::supportedBackends() & Ev::BACKEND_KQUEUE) $backend = Ev::BACKEND_KQUEUE;
        else if (Ev::supportedBackends() & Ev::BACKEND_EPOLL) $backend = Ev::BACKEND_EPOLL;
        $this->loop = new EvLoop($backend);
        $io = $this->loop->io($this->server, Ev::READ, array($this, 'cb'), 'server');
        $this->loop->run();
    }

    function command($stream_id, $msg_name, $msg) {
        $res = gpbs_unserialize($this->so->$msg_name, $msg);

        if ($msg_name == 'request_check') {
            $status = null;
            if (!isset($this->hashes[$res['hash']])) {
                $this->response($stream_id, 'response_generic', ['error_code' => self::ERRNO_NOT_FOUND]);
                return;
            }
            if (!isset($this->hashes[$res['hash']]['exit'])) {
                $this->response($stream_id, 'response_generic', ['error_code' => self::ERRNO_ALREADY_RUNNING]);
                return;
            }
            $this->response(
                $stream_id,
                'response_check',
                [
                    'error_code' => self::ERRNO_SUCCESS_FINISHED,
                    'utime_tv_sec' => 0,
                    'utime_tv_usec' => 200012,
                    'stime_tv_sec' => 0,
                    'stime_tv_usec' => 40002,
                    'retcode' => $this->hashes[$res['hash']]['exit'],
                ]
            );
            return;
        } else if ($msg_name == 'request_free') {
            if (!isset($this->hashes[$res['hash']])) {
                $this->response($stream_id, 'response_generic', ['error_code' => self::ERRNO_NOT_FOUND]);
                return;
            }
            unset($this->hashes[$res['hash']]);
            $this->response($stream_id, 'response_generic', ['error_code' => self::ERRNO_NO_ERROR]);
            return;
        } else if ($msg_name == 'request_terminate') {
            if (!isset($this->hashes[$res['hash']])) {
                $this->response($stream_id, 'response_generic', ['error_code' => self::ERRNO_NOT_FOUND]);
                return;
            }
            $result = posix_kill($this->hashes[$res['hash']]['pid'], SIGTERM);
            $this->response($stream_id, 'response_generic', ['error_code' => $result ? self::ERRNO_NO_ERROR : self::ERRNO_GENERIC]);
            return;
        }
        $pid = pcntl_fork();
        if ($pid == -1) die("fork failed\n");
        if ($pid == 0) {
            try {
                freopen('/dev/null', 0);
                freopen('/logs/gpbsrpc.' . $res['hash'] . '.out.log', 1);
                freopen('/logs/gpbsrpc.' . $res['hash'] . '.err.log', 2);
                $args = array_merge($res['script'], $res['params']);
                cli_set_process_title("gpbsrpc: " . implode(" ", $args));

                $this->clients = $this->streams = $this->read_buf = $this->write_buf = $this->ws = $this->address =
                $this->hashes = $this->pid_to_hash = $this->length = $this->messages = $this->so =
                $this->loop = $this->server = null;
                // @todo run request
            } finally {
                exit(0);
            }
        }
        $this->hashes[$res['hash']]['pid'] = $pid;
        $this->pid_to_hash[$pid] = $res['hash'];
        $this->response($stream_id, 'response_generic', ['error_code' => self::ERRNO_NO_ERROR]);
    }

    function response($stream_id, $msg_name, $response) {
        $msg = $this->so->$msg_name;
        $this->write($stream_id, gpbs_pack($msg, $response));
    }

    function write($stream_id, $buf)
    {
        $this->write_buf[$stream_id] .= $buf;
        $key = Ev::WRITE . '_' . $stream_id;
        if (!isset($this->ws[$key])) $this->ws[$key] = $this->loop->io($this->streams[$stream_id], Ev::WRITE, array($this, 'cb'), $stream_id);
    }

    function cb(EvIo $w, $revents)
    {
        while (true) {
            $status = null;
            $pid = pcntl_wait($status, WNOHANG);
            if ($pid <= 0) {
                break;
            }
            if (isset($this->pid_to_hash[$pid])) {
                $this->hashes[$this->pid_to_hash[$pid]]['exit'] = pcntl_wexitstatus($status);
            }
        }

        if ($revents & Ev::READ) {
            if ($w->data === 'server') {
                $client = stream_socket_accept($this->server);
                $client_id = (int)$client;
                if (!$client) die("accept failed\n");

                $this->ws[Ev::READ . '_' . $client_id] = $this->loop->io($client, Ev::READ, array($this, 'cb'), $client_id);

                $this->read_buf[$client_id] = '';
                $this->write_buf[$client_id] = '';
                $this->clients[$client_id] = array();
                $this->streams[$client_id] = $client;
            } else {
                $stream_id = $w->data;

                $buf = fread($this->streams[$stream_id], 8192);
                if ($buf === false || $buf === '') {
                    $this->disconnect($stream_id);
                    return;
                }
                $this->read_buf[$stream_id] .= $buf;

                while (true) {
                    if (!isset($this->length[$stream_id])) {
                        if (mb_orig_strlen($this->read_buf[$stream_id]) < 4) {
                            /* not enough data in buf for read length */
                            break;
                        }
                        $this->length[$stream_id] = $this->msg_length($this->read_buf[$stream_id]);
                        $this->read_buf[$stream_id] = mb_orig_substr($this->read_buf[$stream_id], 4);
                    } else {
                        if (mb_orig_strlen($this->read_buf[$stream_id]) < $this->length[$stream_id]) {
                            /* not enough data in buf for read packet */
                            break;
                        }

                        $buf = mb_orig_substr($this->read_buf[$stream_id], 0, $this->length[$stream_id]);
                        $this->read_buf[$stream_id] = mb_orig_substr($this->read_buf[$stream_id], $this->length[$stream_id]);

                        $msgid = mb_orig_substr($buf, 0, 4);
                        $msgid = ord($msgid[0]) << 24 | ord($msgid[1]) << 16 | ord($msgid[2]) << 8 | ord($msgid[3]);
                        $msg_name = $this->messages[$msgid];
                        $msg = mb_orig_substr($buf, 4);

                        $this->command($stream_id, $msg_name, $msg);
                        unset($this->length[$stream_id]);
                    }
                }
            }
        }
        if ($revents & Ev::WRITE) {
            $stream_id = $w->data;
            if (!isset($this->write_buf[$stream_id])) {
                return;
            }
            $wrote = fwrite($this->streams[$stream_id], $this->write_buf[$stream_id]);
            if ($wrote === false) die("write failed\n");
            if ($wrote == mb_orig_strlen($this->write_buf[$stream_id])) {
                $this->write_buf[$stream_id] = '';
                unset($this->ws[Ev::WRITE . '_' . $stream_id]);
            } else $this->write_buf[$stream_id] = mb_orig_substr($this->write_buf[$stream_id], $wrote);
        }
    }

    private function msg_length($buf) {
        return ord($buf[0]) << 24 | ord($buf[1]) << 16 | ord($buf[2]) << 8 | ord($buf[3]);
    }
}
