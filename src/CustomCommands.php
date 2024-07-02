<?php

namespace Mariano\GitAutoDeploy;

use Monolog\Logger;

class CustomCommands {
    public const CURRENT_PLACEHOLDERS = [
        Request::REPO_QUERY_PARAM => 'getFromRequestCallback',
        Request::KEY_QUERY_PARAM => 'getFromRequestCallback',
        ConfigReader::REPOS_BASE_PATH => 'getFromConfigCallback',
        ConfigReader::SSH_KEYS_PATH => 'getFromConfigCallback',
        ConfigReader::SECRETS => 'getFromConfigSecretsCallback',
    ];

    private $configReader;
    private $request;
    private $logger;
    private $deployConfigReader;
    private $callbacksCache = [];

    public function __construct(
        ConfigReader $configReader,
        Request $request,
        Logger $logger,
        DeployConfigReader $deployConfigReader
    ) {
        $this->configReader = $configReader;
        $this->request = $request;
        $this->logger = $logger;
        $this->deployConfigReader = $deployConfigReader ?? new DeployConfigReader($configReader, $logger);
    }

    public function get(): ?array {
        $customCommands = $this->getCommandsByRepoDefaultOrNull();
        return $customCommands
            ? array_map([$this, 'hydratePlaceHolders'], $customCommands)
            : null;
    }

    private function hydratePlaceHolders(string $command): string {
        $this->logger->debug("Original command: $command");

        $pattern = '/\$\{\{\s*(?P<placeholder>[^\}]+)\s*\}\}|\$(?P<subkey>[a-zA-Z0-9_.]+)/';

        $result = preg_replace_callback($pattern, function ($matches) {
            $key = $matches['subkey'] ?? $matches['placeholder'];
            $key = trim($key);
            list($baseKey, $subKey) = explode('.', $key) + [null, null];

            if (isset(self::CURRENT_PLACEHOLDERS[$baseKey])) {
                $callbackMethod = self::CURRENT_PLACEHOLDERS[$baseKey];
                $replacement = $this->$callbackMethod($subKey ? "$baseKey.$subKey" : $baseKey);
                $replaceString = $baseKey === ConfigReader::SECRETS ? '***' : $replacement;
                $this->logger->debug("Replacing $key with $replaceString");
                return $replacement ?: $matches[0];
            }

            return $matches[0];
        }, $command);

        $this->logger->debug("Final command: $result");
        return $result;
    }

    private function getCommandsByRepoDefaultOrNull(): ?array {
        $commandsConfig = $this->configReader->get(ConfigReader::CUSTOM_UPDATE_COMMANDS);
        $repoName = $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        $commandsPerRepoInGlobalConfig = $this->commandsPerRepoInGlobalConfig($repoName, $commandsConfig);

        if ($commandsPerRepoInGlobalConfig) {
            $this->logger->info("Using per-repo commands from global config file for repo {$repoName}");
            return $commandsPerRepoInGlobalConfig;
        }

        $repoDeployFileConfig = $this->deployConfigReader->fetchRepoConfig($repoName);
        $commandsPerRepoInRepoConfig = $repoDeployFileConfig
            ? $repoDeployFileConfig->customCommands()
            : [];
        if ($commandsPerRepoInRepoConfig) {
            return $commandsPerRepoInRepoConfig;
        }

        $defaultCommandsInGlobalConfig = $this->defaultCustomCommandsInGlobalConfig($commandsConfig);
        if ($defaultCommandsInGlobalConfig) {
            $this->logger->info("Using default commands in global config file for repo {$repoName}");
            return $defaultCommandsInGlobalConfig;
        }

        $this->logger->info("Using default hardcoded commands for repo {$repoName}");
        return null;
    }

    private function commandsPerRepoInGlobalConfig(string $repoName, ?array $commands): ?array {
        return $commands[$repoName] ?? null;
    }

    private function defaultCustomCommandsInGlobalConfig(?array $commands): ?array {
        return $commands[ConfigReader::DEFAULT_COMMANDS] ?? null;
    }

    private function getFromRequestCallback($key): ?string {
        $key = explode('.', $key)[1] ?? $key;
        if (!isset($this->callbacksCache[$key])) {
            $this->callbacksCache[$key] = $this->request->getQueryParam($key);
        }
        return $this->callbacksCache[$key];
    }

    private function getFromConfigCallback($key): ?string {
        $key = explode('.', $key)[1] ?? $key;
        if (!isset($this->callbacksCache[$key])) {
            $this->callbacksCache[$key] = $this->configReader->get($key);
        }
        return $this->callbacksCache[$key];
    }

    private function getFromConfigSecretsCallback($key): ?string {
        $key = trim(explode('.', $key)[1] ?? $key);
        if (!isset($this->callbacksCache[$key])) {
            $secrets = $this->configReader->get(ConfigReader::SECRETS);
            $this->callbacksCache[$key] = $secrets[$key] ?? null;
        }
        return $this->callbacksCache[$key];
    }
}
