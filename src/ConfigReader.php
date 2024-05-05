<?php

namespace Mariano\GitAutoDeploy;

class ConfigReader {
    const IPS_ALLOWLIST = 'IPsAllowList';
    const SSH_KEYS_PATH = 'SSHKeysPath';
    const REPOS_BASE_PATH = 'ReposBasePath';
    const CUSTOM_UPDATE_COMMANDS = 'CustomCommands';
    const DEFAULT_COMMANDS = '_default_';

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
// TEST COMMENT
    function get(string $key) {
        return array_key_exists($key, $this->config)
            ? $this->config[$key]
            : null;
    }
}
