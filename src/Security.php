<?php

namespace GitAutoDeploy;

use GitAutoDeploy\views\Forbidden;
use GitAutoDeploy\exceptions\ForbiddenException;

class Security {
    static function assert(array $allowedIps, array $headers, string $remoteAddr) {
        $allowed = false;
        if (array_key_exists("x-forwarded-for", $headers)) {
            $ips = explode(",",$headers["x-forwarded-for"]);
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
        self::throwIfAllowed($allowed);
    }

    private static function throwIfAllowed(bool $allowed) {
        if (!$allowed) {
            throw new ForbiddenException(
                new Forbidden()
            );
        }
    }
}
