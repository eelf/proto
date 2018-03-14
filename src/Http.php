<?php

class ProxyServer {
    private
        $hosts = [],
        $ingoing = [],
        $outgoing = [],
        $headers = [],
        $lengths = [];

    public function disconnect(Server $server, $stream_id) {
        return;
        if (isset($this->ingoing[$stream_id])) {
            $server->disconnect($this->ingoing[$stream_id]);
            unset($this->ingoing[$stream_id]);
        }
        if (isset($this->outgoing[$stream_id])) {
            $server->disconnect($this->outgoing[$stream_id]);
            unset($this->outgoing[$stream_id]);
        }
    }

    public function request(Server $server, $stream_id, &$buf) {
        if (isset($this->outgoing[$stream_id])) {
            $server->write($this->outgoing[$stream_id], $buf);
            $buf = '';
            return;
        }
        while (true) {
            if (!isset($this->lengths[$stream_id])) {
                $pos = strpos($buf, "\r\n\r\n");
                if ($pos === false) break;
                $headers = substr($buf, 0, $pos);
                $buf = substr($buf, $pos + 4);

                $headers = explode("\r\n", $headers);
                $headers_parsed = [];
                foreach ($headers as $header) {
                    if (preg_match('#(get|post|put|delete|head|options|connect) (http.*) (http/1..)#i', $header, $m)) {
                        $headers_parsed[0] = $m[0];
                        $headers_parsed[1] = $m[1];
                        $headers_parsed[2] = $m[2];
                        $headers_parsed[3] = $m[3];
                    } else {
                        $header = explode(':', $header, 2);
                        if (isset($header[1])) $headers_parsed[trim(strtolower($header[0]))] = trim($header[1]);
                        else $headers_parsed[] = trim($header[0]);
                    }
                }
                if (isset($headers_parsed['content-length'])) {
                    $this->lengths[$stream_id] = (int)$headers_parsed['content-length'];
                    $this->headers[$stream_id] = $headers_parsed;
                } else {
                    $this->processRequest($server, $stream_id, $headers_parsed, '');
                }
            } else {
                if (strlen($buf) < $this->lengths[$stream_id]) break;
                $body = substr($buf, 0, $this->lengths[$stream_id]);
                $buf = substr($buf, $this->lengths[$stream_id]);
                $this->processRequest($server, $stream_id, $this->headers[$stream_id], $body);
                unset($this->headers[$stream_id], $this->lengths[$stream_id]);
            }
        }
    }

    private function processRequest(Server $server, $stream_id, $headers_parsed, $body) {
        if (isset($headers_parsed[2])) {
            file_put_contents('log', date('r') . ' ' . $str, FILE_APPEND);
            $url = parse_url($headers_parsed[2]);

            $peer_id = isset($this->ingoing[$stream_id]) ? $this->ingoing[$stream_id] : null;
            if (!isset($this->hosts[$stream_id]) || $this->hosts[$stream_id] != $url['host']) {
                if ($peer_id) {
                    $server->disconnect($peer_id);
                }
                $this->hosts[$stream_id] = $url['host'];
                $peer_id = $server->connect($url['host'], 'http');
            }

            $path = $url['path'] . (isset($url['query']) ? '?' . $url['query'] : '');
            $response = "$headers_parsed[1] $path $headers_parsed[3]\r\n";

            foreach ($headers_parsed as $idx => $header) {
                if (!is_numeric($idx)) $response .= "$idx: $header\r\n";
            }
            $response .= "\r\n" . $body;

            $server->write($peer_id, $response);

            $this->outgoing[$peer_id] = $stream_id;
            $this->ingoing[$stream_id] = $peer_id;
        } else {
            $response = '';
            static $HttpHandler;
            if (!$HttpHandler) $HttpHandler = new HttpHandler();
            list ($response_headers, $response_body) = $HttpHandler->handleRequest($headers_parsed, '');
            foreach ($response_headers as $header => $value) {
                $response .= $header === 0 ? "$value\r\n" : "$header: $value\r\n";
            }
            $response .= "\r\n" . $response_body;
            $server->write($stream_id, $response);
            if (isset($response_headers['Connection']) && $response_headers['Connection'] == 'close') $server->write($stream_id, false);
        }
    }
}

class HttpServer {
    private
        $handler,
        $headers = [],
        $lengths = [];

    public function setDefaultHandler($handler) {
        $this->handler = $handler;
    }

    private static function parseHeaders($headers_buf) {
        $headers_buf = explode("\r\n", $headers_buf);
        $headers = [];
        foreach ($headers_buf as $header) {
            $header = explode(':', $header, 2);
            if (isset($header[1])) $headers[trim(strtolower($header[0]))] = trim($header[1]);
            else $headers[0] = trim($header[0]);
        }
        return $headers;
    }

    private static function serHeaders($headers) {
        $serialized = '';
        foreach ($headers as $header => $value) {
            $serialized .= $header === 0 ? "$value\r\n" : "$header: $value\r\n";
        }
        return $serialized;
    }

    public function request(Server $server, $stream_id, &$buf) {
        while (true) {
            if (!isset($this->lengths[$stream_id])) {
                $pos = strpos($buf, "\r\n\r\n");
                if ($pos === false) break; /* not enough buffer for headers */
                $headers_buf = substr($buf, 0, $pos);
                $buf = substr($buf, $pos + 4);
                $this->headers[$stream_id] = self::parseHeaders($headers_buf);

                if (isset($this->headers[$stream_id]['content-length'])) {
                    $this->lengths[$stream_id] = (int)$this->headers[$stream_id]['content-length'];
                } else {
                    $handler = $this->handler;
                    list ($response_headers, $response_body) = $handler($this->headers[$stream_id], '');
                    $response = self::serHeaders($response_headers) . "\r\n" . $response_body;
                    $server->write($stream_id, $response);
                    if (isset($response_headers['Connection']) && $response_headers['Connection'] == 'close') $server->write($stream_id, false);
                }
            } else if (mb_orig_strlen($buf) < $this->lengths[$stream_id]) {
                break;
            } else {
                $data = substr($buf, 0, $this->lengths[$stream_id]);
                $buf = substr($buf, $this->lengths[$stream_id]);
                $handler = $this->handler;
                list ($response_headers, $response_body) = $handler($this->headers[$stream_id], $data);
                $response = self::serHeaders($response_headers) . "\r\n" . $response_body;
                $server->write($stream_id, $response);
                if (isset($response_headers['Connection']) && $response_headers['Connection'] == 'close') $server->write($stream_id, false);
            }
        }
    }
}

class ProxyServer {
    private
        $hosts = [],
        $ingoing = [],
        $outgoing = [],
        $headers = [],
        $lengths = [];

    public function disconnect(Server $server, $stream_id) {
        return;
        if (isset($this->ingoing[$stream_id])) {
            $server->disconnect($this->ingoing[$stream_id]);
            unset($this->ingoing[$stream_id]);
        }
        if (isset($this->outgoing[$stream_id])) {
            $server->disconnect($this->outgoing[$stream_id]);
            unset($this->outgoing[$stream_id]);
        }
    }

    public function request(Server $server, $stream_id, &$buf) {
        if (isset($this->outgoing[$stream_id])) {
            $server->write($this->outgoing[$stream_id], $buf);
            $buf = '';
            return;
        }
        while (true) {
            if (!isset($this->lengths[$stream_id])) {
                $pos = strpos($buf, "\r\n\r\n");
                if ($pos === false) break;
                $headers = substr($buf, 0, $pos);
                $buf = substr($buf, $pos + 4);

                $headers = explode("\r\n", $headers);
                $headers_parsed = [];
                foreach ($headers as $header) {
                    if (preg_match('#(get|post|put|delete|head|options|connect) (http.*) (http/1..)#i', $header, $m)) {
                        $headers_parsed[0] = $m[0];
                        $headers_parsed[1] = $m[1];
                        $headers_parsed[2] = $m[2];
                        $headers_parsed[3] = $m[3];
                    } else {
                        $header = explode(':', $header, 2);
                        if (isset($header[1])) $headers_parsed[trim(strtolower($header[0]))] = trim($header[1]);
                        else $headers_parsed[] = trim($header[0]);
                    }
                }
                if (isset($headers_parsed['content-length'])) {
                    $this->lengths[$stream_id] = (int)$headers_parsed['content-length'];
                    $this->headers[$stream_id] = $headers_parsed;
                } else {
                    $this->processRequest($server, $stream_id, $headers_parsed, '');
                }
            } else {
                if (strlen($buf) < $this->lengths[$stream_id]) break;
                $body = substr($buf, 0, $this->lengths[$stream_id]);
                $buf = substr($buf, $this->lengths[$stream_id]);
                $this->processRequest($server, $stream_id, $this->headers[$stream_id], $body);
                unset($this->headers[$stream_id], $this->lengths[$stream_id]);
            }
        }
    }

    private function processRequest(Server $server, $stream_id, $headers_parsed, $body) {
        if (isset($headers_parsed[2])) {
            file_put_contents('log', date('r') . ' ' . $str, FILE_APPEND);
            $url = parse_url($headers_parsed[2]);

            $peer_id = isset($this->ingoing[$stream_id]) ? $this->ingoing[$stream_id] : null;
            if (!isset($this->hosts[$stream_id]) || $this->hosts[$stream_id] != $url['host']) {
                if ($peer_id) {
                    $server->disconnect($peer_id);
                }
                $this->hosts[$stream_id] = $url['host'];
                $peer_id = $server->connect($url['host'], 'http');
            }

            $path = $url['path'] . (isset($url['query']) ? '?' . $url['query'] : '');
            $response = "$headers_parsed[1] $path $headers_parsed[3]\r\n";

            foreach ($headers_parsed as $idx => $header) {
                if (!is_numeric($idx)) $response .= "$idx: $header\r\n";
            }
            $response .= "\r\n" . $body;

            $server->write($peer_id, $response);

            $this->outgoing[$peer_id] = $stream_id;
            $this->ingoing[$stream_id] = $peer_id;
        } else {
            $response = '';
            static $HttpHandler;
            if (!$HttpHandler) $HttpHandler = new HttpHandler();
            list ($response_headers, $response_body) = $HttpHandler->handleRequest($headers_parsed, '');
            foreach ($response_headers as $header => $value) {
                $response .= $header === 0 ? "$value\r\n" : "$header: $value\r\n";
            }
            $response .= "\r\n" . $response_body;
            $server->write($stream_id, $response);
            if (isset($response_headers['Connection']) && $response_headers['Connection'] == 'close') $server->write($stream_id, false);
        }
    }
}

class HttpServer {
    private
        $handler,
        $headers = [],
        $lengths = [];

    public function setDefaultHandler($handler) {
        $this->handler = $handler;
    }

    private static function parseHeaders($headers_buf) {
        $headers_buf = explode("\r\n", $headers_buf);
        $headers = [];
        foreach ($headers_buf as $header) {
            $header = explode(':', $header, 2);
            if (isset($header[1])) $headers[trim(strtolower($header[0]))] = trim($header[1]);
            else $headers[0] = trim($header[0]);
        }
        return $headers;
    }

    private static function serHeaders($headers) {
        $serialized = '';
        foreach ($headers as $header => $value) {
            $serialized .= $header === 0 ? "$value\r\n" : "$header: $value\r\n";
        }
        return $serialized;
    }

    public function request(Server $server, $stream_id, &$buf) {
        while (true) {
            if (!isset($this->lengths[$stream_id])) {
                $pos = strpos($buf, "\r\n\r\n");
                if ($pos === false) break; /* not enough buffer for headers */
                $headers_buf = substr($buf, 0, $pos);
                $buf = substr($buf, $pos + 4);
                $this->headers[$stream_id] = self::parseHeaders($headers_buf);

                if (isset($this->headers[$stream_id]['content-length'])) {
                    $this->lengths[$stream_id] = (int)$this->headers[$stream_id]['content-length'];
                } else {
                    $handler = $this->handler;
                    list ($response_headers, $response_body) = $handler($this->headers[$stream_id], '');
                    $response = self::serHeaders($response_headers) . "\r\n" . $response_body;
                    $server->write($stream_id, $response);
                    if (isset($response_headers['Connection']) && $response_headers['Connection'] == 'close') $server->write($stream_id, false);
                }
            } else if (mb_orig_strlen($buf) < $this->lengths[$stream_id]) {
                break;
            } else {
                $data = substr($buf, 0, $this->lengths[$stream_id]);
                $buf = substr($buf, $this->lengths[$stream_id]);
                $handler = $this->handler;
                list ($response_headers, $response_body) = $handler($this->headers[$stream_id], $data);
                $response = self::serHeaders($response_headers) . "\r\n" . $response_body;
                $server->write($stream_id, $response);
                if (isset($response_headers['Connection']) && $response_headers['Connection'] == 'close') $server->write($stream_id, false);
            }
        }
    }
}
