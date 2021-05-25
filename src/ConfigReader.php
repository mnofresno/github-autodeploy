<?php

namespace GitAutoDeploy;

class ConfigReader {
    const IPS_ALLOWLIST = 'IPsAllowList';
    const SSH_KEYS_PATH = 'SSHKeysPath';
    const REPOS_BASE_PATH = 'ReposBasePath';
    const CUSTOM_UPDATE_COMMANDS = 'CustomCommands';

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
        return array_key_exists($key, $this->config)
            ? $this->config[$key]
            : null;
    }
}
