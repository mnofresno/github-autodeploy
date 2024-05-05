<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\Forbidden;
use Mariano\GitAutoDeploy\exceptions\ForbiddenException;

class Security {
    static function assert(string $runId, array $allowedIps, array $headers, string $remoteAddr) {
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
        self::throwIfAllowed($runId, $allowed);
    }

    private static function throwIfAllowed(string $runId, bool $allowed) {
        if (!$allowed) {
            throw new ForbiddenException(
                new Forbidden(),
                $runId
            );
        }
    }
}
