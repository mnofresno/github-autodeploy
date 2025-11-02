<?php

namespace Mariano\GitAutoDeploy;

use Ramsey\Uuid\Uuid;

class RunSearcher {
    private $logFileName;
    private $configReader;

    public function __construct(ConfigReader $configReader, ?string $logFileName = null) {
        $this->logFileName = $logFileName;
        $this->configReader = $configReader;
    }

    public function search(string $runId, ?array $fields = null): array {
        $logContents = $this->read($runId);
        $foundRows = [];
        foreach (explode("\n", $logContents) as $logRow) {
            if (strpos($logRow, $runId) !== false) {
                $parsedRow = $this->parse($logRow);
                if ($fields && !empty($fields)) {
                    $parsedRow = array_intersect_key($parsedRow, array_flip($fields));
                }
                $foundRows [] = $parsedRow;
            }
        }

        // Enriquecer con información de deployment status si está disponible
        $deploymentStatus = DeploymentStatus::load($runId);
        if ($deploymentStatus && $deploymentStatus->exists()) {
            $status = $deploymentStatus->get();
            $foundRows[] = [
                'type' => 'deployment_status',
                'date' => $status['started_at'] ?? null,
                'logLevel' => $this->mapStatusToLogLevel($status['status'] ?? ''),
                'message' => $this->formatStatusMessage($status),
                'deployment_status' => $status,
            ];
        }

        return $foundRows;
    }

    private function mapStatusToLogLevel(string $status): string {
        switch ($status) {
            case DeploymentStatus::STATUS_SUCCESS:
                return 'INFO';
            case DeploymentStatus::STATUS_FAILED:
                return 'ERROR';
            case DeploymentStatus::STATUS_RUNNING:
                return 'INFO';
            default:
                return 'INFO';
        }
    }

    private function formatStatusMessage(array $status): string {
        $statusText = $status['status'] ?? 'UNKNOWN';
        $phase = $status['current_phase'] ?? null;
        $failedStep = $status['failed_step'] ?? null;

        if ($statusText === DeploymentStatus::STATUS_FAILED && $failedStep) {
            return sprintf(
                "Deployment FAILED in phase '%s', step %d: %s (exit code: %d)",
                $failedStep['phase'] ?? 'unknown',
                $failedStep['step_id'] ?? -1,
                $failedStep['command'] ?? 'unknown',
                $failedStep['exit_code'] ?? -1
            );
        }

        if ($statusText === DeploymentStatus::STATUS_RUNNING) {
            $currentStep = $status['current_step'] ?? null;
            if ($phase && $currentStep !== null) {
                return sprintf("Deployment RUNNING in phase '%s', step %d", $phase, $currentStep);
            }
            return sprintf("Deployment RUNNING in phase '%s'", $phase ?? 'unknown');
        }

        if ($statusText === DeploymentStatus::STATUS_SUCCESS) {
            $stepsCount = count($status['steps'] ?? []);
            return sprintf("Deployment SUCCESS - Completed %d steps", $stepsCount);
        }

        return sprintf("Deployment status: %s", $statusText);
    }

    private function parse(string $logRow): array {
        $context = explode(' - ', $logRow);
        $jsonContext = count($context) === 2 ? @$context[1] : null;
        if (!$this->isJson($jsonContext)) {
            $jsonContext = null;
        }
        $date = @$context[0];
        $logLevel = null;
        $message = null;
        if (!$jsonContext) {
            $pattern = '/\[(?P<date>.+?)\]\s+(?P<loggerName>\S+)\.(?P<level>\S+):\s+(?P<message>.+?)\s+(?P<context>\{.*\})(?P<extra>.+)/';
            if (preg_match($pattern, $logRow, $matches, PREG_OFFSET_CAPTURE, 0)) {
                $matches = array_filter($matches, "is_string", ARRAY_FILTER_USE_KEY);
                $date = $matches['date'][0];
                $logLevel = $matches['level'][0];
                $message = $matches['message'][0];
                $jsonContext = $matches['context'][0];
                $extraContext = trim($matches['extra'][0]);
            }
        }
        $result = @json_decode($jsonContext, true) ?? [];
        $result['date'] = $date;
        $result['logLevel'] = $logLevel;
        $result['message'] = $message;
        $result['extra_context'] = @json_decode($extraContext, true) ?? [];
        if ($this->configReader->get(ConfigReader::EXPOSE_RAW_LOG)) {
            $result['raw_log'] = $logRow;
        }
        return $result;
    }

    private function isJson(?string $input): bool {
        json_decode($input);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function read(string $runId): string {
        if (!Uuid::isValid($runId)) {
            return '';
        }
        $fileName = escapeshellarg($this->logFileName ?? $this->fileName());
        $runId = escapeshellarg($runId);
        exec("cat {$fileName} | grep {$runId}", $searchOutput);
        return implode("\n", $searchOutput);
    }

    private function fileName(): string {
        return implode(DIRECTORY_SEPARATOR, [
            __DIR__,
            '..',
            'deploy-log.log',
        ]);
    }
}
