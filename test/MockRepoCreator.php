<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\CustomCommands;
use Symfony\Component\Yaml\Yaml;

class MockRepoCreator {
    public const BASE_REPO_DIR = "/tmp";

    private $testRepoPath;
    public $testRepoName;

    public function spinUp(): void {
        $this->testRepoPath = self::BASE_REPO_DIR . DIRECTORY_SEPARATOR . ($this->testRepoName = uniqid('test-repo-name'));
        mkdir($this->testRepoPath, 0777, true);
        touch($this->testRepoPath . '/test-file-in-repo');
    }

    public function spinDown(): void {
        shell_exec("rm -rf {$this->testRepoPath}");
    }

    public function withConfigJson(array $customRepoFileConfigContents): void {
        file_put_contents(
            $this->testRepoPath . DIRECTORY_SEPARATOR . CustomCommands::CUSTOM_CONFIG_FILE_NAME . '.json',
            json_encode($customRepoFileConfigContents, JSON_PRETTY_PRINT)
        );
    }

    public function withConfigYaml(array $customRepoFileConfigContents, string $extension = 'yaml'): void {
        file_put_contents(
            $p = $this->testRepoPath . DIRECTORY_SEPARATOR . CustomCommands::CUSTOM_CONFIG_FILE_NAME . ".$extension",
            Yaml::dump($customRepoFileConfigContents)
        );
    }
}
