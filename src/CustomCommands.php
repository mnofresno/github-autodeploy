<?php

namespace Mariano\GitAutoDeploy;

use Closure;
use Monolog\Logger;

class CustomCommands {
    const CURRENT_PLACEHOLDERS = [
        Request::REPO_QUERY_PARAM => 'r_',
        Request::KEY_QUERY_PARAM => 'r_',
        ConfigReader::REPOS_BASE_PATH => 'c_',
        ConfigReader::SSH_KEYS_PATH => 'c_'
    ];

    public const CUSTOM_CONFIG_FILE_NAME = '.git-auto-deploy.json';
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
        $customCommands = $this->getCommandsByRepoOrNull();
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

    private function getCommandsByRepoOrNull(): ?array {
        $commandsConfig = $this->configReader->get(ConfigReader::CUSTOM_UPDATE_COMMANDS);
        $repoName = $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        if (!$commandsConfig) {
            return null;
        }
        return $this->getCommandsByRepo($repoName, $commandsConfig)
            ?? $this->getDefaultCommands($commandsConfig);
    }

    private function getCommandsByRepo(string $repoName, array $commands): ?array {
        return array_key_exists($repoName, $commands)
            ? $commands[$repoName]
            : $this->perRepoConfigFileContentsOrNull($repoName);
    }

    private function perRepoConfigFileContentsOrNull(string $repoName):?array {
        $repoConfigFileName = implode(
            DIRECTORY_SEPARATOR,
            [
                $this->configReader->get(ConfigReader::REPOS_BASE_PATH),
                $repoName,
                self::CUSTOM_CONFIG_FILE_NAME
            ]
        );
        try {
            if (file_exists($repoConfigFileName)) {
                $contents = json_decode(file_get_contents($repoConfigFileName), true);
                return empty($contents) ? null : $contents;
            }
        } catch (\JsonException $e) {
            $this->logger->error($e->getMessage());
            return null;
        }
        return null;
    }

    private function getDefaultCommands(array $commands): ?array {
        return array_key_exists(ConfigReader::DEFAULT_COMMANDS, $commands)
            ? $commands[ConfigReader::DEFAULT_COMMANDS]
            : null;
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
