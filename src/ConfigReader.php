<?php

namespace Mariano\GitAutoDeploy;

class ConfigReader {
    public const IPS_ALLOWLIST = 'IPsAllowList';
    public const SSH_KEYS_PATH = 'SSHKeysPath';
    public const REPOS_BASE_PATH = 'ReposBasePath';
    public const CUSTOM_UPDATE_COMMANDS = 'CustomCommands';
    public const DEFAULT_COMMANDS = '_default_';
    public const LOG_REQUEST_BODY = 'log_request_body';
    public const EXPOSE_RAW_LOG = 'expose_raw_log';
    public const SECRETS = 'secrets';
    private $config;

    public function __construct() {
        $this->config = json_decode(
            file_get_contents(
                implode(DIRECTORY_SEPARATOR, [
                    __DIR__,
                    '..',
                    'config.json',
                ])
            ),
            true
        );
    }

    public function get(string $key) {
        return array_key_exists($key, $this->config)
            ? $this->config[$key]
            : null;
    }
}
