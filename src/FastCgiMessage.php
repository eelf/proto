<?php

namespace Proto;

class FastCgiMessage {
    const VERSION_1 = 1;

    const BEGIN_REQUEST = 1;
    const ABORT_REQUEST = 2;
    const END_REQUEST = 3;
    const PARAMS = 4;
    const STDIN = 5;
    const STDOUT = 6;
    const STDERR = 7;
    const DATA = 8;
    const GET_VALUES = 9;
    const GET_VALUES_RESULT = 10;
    const UNKNOWN_TYPE = 11;

    const RESPONDER = 1;
    const AUTHORIZER = 2;
    const FILTER = 3;

    const REQUEST_COMPLETE = 0;
    const CANT_MPX_CONN = 1;
    const OVERLOADED = 2;
    const UNKNOWN_ROLE = 3;

    private $version,
        $r_id,
        $type,
        $len,
        $pad,
        $reserved,
        $payload,
        $padding;

    public function __construct($r_id, $type) {
        $this->version = self::VERSION_1;
        $this->r_id = $r_id;
        $this->type = $type;
        $this->reserved = 0;
    }

    public function setPayload($payload, $padding = null) {
        $this->payload = $payload;
        $this->len = strlen($payload);
        $this->pad = 8 - (($this->len % 8) ?: 8);
        $this->padding = $padding === null ? str_repeat("\x0", $this->pad) : $padding;
    }

    public function getPayload() {
        return $this->payload;
    }

    public function getEndResult() {
        return ord($this->payload{4});
    }

    public function getRId() {
        return $this->r_id;
    }

    public function getType() {
        return $this->type;
    }

    public function getLen() {
        return $this->len;
    }

    public function getPad() {
        return $this->pad;
    }

    public function __toString() {
        $str = chr($this->version)
            . chr($this->type)
            . chr($this->r_id >> 8) . chr($this->r_id & 0xff)
            . chr($this->len >> 8) . chr($this->len & 0xff)
            . chr($this->pad)
            . chr($this->reserved)
            . $this->payload
            . $this->padding;
        return $str;
    }

    public static function serializeParams($params) {
        $str = '';
        foreach ($params as $name => $value) {
            $str .= self::varLen(strlen($name))
                . self::varLen(strlen($value))
                . $name
                . $value;
        }
        return $str;
    }

    public static function payloadBegin($role, $keep_alive) {
        return chr(0) . chr($role) . chr($keep_alive) . str_repeat(chr(0), 5);
    }

    public static function fromHeader($header) {
        $msg = new self(0, ord($header{1}));
        $msg->version = ord($header{0});
        $msg->r_id = (ord($header{2}) << 8) + ord($header{3});
        $msg->len = (ord($header{4}) << 8) + ord($header{5});
        $msg->pad = ord($header{6});
        $msg->reserved = ord($header{7});
        return $msg;
    }

    public static function varLen($len) {
        if ($len < 128) return chr($len);
        else return chr(($len >> 24) | 0x80) . chr(($len >> 16) & 0xFF) . chr(($len >> 8) & 0xFF) . chr($len & 0xFF);
    }

    public static function serializedBegin($r_id, $keep_alive)
    {
        $Packet = new self($r_id,self::BEGIN_REQUEST);
        $Packet->setPayload(self::payloadBegin(self::RESPONDER, $keep_alive));
        return (string)$Packet;
    }

    public static function serializedParams($r_id, $params)
    {
        $Packet = new self($r_id, self::PARAMS);
        $Packet->setPayload($params ? self::serializeParams($params) : '');
        return (string)$Packet;
    }

    public static function serializedStdin($r_id, $stdin)
    {
        $Packet = new self($r_id, self::STDIN);
        $Packet->setPayload($stdin);
        return (string)$Packet;
    }
}
