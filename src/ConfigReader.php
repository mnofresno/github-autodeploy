<?php

namespace GitAutoDeploy;

class ConfigReader {
    const IPS_ALLOWLIST = 'IPsAllowList';
    const SSH_KEYS_PATH = 'SSHKeysPath';
    const REPOS_BASE_PATH = 'ReposBasePath';

    private $config;

    function __construct() {
        $this->config = json_decode(
            file_get_contents(
                implode(DIRECTORY_SEPARATOR, [
                    __DIR__,
                    '..',
                    'config.json'
                ])
            ),
            true
        );
    }

    function getKey(string $key) {
        return $this->config[$key];
    }
}
