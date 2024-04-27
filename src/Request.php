<?php

namespace Mariano\GitAutoDeploy;

use JsonException;

class Request {
    const REPO_QUERY_PARAM = 'repo';
    const KEY_QUERY_PARAM = 'key';

    private $headers;
    private $queryParams;
    private $remoteAddr;
    private $body;

    static function fromHttp(array $givenServerVars = null): Request {
        $serverVars = $givenServerVars ?? $_SERVER;
        $result = new self();
        $result->headers = self::computeHeaders($serverVars);
        $result->queryParams = self::computeQueryParams($serverVars);
        $result->remoteAddr = $serverVars['REMOTE_ADDR'] ?? '';
        $result->body = self::doGetBody();
        return $result;
    }

    private static function computeHeaders(array $serverVars): array {
        $rawHeaders = array_intersect_key(
            $serverVars,
            array_flip(
                preg_grep(
                    '/^HTTP_/',
                    array_keys($serverVars),
                    0
                )
            )
        );
        $headers = [];
        foreach ($rawHeaders as $key => $value) {
            $sanitizedKey = strtolower(
                str_replace(
                    '_',
                    '-',
                    str_replace(
                        'HTTP_',
                        '',
                        $key
                    )
                )
            );
            $headers[$sanitizedKey] = $value;
        }
        return $headers;
    }

    private static function computeQueryParams(array $serverVars): array {
        parse_str($serverVars['QUERY_STRING'] ?? '', $output);
        return $output;
    }

    function getHeaders(): array {
        return $this->headers;
    }

    function getRemoteAddress(): string {
        return $this->remoteAddr;
    }

    function getQueryParam(string $queryParamName): string {
        $value = array_key_exists($queryParamName, $this->queryParams)
            ? $this->queryParams[$queryParamName]
            : '';
        return self::sanitizeQueryparam($value);
    }

    function getBody(): array {
        return $this->body;
    }

    private static function doGetBody(): array {
        try {
            return json_decode(
                file_get_contents("php://input"),
                true
            ) ?? [];
        } catch (JsonException $e) {
            return ['json_decode_error' => $e->getMessage()];
        }
    }

    private static function sanitizeQueryparam(string $input): string {
        return preg_replace('/[^A-Za-z0-9_\-]/', '_', $input);
    }
}
