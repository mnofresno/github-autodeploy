<?php

namespace GitAutoDeploy;

class Request {
    private $headers;
    private $queryParams;

    static function fromHttp(): Request {
        $result = new self();
        $result->headers = self::computeHeaders();
        $result->queryParams = self::computeQueryParams();
        return $result;
    }

    private static function computeHeaders(): array {
        return array_intersect_key(
            $_SERVER,
            array_flip(
                preg_grep(
                    '/^HTTP_/',
                    array_keys($_SERVER),
                    0
                )
            )
        );
    }

    private static function computeQueryParams(): array {
        return $_GET;
    }

    function getHeaders(): array {
        return $this->headers;
    }

    function getRemoteAddress(): string {
        return $_SERVER['REMOTE_ADDR'];
    }

    function getQueryParam(string $queryParamName): string {
        $value = array_key_exists($queryParamName, $this->queryParams)
            ? $this->queryParams[$queryParamName]
            : '';
        return self::sanitizeQueryparam($value);
    }

    private static function sanitizeQueryparam(string $input): string {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $input);
    }
}
