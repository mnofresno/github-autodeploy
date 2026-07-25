<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\CustomCommands;
use Mariano\GitAutoDeploy\DeployConfigReader;
use Mariano\GitAutoDeploy\Executer;
use Mariano\GitAutoDeploy\IPAllowListManager;
use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Runner;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class RunnerExactCommitFetchTest extends TestCase {
    public function testExactCommitIsFetchedIntoNamedDeployRef(): void {
        $sha = str_repeat('a', 40);
        $repoName = 'test-app';
        $repoPath = '/tmp/' . $repoName;

        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueryParam', 'getQueryParamsAll'])
            ->getMock();
        $request->method('getQueryParam')->willReturnMap([
            [Request::REPO_QUERY_PARAM, $repoName],
            [Request::KEY_QUERY_PARAM, 'deploy-key'],
        ]);
        $request->method('getQueryParamsAll')->willReturn([]);

        $config = $this->getMockBuilder(ConfigReader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'resolveRepoTransportConfig'])
            ->getMock();
        $config->method('get')->willReturnMap([
            [ConfigReader::REPOS_BASE_PATH, '/tmp'],
            [ConfigReader::SSH_KEYS_PATH, '/keys'],
        ]);
        $config->method('resolveRepoTransportConfig')->willReturn([
            'strategy' => 'ssh',
            'template_uri' => 'git@github.com:test/test-app.git',
        ]);

        $deployConfig = $this->createMock(DeployConfigReader::class);
        $deployConfig->method('fetchRepoConfig')->willReturn(null);
        $response = $this->createMock(Response::class);

        $runner = new Runner(
            $request,
            $response,
            $config,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $this->createMock(CustomCommands::class),
            $deployConfig,
            $this->createMock(Executer::class)
        );

        $reflection = new ReflectionClass($runner);
        $property = $reflection->getProperty('deployCommitSha');
        $property->setAccessible(true);
        $property->setValue($runner, $sha);
        $method = $reflection->getMethod('builtInCommands');
        $method->setAccessible(true);
        $commands = $method->invoke($runner);

        $gitDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'git-autodeploy-' . sha1($repoPath . '|' . $sha);
        $prefix = 'GIT_SSH_COMMAND="ssh -i /keys/deploy-key" git --git-dir='
            . escapeshellarg($gitDir) . ' --work-tree=' . escapeshellarg($repoPath);
        $deployRef = 'refs/git-autodeploy/deploy-target';

        $this->assertContains($prefix . ' fetch --force origin ' . escapeshellarg($sha . ':' . $deployRef), $commands);
        $this->assertContains($prefix . ' rev-parse --verify ' . escapeshellarg($deployRef . '^{commit}'), $commands);
        $this->assertContains($prefix . ' reset --hard ' . escapeshellarg($deployRef), $commands);
    }
}
