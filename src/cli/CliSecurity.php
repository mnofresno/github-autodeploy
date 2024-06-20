<?php

namespace Mariano\GitAutoDeploy\cli;

use Mariano\GitAutoDeploy\ISecurity;

class CliSecurity implements ISecurity {
    public function setParams(...$params): ISecurity {
        return $this;
    }

    public function assert(): void {
        $sapiName = php_sapi_name();

        if ($sapiName !== 'cli') {
            throw new \InvalidArgumentException("Must be ran as a CLI tool");
        }

        $userId = \posix_geteuid();
        $userInfo = \posix_getpwuid($userId);

        if ($userInfo['name'] !== 'www-data') {
            throw new \InvalidArgumentException("Must be ran as www-data unix user");
        }
    }
}
