<?php

namespace Proto;

class FcgiMessage {
    const VERSION = 1;
    const BEGIN_REQUEST = 1;
    const PARAMS = 4;

    private $version,
        $type,
        $r_id,
        $len,
        $pad,
        $reserved,
        $payload;

    public function __construct($type) {
        $this->version = self::VERSION;
        $this->type = $type;
        $this->r_id = 1;
        $this->reserved = 0;
    }

    public function setPayload($payload) {
        $this->payload = $payload;
        $this->len = strlen($payload);
        $this->pad = 8 - (($this->len % 8) ?: 8);
        $this->payload .= str_repeat("\x0", $this->pad);
    }

    public function __toString() {
        $str = chr($this->version)
            . chr($this->type)
            . chr($this->r_id >> 8) . chr($this->r_id & 0xff)
            . chr($this->len >> 8) . chr($this->len & 0xff)
            . chr($this->pad)
            . chr($this->reserved)
            . $this->payload;
        return $str;
    }

    public static function serializeParams($params) {
        $str = '';
        foreach ($params as $name => $value) {
            $str .= chr(strlen($name))
                . chr(strlen($value))
                . $name
                . $value;
        }
        return $str;
    }
}
