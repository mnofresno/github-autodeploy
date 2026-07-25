<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\Executer;
use Mariano\GitAutoDeploy\Request;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ExecuterPermissionRepairTest extends TestCase {
    public function testPermissionFailureOnGitResetTriggersRepairWhenEnabled(): void {
        $executer = $this->buildExecuter([
            'env_REPAIR_CHECKOUT_PERMISSIONS' => 'true',
        ]);

        $result = $this->invokePrivate(
            $executer,
            'shouldRepairCheckoutPermissions',
            [
                'git --git-dir=/tmp/repo reset --hard abc123',
                [
                    'exit_code' => 1,
                    'output' => ["error: unable to unlink old 'package.json': Permission denied"],
                ],
            ]
        );

        $this->assertTrue($result);
    }

    public function testPermissionRepairDoesNotTriggerWithoutExplicitFlag(): void {
        $executer = $this->buildExecuter([]);

        $result = $this->invokePrivate(
            $executer,
            'shouldRepairCheckoutPermissions',
            [
                'git reset --hard abc123',
                [
                    'exit_code' => 1,
                    'output' => ["error: unable to unlink old 'package.json': Permission denied"],
                ],
            ]
        );

        $this->assertFalse($result);
    }

    public function testPermissionRepairDoesNotTriggerForUnrelatedCommand(): void {
        $executer = $this->buildExecuter([
            'env_REPAIR_CHECKOUT_PERMISSIONS' => 'true',
        ]);

        $result = $this->invokePrivate(
            $executer,
            'shouldRepairCheckoutPermissions',
            [
                'docker compose up -d',
                [
                    'exit_code' => 1,
                    'output' => ['permission denied'],
                ],
            ]
        );

        $this->assertFalse($result);
    }

    public function testRepairCommandUsesDockerRootHelperAndCurrentDeployIdentity(): void {
        $executer = $this->buildExecuter([
            'env_REPAIR_CHECKOUT_PERMISSIONS' => 'true',
        ]);

        $command = $this->invokePrivate($executer, 'buildCheckoutPermissionRepairCommand');

        $this->assertStringContainsString('docker run --rm', $command);
        $this->assertStringContainsString('$PWD:/workspace', $command);
        $this->assertStringContainsString('chown -R ${deploy_uid}:${deploy_gid}', $command);
        $this->assertStringContainsString('chmod -R u+rwX', $command);
    }

    private function buildExecuter(array $queryParams): Executer {
        $config = $this->createMock(ConfigReader::class);
        $config->method('get')->willReturnMap([
            [ConfigReader::WHITELISTED_STRINGS_KEY, Executer::WHITELISTED_STRINGS],
            [ConfigReader::COMMAND_TIMEOUT, 30],
        ]);

        $request = $this->createMock(Request::class);
        $request->method('getQueryParamsAll')->willReturn($queryParams);

        return new Executer($config, $request);
    }

    private function invokePrivate(object $subject, string $methodName, array $arguments = []) {
        $method = (new ReflectionClass($subject))->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($subject, $arguments);
    }
}
