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
    public const GIT_TRANSPORT = 'git_transport';
    public const REPO_KEY_TEMPLATE_PLACEHOLDER = '{$repo_key}';
    public const WHITELISTED_STRINGS_KEY = 'whitelisted_command_strings';
    public const ENABLE_CLONE = 'enable_clone';
    public const COMMAND_TIMEOUT = 'command_timeout';
    public const DEPLOYMENT_LOCK_TIMEOUT = 'deployment_lock_timeout';

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
        if (array_key_exists($key, $this->config)) {
            return $this->config[$key];
        }

        // Repository creation remains request-explicit. Legacy installations
        // predate this global switch, so an omitted value permits authenticated
        // bootstrap while an explicit false value is still authoritative.
        if ($key === self::ENABLE_CLONE) {
            return true;
        }

        return null;
    }

    public function resolveRepoTemplateUri(string $repoKey, string $clonePath = ''): ?string {
        $template = $this->resolveRepoTransportConfig($repoKey, $clonePath);
        if (!$template) {
            return null;
        }

        return $template['template_uri'] ?? null;
    }

    public function resolveRepoTransportConfig(string $repoKey, string $clonePath = ''): ?array {
        $template = $this->selectRepoTransportSource($repoKey, $clonePath);
        if (!is_string($template) || $template === '') {
            if (!is_array($template)) {
                return null;
            }
            return $this->normalizeRepoTransportConfig($template, $repoKey);
        }

        return $this->normalizeRepoTransportConfig([
            'template_uri' => $template,
        ], $repoKey);
    }

    public function normalizeRepoTransportConfig(array $transportConfig, string $repoKey): ?array {
        $template = $transportConfig['template_uri']
            ?? $transportConfig['uri']
            ?? $transportConfig['clone_uri']
            ?? $transportConfig['fetch_uri']
            ?? null;
        if (!is_string($template) || $template === '') {
            return null;
        }

        $strategy = $transportConfig['strategy']
            ?? $transportConfig['transport']
            ?? $transportConfig['protocol']
            ?? null;
        if (!is_string($strategy) || $strategy === '') {
            $strategy = preg_match('/^https?:\/\//i', $template) === 1 ? 'https' : 'ssh';
        }

        $normalized = [
            'strategy' => strtolower($strategy),
            'template_uri' => str_replace(
                self::REPO_KEY_TEMPLATE_PLACEHOLDER,
                $repoKey,
                $template
            ),
        ];

        foreach (['credentials_file', 'credentials_username', 'credentials_token', 'ssh_key'] as $key) {
            if (isset($transportConfig[$key]) && is_string($transportConfig[$key]) && $transportConfig[$key] !== '') {
                $normalized[$key] = $transportConfig[$key];
            }
        }

        if (isset($transportConfig['credentials']) && is_array($transportConfig['credentials'])) {
            $normalized['credentials'] = array_filter([
                'username' => $transportConfig['credentials']['username'] ?? $transportConfig['credentials']['user'] ?? null,
                'token' => $transportConfig['credentials']['token'] ?? $transportConfig['credentials']['password'] ?? null,
            ], static function ($value): bool {
                return is_string($value) && $value !== '';
            });
        }

        return $normalized;
    }

    private function selectRepoTransportSource(string $repoKey, string $clonePath = '') {
        if ($clonePath !== '') {
            // The deploy client passes an owner-qualified hint as `owner,repo`.
            // It contains no URL, credentials or shell syntax and is checked
            // against the requested repository before becoming an SSH remote.
            if (preg_match('/^([A-Za-z0-9_.-]+),([A-Za-z0-9_.-]+)$/', $clonePath, $matches) === 1) {
                if ($matches[2] !== $repoKey) {
                    return null;
                }
                return sprintf('git@github.com:%s/%s.git', $matches[1], $matches[2]);
            }

            // Keep the legacy explicit clone-path behavior for existing callers.
            return $clonePath;
        }

        $templateUris = $this->get(self::REPOS_TEMPLATE_URIS);
        if (is_array($templateUris)) {
            $repoSpecificTemplate = $templateUris[$repoKey] ?? $templateUris['default'] ?? $templateUris['_default_'] ?? null;
            if ((is_string($repoSpecificTemplate) || is_array($repoSpecificTemplate)) && $repoSpecificTemplate !== '') {
                return $repoSpecificTemplate;
            }
        } elseif ((is_string($templateUris) || is_array($templateUris)) && $templateUris !== '') {
            return $templateUris;
        }

        $legacyTemplate = $this->get(self::REPOS_TEMPLATE_URI);
        return is_string($legacyTemplate) && $legacyTemplate !== ''
            ? $legacyTemplate
            : null;
    }
}
