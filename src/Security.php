<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\errors\Forbidden;
use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
use Monolog\Logger;

class Security {
    private $logger;

    public function __construct(Logger $logger) {
        $this->logger = $logger;
    }

    function assert(array $allowedIps, array $headers, string $remoteAddr) {
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
        $this->throwIfAllowed($allowed);
    }

    private function throwIfAllowed(bool $allowed) {
        if (!$allowed) {
            throw new ForbiddenException(new Forbidden(), $this->logger);
        }
    }
}
