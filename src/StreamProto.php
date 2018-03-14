<?php

namespace Proto;

class StreamProto {
    protected $buf;

    public function data($data) {
        $this->buf .= $data;
    }

    public function size() {
        return strlen($this->buf);
    }

    public function peakByte($byte) {
        return strpos($this->buf, $byte);
    }

    public function consume($bytes_cnt) {
        $data = substr($this->buf, 0, $bytes_cnt);
        $this->buf = substr($this->buf, $bytes_cnt);
        return $data;
    }
}
