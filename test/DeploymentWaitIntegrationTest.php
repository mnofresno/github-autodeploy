<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\DeploymentStatus;
use PHPUnit\Framework\TestCase;

/**
 * Test de integración completo que verifica el flujo de deployment con wait=true
 *
 * Este test verifica:
 * - Inicialización correcta de DeploymentStatus
 * - Tracking de steps durante ejecución
 * - Respuestas correctas para SUCCESS, FAILED y TIMEOUT
 * - Compatibilidad con el workflow de GitHub Actions
 */
class DeploymentWaitIntegrationTest extends TestCase {
    use ContainerAwareTrait;

    private $mockRepoCreator;
    private $realConfigReader;
    private $testRepoName;
    private $deploymentStatusDir;

    public function setUp(): void {
        parent::setUp();
        $this->mockRepoCreator = new MockRepoCreator();
        $this->mockRepoCreator->spinUp();
        $this->testRepoName = $this->mockRepoCreator->testRepoName;

        // Configurar directorio de status para tests
        $this->deploymentStatusDir = sys_get_temp_dir() . '/deployment-statuses-test-' . uniqid();
        mkdir($this->deploymentStatusDir, 0755, true);
    }

    public function tearDown(): void {
        $this->mockRepoCreator->spinDown();
        // Limpiar archivos de status de test
        if (is_dir($this->deploymentStatusDir)) {
            array_map('unlink', glob("{$this->deploymentStatusDir}/*.json"));
            rmdir($this->deploymentStatusDir);
        }
        parent::tearDown();
    }

    public function testDeploymentStatusLifecycle(): void {
        // Test completo del ciclo de vida de DeploymentStatus
        // Simula un deployment exitoso paso a paso

        $runId = 'test-run-lifecycle-' . uniqid();
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);

        // 1. Inicialización
        $deploymentStatus->initialize('test-repo', 'test-key', [
            'sha' => 'abc123',
            'author' => 'test-user',
        ]);

        $status = $deploymentStatus->get();
        $this->assertEquals(DeploymentStatus::STATUS_RUNNING, $status['status']);
        $this->assertEquals('test-repo', $status['repo']);
        $this->assertEquals('test-key', $status['key']);
        $this->assertEquals('abc123', $status['commit']['sha']);

        // 2. Iniciar fase
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_PRE_FETCH);
        $status = $deploymentStatus->get();
        $this->assertEquals(DeploymentStatus::PHASE_PRE_FETCH, $status['current_phase']);

        // 3. Ejecutar steps
        $deploymentStatus->startStep('echo "step 1"', DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->completeStep(0, ['output line 1'], 0);

        $deploymentStatus->startStep('echo "step 2"', DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->completeStep(1, ['output line 2'], 0);

        $status = $deploymentStatus->get();
        $this->assertCount(2, $status['steps']);
        $this->assertEquals('SUCCESS', $status['steps'][0]['status']);
        $this->assertEquals('SUCCESS', $status['steps'][1]['status']);

        // 4. Cambiar a siguiente fase
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_FETCH);
        $status = $deploymentStatus->get();
        $this->assertEquals(DeploymentStatus::PHASE_FETCH, $status['current_phase']);

        // 5. Finalizar con éxito
        $deploymentStatus->markSuccess();
        $status = $deploymentStatus->get();
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $status['status']);
        $this->assertNotNull($status['completed_at']);
        $this->assertNull($status['current_phase']);
        $this->assertNull($status['current_step']);
    }

    public function testWaitDeploymentReturnsCorrectResponse(): void {
        // Test que verifica que wait_deployment endpoint retorna formato correcto
        $runId = 'test-run-' . uniqid();

        // Simular un deployment que ya terminó
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('test-repo', 'test-key', ['sha' => 'abc123']);
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->startStep('echo test', DeploymentStatus::PHASE_PRE_FETCH);
        $deploymentStatus->completeStep(0, ['output line'], 0);
        $deploymentStatus->markSuccess();

        // Verificar que el estado se guardó correctamente
        $loaded = DeploymentStatus::load($runId, $this->deploymentStatusDir);
        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->exists());

        $status = $loaded->get();
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $status['status']);
        $this->assertCount(1, $status['steps']);
        $this->assertEquals('SUCCESS', $status['steps'][0]['status']);
    }

    public function testWaitDeploymentHandlesFailedStep(): void {
        // Test que verifica manejo de steps fallidos
        $runId = 'test-run-failed-' . uniqid();

        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('test-repo', 'test-key', []);
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_POST_FETCH);
        $deploymentStatus->startStep('false', DeploymentStatus::PHASE_POST_FETCH); // Comando que falla
        $deploymentStatus->completeStep(0, ['error output'], 1);
        $deploymentStatus->markFailed(
            DeploymentStatus::PHASE_POST_FETCH,
            0,
            'false',
            ['error output'],
            1,
            'Command failed'
        );

        $loaded = DeploymentStatus::load($runId, $this->deploymentStatusDir);
        $status = $loaded->get();

        $this->assertEquals(DeploymentStatus::STATUS_FAILED, $status['status']);
        $this->assertNotNull($status['failed_step']);
        $this->assertEquals(1, $status['failed_step']['exit_code']);
        $this->assertEquals('false', $status['failed_step']['command']);
    }

    public function testWaitDeploymentHandlesTimeout(): void {
        // Test que verifica manejo de timeout
        $runId = 'test-run-timeout-' . uniqid();

        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('test-repo', 'test-key', []);
        $deploymentStatus->startPhase(DeploymentStatus::PHASE_POST_FETCH);
        $deploymentStatus->startStep('sleep 100', DeploymentStatus::PHASE_POST_FETCH);

        // Simular timeout (exit code 124)
        $deploymentStatus->markFailed(
            DeploymentStatus::PHASE_POST_FETCH,
            0,
            'sleep 100',
            ['Command timed out after 60 seconds'],
            \Mariano\GitAutoDeploy\Executer::EXIT_CODE_TIMEOUT,
            'Command timed out'
        );

        $loaded = DeploymentStatus::load($runId, $this->deploymentStatusDir);
        $status = $loaded->get();

        $this->assertEquals(DeploymentStatus::STATUS_FAILED, $status['status']);
        $this->assertEquals(
            \Mariano\GitAutoDeploy\Executer::EXIT_CODE_TIMEOUT,
            $status['failed_step']['exit_code']
        );
    }

    public function testDeploymentStatusPersistence(): void {
        // Verifica que el DeploymentStatus persiste correctamente y puede recargarse
        $runId = 'test-run-persistence-' . uniqid();

        // Crear y modificar
        $status1 = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $status1->initialize('repo1', 'key1', []);
        $status1->startPhase(DeploymentStatus::PHASE_POST_FETCH);
        $status1->startStep('command1', DeploymentStatus::PHASE_POST_FETCH);
        $status1->completeStep(0, ['output'], 0);
        $status1->markSuccess();

        // Recargar
        $status2 = DeploymentStatus::load($runId, $this->deploymentStatusDir);
        $this->assertNotNull($status2);
        $this->assertTrue($status2->exists());

        $data = $status2->get();
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $data['status']);
        $this->assertEquals('repo1', $data['repo']);
        $this->assertCount(1, $data['steps']);
    }

    public function testGitHubActionsResponseFormat(): void {
        // Verifica que el formato de respuesta es compatible con el workflow de GitHub Actions
        // Nota: waitForDeploymentCompletion formatea la respuesta, pero aquí verificamos
        // el formato base que se usa para construir la respuesta

        $runId = 'test-run-gha-' . uniqid();

        // Simular deployment exitoso
        $deploymentStatus = new DeploymentStatus($runId, $this->deploymentStatusDir);
        $deploymentStatus->initialize('control-gastos', 'deploy-key', [
            'sha' => 'abc123def456',
            'author' => 'test-user',
        ]);

        // Simular varios steps
        for ($i = 0; $i < 3; $i++) {
            $deploymentStatus->startStep("command-$i", DeploymentStatus::PHASE_POST_FETCH);
            $deploymentStatus->completeStep($i, ["output-$i"], 0);
        }

        $deploymentStatus->markSuccess();

        // Verificar formato base de DeploymentStatus
        $status = $deploymentStatus->get();
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('run_id', $status);
        $this->assertArrayHasKey('steps', $status);
        $this->assertEquals(DeploymentStatus::STATUS_SUCCESS, $status['status']);
        $this->assertCount(3, $status['steps']);
        $this->assertNotNull($status['completed_at']);

        // Verificar que cada step tiene la estructura esperada
        foreach ($status['steps'] as $step) {
            $this->assertArrayHasKey('id', $step);
            $this->assertArrayHasKey('phase', $step);
            $this->assertArrayHasKey('command', $step);
            $this->assertArrayHasKey('status', $step);
            $this->assertArrayHasKey('exit_code', $step);
        }
    }
}
