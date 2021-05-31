<?php

namespace GitAutoDeploy;

use Closure;

class CustomCommands {
    const CURRENT_PLACEHOLDERS = [
        Request::REPO_QUERY_PARAM => 'r_',
        Request::KEY_QUERY_PARAM => 'r_',
        ConfigReader::REPOS_BASE_PATH => 'c_',
        ConfigReader::SSH_KEYS_PATH => 'c_'
    ];

    const CALLBACKS_MAP = [
        'r_' => 'getFromRequestCallback',
        'c_' => 'getFromConfigCallback'
    ];

    private $configReader;
    private $request;

    function __construct(ConfigReader $configReader, Request $request) {
        $this->configReader = $configReader;
        $this->request = $request;
    }

    function get() {
        $customCommands = $this->getCommandsByRepoOrDefault();
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

    private function getCommandsByRepoOrDefault() {
        $commandsConfig = $this->configReader->getKey(ConfigReader::CUSTOM_UPDATE_COMMANDS);
        $repoName = $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        if (!$commandsConfig) {
            return null;
        }
        return $this->getCommandsByRepo($repoName, $commandsConfig)
            ?? $this->getDefaultCommands($commandsConfig);

    }

    private function getCommandsByRepo(string $repoName, array $commands) {
        return array_key_exists($repoName, $commands)
            ? $commands[$repoName]
            : null;
    }

    private function getDefaultCommands(array $commands) {
        return array_key_exists(ConfigReader::DEFAULT_COMMANDS, $commands)
            ? $commands[ConfigReader::DEFAULT_COMMANDS]
            : $commands;
    }

    private function getFromRequestCallback(): Closure {
        return function(string $k) {
            return $this->request->getQueryParam($k);
        };
    }

    private function getFromConfigCallback(): Closure {
        return function(string $k) {
            return $this->configReader->getKey($k);
        };
    }
}
