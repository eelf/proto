<?php
namespace Proto;

class HttpResponse {
    public static function fromStr($str) {
        $headers_arr = explode("\r\n\r\n", $str, 2);
        $body = $headers_arr[1] ?? null;
        $headers = [];
        foreach (explode("\r\n", $headers_arr[0]) as $header) {
            $header = explode(':', $header, 2);
            $header_name = strtolower(trim($header[0]));
            $header = isset($header[1]) ? trim($header[1]) : false;
            if ($header_name == 'status') {
                list ($code, $text) = explode(' ', $header, 2);
                $headers['status_code'] = $code;
                $headers['status_text'] = $text;
            }
            if (isset($headers[$header_name])) {
                if (!is_array($headers[$header_name])) {
                    $headers[$header_name] = [$headers[$header_name]];
                }
                $headers[$header_name][] = $header;
            } else {
                $headers[$header_name] = $header;
            }
        }

        return [$headers, $body];
    }
}
