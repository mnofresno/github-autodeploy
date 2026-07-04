<?php

namespace Mariano\GitAutoDeploy;

class ConfigReader {
    public const IPS_ALLOWLIST = 'IPsAllowList';
    public const SSH_KEYS_PATH = 'SSHKeysPath';
    public const REPOS_BASE_PATH = 'ReposBasePath';
    public const CUSTOM_UPDATE_COMMANDS = 'custom_commands';
    public const POST_FETCH_COMMANDS = 'post_fetch_commands';
    public const PRE_FETCH_COMMANDS = 'pre_fetch_commands';
    public const VERBOSE_MATCHER = 'verbose_matcher';
    public const DEFAULT_COMMANDS = '_default_';
    public const LOG_REQUEST_BODY = 'log_request_body';
    public const EXPOSE_RAW_LOG = 'expose_raw_log';
    public const SECRETS = 'secrets';
    public const REPOS_TEMPLATE_URI = 'repos_template_uri';
    public const REPOS_TEMPLATE_URIS = 'repos_template_uris';
    public const REPO_KEY_TEMPLATE_PLACEHOLDER = '{$repo_key}';
    public const WHITELISTED_STRINGS_KEY = 'whitelisted_command_strings';
    public const ENABLE_CLONE = 'enable_clone';
    public const COMMAND_TIMEOUT = 'command_timeout';

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

    public function resolveRepoTemplateUri(string $repoKey, string $clonePath = ''): ?string {
        $template = $this->selectRepoTemplateUri($repoKey, $clonePath);
        if (!is_string($template) || $template === '') {
            return null;
        }

        return str_replace(
            self::REPO_KEY_TEMPLATE_PLACEHOLDER,
            escapeshellarg($repoKey),
            $template
        );
    }

    private function selectRepoTemplateUri(string $repoKey, string $clonePath = '') {
        if ($clonePath !== '') {
            return $clonePath;
        }

        $templateUris = $this->get(self::REPOS_TEMPLATE_URIS);
        if (is_array($templateUris)) {
            $repoSpecificTemplate = $templateUris[$repoKey] ?? $templateUris['default'] ?? $templateUris['_default_'] ?? null;
            if (is_string($repoSpecificTemplate) && $repoSpecificTemplate !== '') {
                return $repoSpecificTemplate;
            }
        } elseif (is_string($templateUris) && $templateUris !== '') {
            return $templateUris;
        }

        $legacyTemplate = $this->get(self::REPOS_TEMPLATE_URI);
        return is_string($legacyTemplate) && $legacyTemplate !== ''
            ? $legacyTemplate
            : null;
    }
}
