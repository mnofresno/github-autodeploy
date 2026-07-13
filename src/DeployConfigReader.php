<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\exceptions\InvalidDeployFileException;
use Monolog\Logger;
use Symfony\Component\Yaml\Exception\ParseException as YamlException;
use Symfony\Component\Yaml\Yaml;

class DeployConfigReader {
    public const CUSTOM_CONFIG_FILE_NAME = '.git-auto-deploy';

    private $configReader;
    private $logger;

    public function __construct(ConfigReader $configReader, Logger $logger) {
        $this->configReader = $configReader;
        $this->logger = $logger;
    }

    public function fetchRepoConfig(string $repoName, ?string $commitSha = null): ?object {
        if (is_string($commitSha) && $commitSha !== '') {
            $remoteContents = $this->fetchRemoteRepoConfig($repoName, $commitSha);
            if (is_array($remoteContents)) {
                return $this->generateConfig($remoteContents);
            }
        }

        $repoConfigFileName = implode(DIRECTORY_SEPARATOR, [
            $this->configReader->get(ConfigReader::REPOS_BASE_PATH),
            $repoName,
            self::CUSTOM_CONFIG_FILE_NAME,
        ]);
        try {
            if (file_exists("$repoConfigFileName.json")) {
                $contents = json_decode(file_get_contents("$repoConfigFileName.json"), true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \JsonException(json_last_error_msg());
                }
                $this->logConfigPerRepoFound($repoName, 'json');
                return $this->generateConfig($contents);
            } elseif (file_exists("$repoConfigFileName.yaml")) {
                $this->logConfigPerRepoFound($repoName, 'yaml');
                $contents = Yaml::parse(file_get_contents("$repoConfigFileName.yaml"));
                return $this->generateConfig($contents);
            } elseif (file_exists("$repoConfigFileName.yml")) {
                $this->logConfigPerRepoFound($repoName, 'yml');
                $contents = Yaml::parse(file_get_contents("$repoConfigFileName.yml"));
                return $this->generateConfig($contents);
            }
        } catch (\JsonException $e) {
            $this->logger->error($e->getMessage());
            return null;
        } catch (YamlException $e) {
            $this->logger->error($e->getMessage());
            return null;
        }
        return null;
    }

    private function fetchRemoteRepoConfig(string $repoName, string $commitSha): ?array {
        $remoteRepo = $this->resolveRemoteRepository($repoName);
        if (!$remoteRepo) {
            return null;
        }

        [$owner, $repo] = $remoteRepo;
        $baseUrl = sprintf('https://raw.githubusercontent.com/%s/%s/%s/%s', $owner, $repo, $commitSha, self::CUSTOM_CONFIG_FILE_NAME);
        foreach (['.json', '.yaml', '.yml'] as $extension) {
            $contents = $this->fetchRemoteFile($baseUrl . $extension);
            if (!is_string($contents) || trim($contents) === '') {
                continue;
            }

            try {
                if ($extension === '.json') {
                    $decoded = json_decode($contents, true);
                    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                        continue;
                    }
                    $this->logConfigPerRepoFound($repoName, 'json remote');
                    return $decoded;
                }

                $parsed = Yaml::parse($contents);
                if (is_array($parsed)) {
                    $this->logConfigPerRepoFound($repoName, substr($extension, 1) . ' remote');
                    return $parsed;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Failed to parse remote deploy config', [
                    'repo' => $repoName,
                    'commit' => $commitSha,
                    'extension' => $extension,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    private function fetchRemoteFile(string $url): ?string {
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: github-autodeploy\r\n",
            ],
            'https' => [
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: github-autodeploy\r\n",
            ],
        ]);
        $contents = @file_get_contents($url, false, $context);
        if (!is_string($contents)) {
            return null;
        }
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/^HTTP\/\S+\s+404\b/', $header) === 1) {
                    return null;
                }
            }
        }
        return $contents;
    }

    private function resolveRemoteRepository(string $repoName): ?array {
        $repoPath = implode(DIRECTORY_SEPARATOR, [
            $this->configReader->get(ConfigReader::REPOS_BASE_PATH),
            $repoName,
        ]);
        $remoteUrl = trim((string) shell_exec(sprintf('git -C %s config --get remote.origin.url', escapeshellarg($repoPath))));
        if ($remoteUrl === '') {
            return null;
        }

        if (preg_match('#^git@github\.com:([^/]+)/([^/.]+)(?:\.git)?$#', $remoteUrl, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        if (preg_match('#^https://github\.com/([^/]+)/([^/.]+)(?:\.git)?$#', $remoteUrl, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return null;
    }

    private function generateConfig(array $contents): object {
        $this->assertCommands($contents);
        return new class ($contents) {
            private $configData;

            public function __construct(array $configData) {
                $this->configData = $configData;
            }

            public function customCommands(): array {
                return $this->configData[ConfigReader::CUSTOM_UPDATE_COMMANDS] ?? [];
            }

            public function postFetchCommands(): array {
                return $this->configData[ConfigReader::POST_FETCH_COMMANDS] ?? [];
            }

            public function preFetchCommands(): array {
                return $this->configData[ConfigReader::PRE_FETCH_COMMANDS] ?? [];
            }

            public function verboseMatchers(): array {
                return $this->configData[ConfigReader::VERBOSE_MATCHER] ?? [];
            }

            public function gitTransport(): ?array {
                return $this->configData[ConfigReader::GIT_TRANSPORT] ?? null;
            }
        };
    }

    private function logConfigPerRepoFound(string $repoName, string $extension): void {
        $this->logger->info("Using config file " . self::CUSTOM_CONFIG_FILE_NAME . ".$extension for repo {$repoName}");
    }

    private function assertCommands(array $commandsData): void {
        foreach ($commandsData as $key => $commandsList) {
            if ($key === ConfigReader::VERBOSE_MATCHER) {
                if (!is_array($commandsList)) {
                    throw InvalidDeployFileException::build($commandsList, $this->logger);
                }

                foreach ($commandsList as $matcher) {
                    if (!is_string($matcher)) {
                        throw InvalidDeployFileException::build($matcher, $this->logger);
                    }
                }
                continue;
            }

            if (
                $key !== ConfigReader::CUSTOM_UPDATE_COMMANDS &&
                $key !== ConfigReader::POST_FETCH_COMMANDS &&
                $key !== ConfigReader::PRE_FETCH_COMMANDS &&
                $key !== ConfigReader::GIT_TRANSPORT
            ) {
                continue;
            }

            if (!is_array($commandsList)) {
                if ($key === ConfigReader::GIT_TRANSPORT) {
                    throw InvalidDeployFileException::build($commandsList, $this->logger);
                }

                throw InvalidDeployFileException::build($commandsList, $this->logger);
            }

            if ($key === ConfigReader::GIT_TRANSPORT) {
                $this->assertGitTransport($commandsList);
                continue;
            }

            foreach ($commandsList as $command) {
                if (!is_string($command)) {
                    throw InvalidDeployFileException::build($command, $this->logger);
                }
            }
        }
    }

    private function assertGitTransport(array $transportData): void {
        foreach ($transportData as $key => $value) {
            if (in_array($key, ['strategy', 'template_uri', 'uri', 'clone_uri', 'fetch_uri', 'credentials_file', 'credentials_username', 'credentials_token'], true)) {
                if (!is_string($value)) {
                    throw InvalidDeployFileException::build($value, $this->logger);
                }
                continue;
            }

            if ($key === 'credentials') {
                if (!is_array($value)) {
                    throw InvalidDeployFileException::build($value, $this->logger);
                }
                foreach ($value as $credentialKey => $credentialValue) {
                    if (!is_string($credentialValue)) {
                        throw InvalidDeployFileException::build($credentialValue, $this->logger);
                    }
                }
                continue;
            }
        }
    }
}
