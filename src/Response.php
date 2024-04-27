<?php

namespace Mariano\GitAutoDeploy;

class Response {
    const STATUS_MAP = [
        200 => 'OK',
        400 => 'Bad Request',
        403 => 'Forbidden'
    ];

    private $body = '';
    private $statusCode = 200;

    function addToBody(string $contents) {
        $this->body .= $contents;
    }

    function setStatusCode(int $statusCode) {
        $this->statusCode = $statusCode;
    }

    function send(): void {
        header(
            sprintf(
                "HTTP/1.1 %s %s",
                $this->statusCode,
                self::STATUS_MAP[$this->statusCode]
            )
        );
        echo $this->body;
    }
}
