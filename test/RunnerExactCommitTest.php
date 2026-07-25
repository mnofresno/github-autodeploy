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

class RunnerExactCommitTest extends TestCase {
    public function testShaDeploymentResetsAndVerifiesTheRequestedCommit(): void {
        $sha = '0123456789abcdef0123456789abcdef01234567';
        $commands = $this->buildCommandsFor($sha);

        $this->assertTrue($this->containsCommand($commands, 'reset --hard', $sha));
        $this->assertTrue($this->containsCommand($commands, 'actual_commit=', $sha));
        $this->assertFalse($this->containsCommand($commands, 'reset --hard "origin/main"'));
    }

    public function testLegacyDeploymentWithoutShaStillTracksOriginMain(): void {
        $commands = $this->buildCommandsFor('unknown');

        $this->assertTrue($this->containsCommand($commands, 'reset --hard "origin/main"'));
        $this->assertFalse($this->containsCommand($commands, 'actual_commit='));
    }

    private function buildCommandsFor(string $sha): array {
        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQueryParam', 'getQueryParamsAll'])
            ->getMock();
        $request->method('getQueryParam')->willReturnMap([
            [Request::REPO_QUERY_PARAM, 'test-repository'],
            [Request::KEY_QUERY_PARAM, 'test-deploy-key'],
            [Request::CLONE_PATH_QUERY_PARAM, ''],
        ]);
        $request->method('getQueryParamsAll')->willReturn([]);

        $response = $this->getMockBuilder(Response::class)
            ->disableOriginalConstructor()
            ->getMock();

        $configReader = $this->getMockBuilder(ConfigReader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'resolveRepoTransportConfig'])
            ->getMock();
        $configReader->method('get')->willReturnMap([
            [ConfigReader::REPOS_BASE_PATH, '/tmp'],
            [ConfigReader::SSH_KEYS_PATH, '/tmp/keys'],
        ]);
        $configReader->method('resolveRepoTransportConfig')->willReturn(null);

        $deployConfigReader = $this->getMockBuilder(DeployConfigReader::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['fetchRepoConfig'])
            ->getMock();
        $deployConfigReader->method('fetchRepoConfig')->willReturn(null);

        $runner = new Runner(
            $request,
            $response,
            $configReader,
            $this->createMock(Logger::class),
            $this->createMock(IPAllowListManager::class),
            $this->createMock(CustomCommands::class),
            $deployConfigReader,
            $this->createMock(Executer::class)
        );

        $reflection = new ReflectionClass($runner);
        $commitProperty = $reflection->getProperty('deployCommitSha');
        $commitProperty->setAccessible(true);
        $commitProperty->setValue($runner, $sha);

        $method = $reflection->getMethod('builtInCommands');
        $method->setAccessible(true);

        return $method->invoke($runner);
    }

    private function containsCommand(array $commands, string ...$needles): bool {
        foreach ($commands as $command) {
            $matches = true;
            foreach ($needles as $needle) {
                if (!str_contains($command, $needle)) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                return true;
            }
        }

        return false;
    }
}
