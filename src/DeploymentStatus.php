<?php

namespace Mariano\GitAutoDeploy;

class DeploymentStatus {
    public const STATUS_RUNNING = 'RUNNING';
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILED = 'FAILED';

    public const PHASE_PRE_FETCH = 'pre_fetch';
    public const PHASE_FETCH = 'fetch';
    public const PHASE_POST_FETCH = 'post_fetch';

    private $runId;
    private $statusDir;
    private $statusFile;

    public function __construct(string $runId, ?string $statusDir = null) {
        $this->runId = $runId;
        $this->statusDir = $statusDir ?? $this->getDefaultStatusDir();
        $this->statusFile = $this->statusDir . DIRECTORY_SEPARATOR . $this->runId . '.json';
    }

    public function initialize(string $repo, string $key, array $commit = []): void {
        $this->write([
            'run_id' => $this->runId,
            'repo' => $repo,
            'key' => $key,
            'status' => self::STATUS_RUNNING,
            'started_at' => date('c'),
            'commit' => $commit,
            'current_phase' => null,
            'current_step' => null,
            'steps' => [],
            'failed_step' => null,
            'error_message' => null,
        ]);
    }

    public function startPhase(string $phase): void {
        $status = $this->read();
        $status['current_phase'] = $phase;
        $status['current_step'] = null;
        $this->write($status);
    }

    public function startStep(string $command, string $phase): void {
        $status = $this->read();
        $status['current_phase'] = $phase;
        $status['current_step'] = count($status['steps']);
        $stepId = count($status['steps']);
        $status['steps'][] = [
            'id' => $stepId,
            'phase' => $phase,
            'command' => $command,
            'status' => self::STATUS_RUNNING,
            'started_at' => date('c'),
            'exit_code' => null,
            'output' => null,
        ];
        $this->write($status);
    }

    public function completeStep(int $stepId, array $output, int $exitCode): void {
        $status = $this->read();
        if (isset($status['steps'][$stepId])) {
            $status['steps'][$stepId]['status'] = $exitCode === 0 ? self::STATUS_SUCCESS : self::STATUS_FAILED;
            $status['steps'][$stepId]['exit_code'] = $exitCode;
            $status['steps'][$stepId]['output'] = $output;
            $status['steps'][$stepId]['completed_at'] = date('c');
            $status['current_step'] = null;
            $this->write($status);
        }
    }

    public function markFailed(string $phase, int $stepId, string $command, array $output, int $exitCode, ?string $errorMessage = null): void {
        $status = $this->read();
        $status['status'] = self::STATUS_FAILED;
        $status['failed_at'] = date('c');
        $status['failed_step'] = [
            'phase' => $phase,
            'step_id' => $stepId,
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => $output,
        ];
        if ($errorMessage) {
            $status['error_message'] = $errorMessage;
        }
        if (isset($status['steps'][$stepId])) {
            $status['steps'][$stepId]['status'] = self::STATUS_FAILED;
            $status['steps'][$stepId]['exit_code'] = $exitCode;
            $status['steps'][$stepId]['output'] = $output;
            $status['steps'][$stepId]['completed_at'] = date('c');
        }
        $this->write($status);
    }

    public function markSuccess(): void {
        $status = $this->read();
        $status['status'] = self::STATUS_SUCCESS;
        $status['completed_at'] = date('c');
        $status['current_phase'] = null;
        $status['current_step'] = null;
        $this->write($status);
    }

    public function get(): array {
        return $this->read();
    }

    public static function load(string $runId, ?string $statusDir = null): ?DeploymentStatus {
        $status = new self($runId, $statusDir);
        if (!$status->exists()) {
            return null;
        }
        return $status;
    }

    public function exists(): bool {
        return file_exists($this->statusFile);
    }

    private function read(): array {
        if (!file_exists($this->statusFile)) {
            return [];
        }
        $content = file_get_contents($this->statusFile);
        $decoded = json_decode($content, true);
        // Si json_decode falla o devuelve null, o si devolvió un array vacío,
        // retornamos array vacío (que luego se serializará como objeto cuando tenga datos)
        if ($decoded === null || (is_array($decoded) && empty($decoded))) {
            return [];
        }
        // Asegurar que siempre es un array asociativo (objeto cuando se serializa)
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    private function write(array $data): void {
        if (!is_dir($this->statusDir)) {
            mkdir($this->statusDir, 0755, true);
        }
        file_put_contents($this->statusFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function getDefaultStatusDir(): string {
        return implode(DIRECTORY_SEPARATOR, [
            __DIR__,
            '..',
            'deployment-statuses',
        ]);
    }

    public function delete(): void {
        if (file_exists($this->statusFile)) {
            unlink($this->statusFile);
        }
    }
}
