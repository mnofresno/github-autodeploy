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
        // The deploy key authenticates the request but must never be persisted in
        // a status file. Keep the parameter for backward-compatible callers.
        unset($key);
        $this->write([
            'run_id' => $this->runId,
            'repo' => $repo,
            'status' => self::STATUS_RUNNING,
            'started_at' => date('c'),
            'commit' => [
                'sha' => is_string($commit['sha'] ?? null) ? $commit['sha'] : null,
                'author' => is_string($commit['author'] ?? null) ? $commit['author'] : null,
            ],
            'current_phase' => null,
            'current_step' => null,
            'steps' => [],
            'failed_step' => null,
            'error_message' => null,
            'error_code' => null,
        ]);
    }

    public function startPhase(string $phase): void {
        $status = $this->read();
        $status['current_phase'] = $phase;
        $status['current_step'] = null;
        $this->write($status);
    }

    public function startStep(string $command, string $phase, bool $verbose = false): void {
        $status = $this->read();
        $status['current_phase'] = $phase;
        $status['current_step'] = count($status['steps']);
        $stepId = count($status['steps']);
        $status['steps'][] = [
            'id' => $stepId,
            'phase' => $phase,
            'command' => $command,
            'status' => self::STATUS_RUNNING,
            'verbose' => $verbose,
            'started_at' => date('c'),
            'exit_code' => null,
            'output' => [],
        ];
        $this->write($status);
    }

    public function appendStepOutput(int $stepId, string $line): void {
        $status = $this->read();
        if (!isset($status['steps'][$stepId])) {
            return;
        }

        if (!isset($status['steps'][$stepId]['output']) || !is_array($status['steps'][$stepId]['output'])) {
            $status['steps'][$stepId]['output'] = [];
        }

        $status['steps'][$stepId]['output'][] = $line;
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
        $failedStep = $status['steps'][$stepId] ?? [];
        $failedStep['phase'] = $phase;
        $failedStep['step_id'] = $stepId;
        $failedStep['command'] = $command;
        $failedStep['exit_code'] = $exitCode;
        $failedStep['output'] = $output;
        $status['failed_step'] = $failedStep;
        if ($errorMessage) {
            $status['error_message'] = $errorMessage;
        }
        $status['error_code'] = self::classifyError($errorMessage ?? '', $phase, $exitCode);
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

    /** Internal representation used by Runner and local tests. */
    public function get(): array {
        return $this->read();
    }

    /**
     * Connector-safe representation. Commands, command output, deploy keys,
     * paths and private error text are intentionally omitted.
     */
    public function getPublic(): array {
        $status = $this->read();
        if (empty($status)) {
            return [];
        }

        $steps = array_map(static function (array $step): array {
            return array_filter([
                'id' => $step['id'] ?? null,
                'phase' => $step['phase'] ?? null,
                'status' => $step['status'] ?? null,
                'verbose' => $step['verbose'] ?? false,
                'started_at' => $step['started_at'] ?? null,
                'completed_at' => $step['completed_at'] ?? null,
                'exit_code' => $step['exit_code'] ?? null,
            ], static function ($value): bool {
                return $value !== null;
            });
        }, is_array($status['steps'] ?? null) ? $status['steps'] : []);

        $failed = is_array($status['failed_step'] ?? null) ? $status['failed_step'] : null;
        $failedStep = $failed ? array_filter([
            'phase' => $failed['phase'] ?? null,
            'step_id' => $failed['step_id'] ?? ($failed['id'] ?? null),
            'exit_code' => $failed['exit_code'] ?? null,
        ], static function ($value): bool {
            return $value !== null;
        }) : null;

        return array_filter([
            'run_id' => $status['run_id'] ?? $this->runId,
            'repo' => $status['repo'] ?? null,
            'commit_sha' => $status['commit']['sha'] ?? null,
            'status' => $status['status'] ?? null,
            'started_at' => $status['started_at'] ?? null,
            'completed_at' => $status['completed_at'] ?? null,
            'failed_at' => $status['failed_at'] ?? null,
            'current_phase' => $status['current_phase'] ?? null,
            'current_step' => $status['current_step'] ?? null,
            'steps' => $steps,
            'failed_step' => $failedStep,
            'error_code' => $status['error_code'] ?? null,
        ], static function ($value): bool {
            return $value !== null;
        });
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

    private static function classifyError(string $message, string $phase, int $exitCode): string {
        $normalized = strtolower($message);
        if (strpos($normalized, 'repo') !== false && (strpos($normalized, 'not exist') !== false || strpos($normalized, 'not found') !== false)) {
            return 'repository_not_found';
        }
        if (strpos($normalized, 'permission denied') !== false || strpos($normalized, 'not writable') !== false) {
            return 'permission_denied';
        }
        if (strpos($normalized, 'unauthorized') !== false || strpos($normalized, 'invalid key') !== false) {
            return 'authentication_failed';
        }
        if (strpos($normalized, 'invalid deploy') !== false || strpos($normalized, 'yaml') !== false) {
            return 'invalid_deploy_config';
        }
        if ($phase === self::PHASE_FETCH || in_array($exitCode, [74, 128], true)) {
            return 'checkout_failed';
        }
        if ($exitCode === Executer::EXIT_CODE_TIMEOUT) {
            return 'command_timeout';
        }
        if ($phase === self::PHASE_POST_FETCH) {
            return 'post_deploy_check_failed';
        }
        return 'unclassified_server_failure';
    }

    private function read(): array {
        if (!file_exists($this->statusFile)) {
            return [];
        }
        $content = file_get_contents($this->statusFile);
        $decoded = json_decode($content, true);
        if ($decoded === null || (is_array($decoded) && empty($decoded))) {
            return [];
        }
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
