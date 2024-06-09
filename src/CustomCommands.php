<?php

namespace Mariano\GitAutoDeploy;

use Closure;
use Monolog\Logger;
use Symfony\Component\Yaml\Yaml;

class CustomCommands {
    const CURRENT_PLACEHOLDERS = [
        Request::REPO_QUERY_PARAM => 'r_',
        Request::KEY_QUERY_PARAM => 'r_',
        ConfigReader::REPOS_BASE_PATH => 'c_',
        ConfigReader::SSH_KEYS_PATH => 'c_'
    ];

    public const CUSTOM_CONFIG_FILE_NAME = '.git-auto-deploy';
    private const REQUEST_CALLBACK_PREFIX = 'r_';
    private const CONFIG_CALLBACK_PREFIX = 'c_';

    private const CALLBACKS_MAP = [
        self::REQUEST_CALLBACK_PREFIX => 'getFromRequestCallback',
        self::CONFIG_CALLBACK_PREFIX => 'getFromConfigCallback'
    ];

    private $configReader;
    private $request;
    private $logger;
    private $callbacksCache = [];

    function __construct(ConfigReader $configReader, Request $request, Logger $logger) {
        $this->configReader = $configReader;
        $this->request = $request;
        $this->logger = $logger;
    }

    function get(): ?array {
        $customCommands = $this->getCommandsByRepoDefaultOrNull();
        return $customCommands
            ? array_map(function (string $command) {
                return $this->hydratePlaceHolders($command);
            }, $customCommands)
            : null;
    }

    private function hydratePlaceHolders(string $command): string {
        foreach (self::CURRENT_PLACEHOLDERS as $placeHolder => $callbackShorthand) {
            $command = str_replace(
                '$' . $placeHolder,
                $this->{self::CALLBACKS_MAP[$callbackShorthand]}()($placeHolder),
                $command
            );
        }
        return $command;
    }

    private function getCommandsByRepoDefaultOrNull(): ?array {
        $commandsConfig = $this->configReader->get(ConfigReader::CUSTOM_UPDATE_COMMANDS);
        $repoName = $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        $commandsPerRepoInGlobalConfig = $this->commandsPerRepoInGlobalConfig($repoName, $commandsConfig);
        if ($commandsPerRepoInGlobalConfig) {
            $this->logger->info("Using per-repo " . ConfigReader::CUSTOM_UPDATE_COMMANDS . " from global config file for repo {$repoName}");
            return $commandsPerRepoInGlobalConfig;
        }
        $commandsPerRepoInRepoConfig = $this->commandsPerRepoInRepoConfig($repoName);
        if ($commandsPerRepoInRepoConfig) {
            return $commandsPerRepoInRepoConfig;
        }
        $defaultCommandsInGlobalConfig = $this->defaultCustomCommandsInGlobalConfig($commandsConfig);
        if ($defaultCommandsInGlobalConfig) {
            $this->logger->info("Using default " . ConfigReader::DEFAULT_COMMANDS . " in global config file for repo {$repoName}");
            return $defaultCommandsInGlobalConfig;
        }
        $this->logger->info("Using default hardcoded commands for repo {$repoName}");
        return null;
    }

    private function commandsPerRepoInGlobalConfig(string $repoName, ?array $commands): ?array {
        return $commands[$repoName] ?? null;
    }

    private function logConfigPerRepoFound(string $repoName, string $extension): void {
        $this->logger->info("Using config file " . self::CUSTOM_CONFIG_FILE_NAME . ".$extension for repo {$repoName}");
    }

    private function commandsPerRepoInRepoConfig(string $repoName):?array {
        $repoConfigFileName = implode(
            DIRECTORY_SEPARATOR,
            [
                $this->configReader->get(ConfigReader::REPOS_BASE_PATH),
                $repoName,
                self::CUSTOM_CONFIG_FILE_NAME
            ]
        );
        try {
            if (file_exists($repoConfigFileName . ".json")) {
                $contents = json_decode(file_get_contents("$repoConfigFileName.json"), true);
                $this->logConfigPerRepoFound($repoName, 'json');
                return empty($contents)
                    ? null
                    : $contents[ConfigReader::CUSTOM_UPDATE_COMMANDS] ?? null;
            } else if (file_exists($repoConfigFileName . ".yaml")) {
                $this->logConfigPerRepoFound($repoName, 'yaml');
                $contents = Yaml::parse(file_get_contents("$repoConfigFileName.yaml"));
                return empty($contents)
                    ? null
                    : $contents[ConfigReader::CUSTOM_UPDATE_COMMANDS] ?? null;
            } else if (file_exists($repoConfigFileName . ".yml")) {
                $this->logConfigPerRepoFound($repoName, 'yml');
                $contents = Yaml::parse(file_get_contents("$repoConfigFileName.yml"));
                return empty($contents)
                    ? null
                    : $contents[ConfigReader::CUSTOM_UPDATE_COMMANDS] ?? null;
            }
        } catch (\JsonException $e) {
            $this->logger->error($e->getMessage());
            return null;
        }
        return null;
    }

    private function defaultCustomCommandsInGlobalConfig(?array $commands): ?array {
        return $commands[ConfigReader::DEFAULT_COMMANDS] ?? null;
    }

    private function getFromRequestCallback(): Closure {
        return function(string $key) {
            if (!isset($this->callbacksCache[self::CONFIG_CALLBACK_PREFIX . $key])) {
                $this->callbacksCache[self::CONFIG_CALLBACK_PREFIX. $key] = $this->request->getQueryParam($key);
            }
            return $this->callbacksCache[self::CONFIG_CALLBACK_PREFIX. $key];
        };
    }

    private function getFromConfigCallback(): Closure {
        return function(string $key) {
            if (!isset($this->callbacksCache[self::CONFIG_CALLBACK_PREFIX . $key])) {
                $this->callbacksCache[self::CONFIG_CALLBACK_PREFIX. $key] = $this->configReader->get($key);
            }
            return $this->callbacksCache[self::CONFIG_CALLBACK_PREFIX. $key];
        };
    }
}
