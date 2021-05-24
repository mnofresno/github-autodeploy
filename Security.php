<?php

namespace GithubAutoDeploy;

use GithubAutoDeploy\views\Forbidden;
use GithubAutoDeploy\exceptions\BadRequestException;

class Security {
    static function assert(array $allowedIps, array $headers, string $remoteAddr) {
        $allowed = false;
        if (array_key_exists("HTTP_X_FORWARDED_FOR", $headers)) {
            $ips = explode(",",$headers["HTTP_X_FORWARDED_FOR"]);
            $ip  = $ips[0];
        } else {
            $ip = $remoteAddr;
        }
        foreach ($allowedIps as $allow) {
            if (stripos($ip, $allow) !== false) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            throw new BadRequestException(
                new Forbidden()
            );
        }
    }
}
