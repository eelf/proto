<?php

namespace Proto;

class FastCgi extends StreamProto {
    use \Net\TEvent;

    const EVENT_PKT = 1;
    const HEADER_LEN = 8;
    const R_ID_MAX = 0x10000;

    private static $r_id_factory;

    private $msg;

    public static function ser($keep_alive, array $params, $stdin) {
        $request_id = ++self::$r_id_factory;
        if (self::$r_id_factory >= self::R_ID_MAX) {
            self::$r_id_factory = 1;
        }

        $request = FastCgiMessage::serializedBegin($request_id, $keep_alive)
            . FastCgiMessage::serializedParams($request_id, $params)
            . FastCgiMessage::serializedParams($request_id, '');

        if ($stdin) {
            $request .= FastCgiMessage::serializedStdin($request_id, $stdin)
                . FastCgiMessage::serializedStdin($request_id, '');
        }
        return $request;
    }

    public function data($data) {
        parent::data($data);

        while ($packet = $this->splitIdeBufIntoPacket()) {
            $this->emit(self::EVENT_PKT, $packet);
        }
    }

    protected function splitIdeBufIntoPacket() {
        if (!$this->msg && $this->size() >= self::HEADER_LEN) {
            $header = $this->consume(self::HEADER_LEN);
            $this->msg = FastCgiMessage::fromHeader($header);
        }
        $msg = null;
        if ($this->msg && $this->size() >= $this->msg->getLen() + $this->msg->getPad()) {
            $content = $this->consume($this->msg->getLen());
            $padding = $this->consume($this->msg->getPad());
            $this->msg->setPayload($content, $padding);
            $msg = $this->msg;
            $this->msg = null;
        }
        return $msg;
    }
}
