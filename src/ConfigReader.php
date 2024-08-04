<?php

namespace Mariano\GitAutoDeploy;

class ConfigReader {
    public const IPS_ALLOWLIST = 'IPsAllowList';
    public const SSH_KEYS_PATH = 'SSHKeysPath';
    public const REPOS_BASE_PATH = 'ReposBasePath';
    public const CUSTOM_UPDATE_COMMANDS = 'custom_commands';
    public const POST_FETCH_COMMANDS = 'post_fetch_commands';
    public const PRE_FETCH_COMMANDS = 'pre_fetch_commands';
    public const DEFAULT_COMMANDS = '_default_';
    public const LOG_REQUEST_BODY = 'log_request_body';
    public const EXPOSE_RAW_LOG = 'expose_raw_log';
    public const SECRETS = 'secrets';
    public const REPOS_TEMPLATE_URI = 'repos_template_uri';
    public const REPO_KEY_TEMPLATE_PLACEHOLDER = '{$repo_key}';
    public const WHITELISTED_STRINGS_KEY = 'whitelisted_command_strings';

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
