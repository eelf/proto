<?php

namespace Proto;

use Net\TEvent;

class Xdebug extends StreamProto {
    use TEvent;
    const EVENT_XDEBUG_PKT = 1;

    private $length;

    public static function ser(\SimpleXMLElement $doc) {
        $packet = $doc->asXML();
        return strlen($packet) . "\0$packet\0";
    }

    public function data($data) {
        parent::data($data);

        while ($packet = $this->splitBufIntoPacket()) {
            $this->emit(self::EVENT_XDEBUG_PKT, $packet);
        }
    }

    protected function splitBufIntoPacket() {
        if ($this->length === null) {
            $null_pos = $this->peakByte("\0");
            if ($null_pos === false) {
                return null;
            }

            $this->length = (int)$this->consume($null_pos);
            //drop null byte
            $this->consume(1);
        }
        if ($this->size() >= $this->length + 1) {
            $packet = $this->consume($this->length);
            //drop null byte
            $end = $this->consume(1);

            // todo check for null byte
            if ($end != "\0") {
                return null;
            }
            $this->length = null;
            return new \SimpleXMLElement($packet);
        }
        return null;
    }
}
