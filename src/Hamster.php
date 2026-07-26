<?php

namespace Mariano\GitAutoDeploy;

use Monolog\Logger;

class Hamster {
    private $runner;
    private $request;
    private $response;
    private $configReader;
    private $logger;
    private $runSearcher;

    public function __construct(
        Logger $logger,
        Runner $runner,
        Response $response,
        Request $request,
        ConfigReader $configReader,
        RunSearcher $runSearcher
    ) {
        $this->logger = $logger;
        $this->runner = $runner;
        $this->request = $request;
        $this->response = $response;
        $this->configReader = $configReader;
        $this->runSearcher = $runSearcher;
    }

    public function run() {
        $runId = $this->request->getQueryParam('previous_run_id');
        $deploymentStatusParam = $this->request->getQueryParam('deployment_status');
        $waitDeployment = $this->request->getQueryParam('wait_deployment');

        if ($waitDeployment !== '' && $runId !== '') {
            $this->handleWaitDeploymentRequest($runId);
            return;
        }

        if ($deploymentStatusParam !== '' && $runId !== '') {
            $this->handleDeploymentStatusRequest($runId);
            return;
        }

        if ($runId !== '') {
            if ($this->configReader->get(ConfigReader::EXPOSE_RAW_LOG) !== true) {
                $this->response->setStatusCode(404);
                $this->response->addToBody(json_encode([
                    'error' => 'Raw deployment logs are disabled',
                    'run_id' => $runId,
                ], JSON_PRETTY_PRINT));
                $this->response->send('application/json; charset=utf-8');
                return;
            }

            $this->response->setStatusCode(200);
            $fieldsParam = $this->request->getQueryParam('fields');
            $fields = $fieldsParam ? explode(',', $fieldsParam) : null;
            $this->logger->debug("Fields chosen for log run searcher: $fieldsParam");
            $this->response->addToBody(
                json_encode([
                    'message' => "Given run Id: $runId",
                    'results' => $this->runSearcher->search($runId, $fields),
                ], JSON_PRETTY_PRINT)
            );
            $this->response->send('application/json; charset=utf-8');
            return;
        }

        $runInBackground = false;
        $bodyData = $this->request->getBody();
        $this->logger->debug('Checking for run_in_background', ['body_data' => $bodyData]);
        if (isset($bodyData['run_in_background'])) {
            $runInBackground = $bodyData['run_in_background'] === true || $bodyData['run_in_background'] === 'true';
        } else {
            $runInBackground = $this->request->getQueryParam('run_in_background') === 'true';
        }
        $waitForCompletion = $this->request->getQueryParam('wait') === 'true';

        if ($runInBackground || $waitForCompletion) {
            ini_set('ignore_user_abort', 'On');
            $website = $this->configReader->get('website') ?? '-website-not-configured-';
            $runId = $this->response->getRunId();
            $this->response->setStatusCode(201);

            if ($waitForCompletion) {
                header('Connection: keep-alive');
                header('X-Accel-Buffering: no');
                $this->executeTrigger();
                $this->waitForDeploymentCompletion($runId);
                return;
            }

            $statusUrl = "{$website}?deployment_status=true&previous_run_id={$runId}";
            $waitUrl = "{$website}?wait_deployment=true&previous_run_id={$runId}";
            $monitoring = [
                'status_url' => $statusUrl,
                'wait_url' => $waitUrl,
                'description' => 'Use wait_url to wait for completion or status_url for sanitized status.',
            ];
            if ($this->configReader->get(ConfigReader::EXPOSE_RAW_LOG) === true) {
                $monitoring['logs_url'] = "{$website}?previous_run_id={$runId}";
            }

            $responseData = [
                'status' => 'deployment_started',
                'run_id' => $runId,
                'message' => 'Deployment started in background',
                'wait_url' => $waitUrl,
                'monitoring' => $monitoring,
            ];

            $this->response->addToBody(
                json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            );
            $this->response->send('application/json; charset=utf-8');
            $this->finishRequest();
            $this->executeTrigger();
            return;
        }

        $this->executeTrigger();
        $this->response->send();
    }

    private function waitForDeploymentCompletion(string $runId, ?int $maxWait = null, ?int $pollInterval = null): void {
        $maxWait = $maxWait ?? 2400;
        $pollInterval = $pollInterval ?? 5;
        $startTime = time();

        $this->logger->info('Waiting for deployment to complete', [
            'run_id' => $runId,
            'max_wait' => $maxWait,
            'poll_interval' => $pollInterval,
        ]);

        header('Content-Type: application/json; charset=utf-8');

        while (true) {
            $elapsed = time() - $startTime;
            if ($elapsed >= $maxWait) {
                $this->response->setStatusCode(408);
                $this->response->addToBody(json_encode([
                    'error' => 'timeout',
                    'run_id' => $runId,
                    'message' => "Deployment wait timed out ({$maxWait}s)",
                    'elapsed_seconds' => $elapsed,
                ], JSON_PRETTY_PRINT));
                $this->response->send('application/json; charset=utf-8');
                return;
            }

            $deploymentStatus = DeploymentStatus::load($runId);
            if (!$deploymentStatus || !$deploymentStatus->exists()) {
                sleep($pollInterval);
                continue;
            }

            $status = $deploymentStatus->getPublic();
            $deploymentStatusValue = $status['status'] ?? 'UNKNOWN';

            if ($deploymentStatusValue === DeploymentStatus::STATUS_SUCCESS) {
                $this->response->setStatusCode(200);
                $this->response->addToBody(json_encode([
                    'status' => 'success',
                    'run_id' => $runId,
                    'message' => 'Deployment completed successfully',
                    'elapsed_seconds' => $elapsed,
                    'summary' => [
                        'total_steps' => count($status['steps'] ?? []),
                        'started_at' => $status['started_at'] ?? null,
                        'completed_at' => $status['completed_at'] ?? null,
                    ],
                ], JSON_PRETTY_PRINT));
                $this->response->send('application/json; charset=utf-8');
                return;
            }

            if ($deploymentStatusValue === DeploymentStatus::STATUS_FAILED) {
                $failedStep = $status['failed_step'] ?? null;
                $this->response->setStatusCode(500);
                $this->response->addToBody(json_encode([
                    'status' => 'failed',
                    'run_id' => $runId,
                    'message' => 'Deployment failed',
                    'error_code' => $status['error_code'] ?? 'unclassified_server_failure',
                    'elapsed_seconds' => $elapsed,
                    'failed_step' => $failedStep ? [
                        'phase' => $failedStep['phase'] ?? null,
                        'step_id' => $failedStep['step_id'] ?? null,
                        'exit_code' => $failedStep['exit_code'] ?? null,
                        'is_timeout' => ($failedStep['exit_code'] ?? 0) === Executer::EXIT_CODE_TIMEOUT,
                    ] : null,
                ], JSON_PRETTY_PRINT));
                $this->response->send('application/json; charset=utf-8');
                return;
            }

            if ($elapsed % 30 === 0) {
                echo ' ';
                flush();
            }
            sleep($pollInterval);
        }
    }

    private function handleWaitDeploymentRequest(string $runId): void {
        $maxWaitParam = $this->request->getQueryParam('max_wait');
        $pollIntervalParam = $this->request->getQueryParam('poll_interval');
        $maxWait = $maxWaitParam !== '' ? (int) $maxWaitParam : null;
        $pollInterval = $pollIntervalParam !== '' ? (int) $pollIntervalParam : null;
        $this->waitForDeploymentCompletion($runId, $maxWait, $pollInterval);
    }

    private function handleDeploymentStatusRequest(string $runId): void {
        $deploymentStatus = DeploymentStatus::load($runId);

        if (!$deploymentStatus || !$deploymentStatus->exists()) {
            $this->response->setStatusCode(404);
            $this->response->addToBody(
                json_encode([
                    'error' => 'Deployment status not found',
                    'run_id' => $runId,
                    'message' => 'The deployment status does not exist or has expired.',
                ], JSON_PRETTY_PRINT)
            );
            $this->response->send('application/json; charset=utf-8');
            return;
        }

        $status = $deploymentStatus->getPublic();
        $this->response->setStatusCode(200);
        $jsonData = empty($status) ? new \stdClass() : $status;
        $this->response->addToBody(json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->response->send('application/json; charset=utf-8');
    }

    private function executeTrigger(): void {
        $this->runner->run(
            $this->request->getQueryParam(Request::CREATE_REPO_IF_NOT_EXISTS) === 'true'
        );
    }

    private function finishRequest(): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            $this->logger->warning('fastcgi_finish_request function not found, relying on ignore_user_abort');
            flush();
        }
    }
}
