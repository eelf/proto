<?php

namespace Proto;

use Net\TEvent;

class FastCgi extends StreamProto {
    use TEvent;
    const EVENT_PKT = 1;

    public static function ser($cmd, $args) {
        $args_plain = [];
        foreach ($args as $key => $value) {
            $args_plain[] = "-$key";
            $args_plain[] = $value;
        }
        $packet = implode(' ', array_merge([$cmd], $args_plain));
        var_dump($packet);
        return "$packet\0";
    }

    public function data($data) {
        parent::data($data);

        while ($packet = $this->splitIdeBufIntoPacket()) {
            $this->emit(self::EVENT_IDE_PKT, $type, $args);
        }
    }

    protected function splitIdeBufIntoPacket() {

        $begin = new FCGIMessage(FCGIMessage::BEGIN_REQUEST);
        $begin->setPayload("\x0\x1\x0\x0\x0\x0\x0\x0");

        $fcgim_params = new FCGIMessage(FCGIMessage::PARAMS);
        $params = [

            'HTTP_USER_AGENT' => 'curl/7.40.0',
            'HTTP_HOST' => 'localhost:1080',
            'HTTP_ACCEPT' => '*/*',
            'SCRIPT_NAME' => '/php-fpm-status',
            'SCRIPT_FILENAME' => '/php-fpm-status',
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/php-fpm-status?html=1&full=1',
            'QUERY_STRING' => 'full=1',
        ];
        $fcgim_params->setPayload(FCGIMessage::serializeParams($params));

        $fcgim_params_end = new FCGIMessage(FCGIMessage::PARAMS);
        $fcgim_params_end->setPayload('');

        $str = (string)$begin . (string)$fcgim_params . (string)$fcgim_params_end;

// -- cut here --
        $nul_pos = $this->peakByte("\0");
        if ($nul_pos === false) return null;
        $packet = explode(' ', $this->consume($nul_pos), 2);
        //drop null byte
        $this->consume(1);
        return [$packet[0], $packet[1]];
    }
}
