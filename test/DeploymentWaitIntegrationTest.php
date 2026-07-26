<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\DeploymentStatus;
use Mariano\GitAutoDeploy\Executer;
use PHPUnit\Framework\TestCase;

/**
 * Integration coverage for the connector-safe deployment status lifecycle.
 */
class DeploymentWaitIntegrationTest extends TestCase {
    use ContainerAwareTrait;

    private $mockRepoCreator;
    private $testRepoName;
    private $deploymentStatusDir;

    public function setUp(): void {
        parent::setUp();
        $this->mockRepoCreator = new MockRepoCreator();
        $this->mockRepoCreator->spinUp();
        $this->testRepoName = $this->mockRepoCreator->testRepoName;
        $this->deploymentStatusDir = sys_get_temp_dir() . '/deployment-statuses-test-' . uniqid();
        mkdir($this->deploymentStatusDir, 0755, true);
    }

    public function tearDown(): void {
        $this->mockRepoCreator->spinDown();
        if (is_dir($this->deploymentStatusDir)) {
            array_map('unlink', glob("{$this->deploymentStatusDir}/*.json"));
            rmdir($this->deploymentStatusDir);
        }
        parent::tearDown();
    }

    public function testDeploymentStatusLifecycle(): void {
        $runId = 'test-run-lifecycle-' . uniqid();
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);

        $deploymentStatus->initialize('test-repo', 'test-key', [
            'sha' => 'abc123',
            'author' => 'test-user',
        ]);

        $status = $deploymentStatus->getPublic();
        $this->assertEquals(DeploymentStatus::STATUS_RUNNING, $status['status']);
        $this->assertEquals('test-repo', $status['repo']);
        $this->assertEquals('abc123', $status['commit_sha']);
        $this->assertArrayNotHasKey('key', $status);

        $statusFile = $this->deploymentStatusDir . DIRECTORY_SEPARATOR . $runId . '.json';
        $persisted = json_decode(file_get_contents($statusFile), true);
        $this->assertArrayNotHasKey('key', $persisted);

        $deploymentStatus->startPhase(DeploymentStatus::PHASE_PRE_FETCH);
        $status = $deploymentStatus->getPublic();
        $this->assertEquals(DeploymentStatus::PHASE_PRE_FETCH, $status['current_phase']);

        $deploymentStatus->startStep('echo "step 1"', DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->completeStep(0, ['output line 1'], 0);
        $deploymentStatus->startStep('echo "step 2"', DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->completeStep(1, ['output line 2'], 0);

        $status = $deploymentStatus->getPublic();
        $this->assertCount(2, $status['steps']);
        $this->assertEquals('SUCCESS', $status['steps'][0]['status']);
        $this->assertEquals('SUCCESS', $status['steps'][1]['status']);
        $this->assertArrayNotHasKey('command', $status['steps'][0]);
        $this->assertArrayNotHasKey('output', $status['steps'][0]);

        $deploymentStatus->startPhase(DeploymentStatus::PHASE_FETCH);
        $status = $deploymentStatus->getPublic();
        $this->assertEquals(DeploymentStatus::PHASE_FETCH, $status['current_phase']);

        $deploymentStatus->markSuccess();
        $status = $deploymentStatus->getPublic();
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $status['status']);
        $this->assertNotNull($status['completed_at']);
        $this->assertArrayNotHasKey('current_phase', $status);
        $this->assertArrayNotHasKey('current_step', $status);
    }

    public function testWaitDeploymentReturnsCorrectResponse(): void {
        $runId = 'test-run-' . uniqid();
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('test-repo', 'test-key', ['sha' => 'abc123']);
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->startStep('echo test', DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->completeStep(0, ['output line'], 0);
        $deploymentStatus->markSuccess();

        $loaded = DeploymentStatus::load($runId, $this->deploymentStatusDir);
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->exists());

        $status = $loaded->getPublic();
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $status['status']);
        $this->assertCount(1, $status['steps']);
        $this->assertEquals('SUCCESS', $status['steps'][0]['status']);
    }

    public function testWaitDeploymentHandlesFailedStepWithoutPrivateOutput(): void {
        $runId = 'test-run-failed-' . uniqid();
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('test-repo', 'test-key', []);
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_POST_FETCH);
        $deploymentStatus->startStep('false --secret=value', DeploymentStatus::PHASE_POST_FETCH);
        $deploymentStatus->completeStep(0, ['private error output'], 1);
        $deploymentStatus->markFailed(
            DeploymentStatus::PHASE_POST_FETCH,
            0,
            'false --secret=value',
            ['private error output'],
            1,
            'Command failed'
        );

        $status = DeploymentStatus::load($runId, $this->deploymentStatusDir)->getPublic();
        $this->assertEquals(DeploymentStatus::STATUS_FAILED, $status['status']);
        $this->assertEquals(1, $status['failed_step']['exit_code']);
        $this->assertEquals('post_deploy_check_failed', $status['error_code']);
        $this->assertArrayNotHasKey('command', $status['failed_step']);
        $this->assertArrayNotHasKey('output', $status['failed_step']);
        $this->assertArrayNotHasKey('error_message', $status);
    }

    public function testWaitDeploymentHandlesTimeout(): void {
        $runId = 'test-run-timeout-' . uniqid();
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('test-repo', 'test-key', []);
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_POST_FETCH);
        $deploymentStatus->startStep('sleep 100', DeploymentStatus::PHASE_POST_FETCH);
        $deploymentStatus->markFailed(
            DeploymentStatus::PHASE_POST_FETCH,
            0,
            'sleep 100',
            ['Command timed out after 60 seconds'],
            Executer::EXIT_CODE_TIMEOUT,
            'Command timed out'
        );

        $status = DeploymentStatus::load($runId, $this->deploymentStatusDir)->getPublic();
        $this->assertEquals(DeploymentStatus::STATUS_FAILED, $status['status']);
        $this->assertEquals(Executer::EXIT_CODE_TIMEOUT, $status['failed_step']['exit_code']);
        $this->assertEquals('command_timeout', $status['error_code']);
    }

    public function testDeploymentStatusPersistence(): void {
        $runId = 'test-run-persistence-' . uniqid();
        $status1 = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $status1->initialize('repo1', 'key1', []);
        $status1->startPhase(DeploymentStatus::PHASE_POST_FETCH);
        $status1->startStep('command1', DeploymentStatus::PHASE_POST_FETCH);
        $status1->completeStep(0, ['output'], 0);
        $status1->markSuccess();

        $status2 = DeploymentStatus::load($runId, $this->deploymentStatusDir);
        $this->assertNotNull($status2);
        $this->assertTrue($status2->exists());

        $data = $status2->getPublic();
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $data['status']);
        $this->assertEquals('repo1', $data['repo']);
        $this->assertCount(1, $data['steps']);
    }

    public function testGitHubActionsResponseFormatIsBoundedAndSanitized(): void {
        $runId = 'test-run-gha-' . uniqid();
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('control-gastos', 'deploy-key', [
            'sha' => 'abc123def456',
            'author' => 'test-user',
        ]);

        for ($i = 0; $i < 3; $i++) {
            $deploymentStatus->startStep("command-$i", DeploymentStatus::PHASE_POST_FETCH);
            $deploymentStatus->completeStep($i, ["output-$i"], 0);
        }
        $deploymentStatus->markSuccess();

        $status = $deploymentStatus->getPublic();
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('run_id', $status);
        $this->assertArrayHasKey('steps', $status);
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $status['status']);
        $this->assertCount(3, $status['steps']);
        $this->assertNotNull($status['completed_at']);

        foreach ($status['steps'] as $step) {
            $this->assertArrayHasKey('id', $step);
            $this->assertArrayHasKey('phase', $step);
            $this->assertArrayHasKey('status', $step);
            $this->assertArrayHasKey('exit_code', $step);
            $this->assertArrayHasKey('verbose', $step);
            $this->assertArrayNotHasKey('command', $step);
            $this->assertArrayNotHasKey('output', $step);
        }
    }

    public function testVerboseOutputIsNotExposedByPublicStatus(): void {
        $runId = 'test-run-verbose-' . uniqid();
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('repo1', 'key1', []);
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_POST_FETCH);
        $deploymentStatus->startStep('sh ./scripts/report-docker-image-shas.sh frontend backend', DeploymentStatus::PHASE_POST_FETCH, true);
        $deploymentStatus->appendStepOutput(0, 'frontend=sha-123');
        $deploymentStatus->appendStepOutput(0, 'backend=sha-456');

        $status = $deploymentStatus->getPublic();
        $this->assertTrue($status['steps'][0]['verbose']);
        $this->assertArrayNotHasKey('command', $status['steps'][0]);
        $this->assertArrayNotHasKey('output', $status['steps'][0]);
    }
}
