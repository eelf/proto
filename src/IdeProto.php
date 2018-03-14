<?php

namespace Proto;

use Net\TEvent;

class IdeProto extends StreamProto {
    use TEvent;
    const EVENT_IDE_PKT = 1;

    public static function ser($cmd, $args) {
        return "$cmd $args\0";
    }

    public function data($data) {
        parent::data($data);

        while ($packet = $this->splitIdeBufIntoPacket()) {
            $this->emit(self::EVENT_IDE_PKT, $packet[0], $packet[1]);
        }
    }

    protected function splitIdeBufIntoPacket() {
        $nul_pos = $this->peakByte("\0");
        if ($nul_pos === false) return null;
        $packet = explode(' ', $this->consume($nul_pos), 2);
        //drop null byte
        $this->consume(1);
        return [$packet[0], $packet[1]];
    }

    public static function parseIdeArgs($args) {
        //todo https://xdebug.org/docs-dbgp.php
        $args = explode(' ', $args);
        $result = [];
        for ($i = 0; $i < count($args) - 1; $i++) {
            if (in_array($args[$i], ['-p', '-k', '-m', '-i', '-n', '-v', '-f', '-t'])) {
                $key = $args[$i][1];
                $i++;
                $result[$key] = $args[$i];
            } else {
                return [null, "Unknown arg[$i]:" . $args[$i] . " args:" . var_export($args, true)];
            }
        }
        return [$result, null];
    }

    public static function buildArgs($args) {
        $args_plain = [];
        foreach ($args as $key => $value) {
            $args_plain[] = "-$key";
            $args_plain[] = $value;
        }
        return implode(' ', $args_plain);
    }
}
