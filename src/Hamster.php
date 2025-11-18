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
            $this->response->setStatusCode(200);
            $fieldsParam = $this->request->getQueryParam('fields');
            $fields = $fieldsParam ? explode(',', $fieldsParam) : null;
            $this->logger->debug("Fields choosen for log run searcher: $fieldsParam");
            $this->response->addToBody(
                json_encode([
                    'message' => "Given run Id: $runId",
                    'results' => $this->runSearcher->search($runId, $fields),
                ], JSON_PRETTY_PRINT)
            );
            $this->response->send('application/json; charset=utf-8');
            return;
        } else {
            // Leer run_in_background del body JSON (envío del workflow) o query params (backward compatibility)
            $runInBackground = false;
            $bodyData = $this->request->getBody();
            $this->logger->debug('Checking for run_in_background', ['body_data' => $bodyData]);
            if (isset($bodyData['run_in_background'])) {
                $runInBackground = $bodyData['run_in_background'] === true || $bodyData['run_in_background'] === 'true';
                $this->logger->debug('Found run_in_background in JSON body', ['value' => $bodyData['run_in_background'], 'parsed' => $runInBackground]);
            } else {
                $runInBackground = $this->request->getQueryParam('run_in_background') === 'true';
                $this->logger->debug('Checking query params for run_in_background', ['found' => $runInBackground]);
            }
            $waitForCompletion = $this->request->getQueryParam('wait') === 'true';

            if ($runInBackground || $waitForCompletion) {
                // Si wait=true, iniciamos en background pero luego esperamos
                ini_set('ignore_user_abort', 'On');
                $this->logger->info($waitForCompletion ? 'Deployment with wait enabled' : 'Background run enabled');
                $website = $this->configReader->get('website') ?? '-website-not-configured-';
                $runId = $this->response->getRunId();

                // Iniciar deployment en background
                $this->response->setStatusCode(201);

                if ($waitForCompletion) {
                    // Con wait=true: NO cerrar conexión, mantenerla abierta
                    // Ejecutar deployment y hacer polling interno
                    // IMPORTANTE: Esto requiere que PHP/Nginx tenga timeouts largos

                    // NO llamamos finishRequest() - mantenemos la conexión abierta
                    // Configurar headers para mantener conexión viva
                    header('Connection: keep-alive');
                    header('X-Accel-Buffering: no'); // Deshabilitar buffering de Nginx

                    // Ejecutar deployment en el mismo proceso
                    $this->executeTrigger();

                    // Esperar hasta que termine (polling interno)
                    // La respuesta se enviará cuando termine
                    $this->waitForDeploymentCompletion($runId);
                } else {
                    // Solo iniciar y retornar URLs (comportamiento original)
                    $statusUrl = "{$website}?deployment_status=true&previous_run_id={$runId}";
                    $logsUrl = "{$website}?previous_run_id={$runId}";
                    $waitUrl = "{$website}?wait_deployment=true&previous_run_id={$runId}";

                    $responseData = [
                        'status' => 'deployment_started',
                        'run_id' => $runId,
                        'message' => 'El deployment ha comenzado en segundo plano',
                        'wait_url' => $waitUrl,
                        'monitoring' => [
                            'status_url' => $statusUrl,
                            'logs_url' => $logsUrl,
                            'wait_url' => $waitUrl,
                            'description' => 'Usa wait_url para esperar automáticamente hasta que termine, o status_url para consultar el estado.',
                        ],
                    ];

                    $this->response->addToBody(
                        json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
                    );
                    $this->response->send('application/json; charset=utf-8');
                    $this->logger->info('Response sent, finishing request and starting background execution');
                    $this->finishRequest();
                    $this->logger->info('Request finished, executing trigger in background');
                    $this->executeTrigger();
                }
            } else {
                // Ejecución síncrona tradicional (sin background)
                $this->logger->info('Synchronous deployment (no background, no wait)');
                $this->executeTrigger();
                $this->response->send();
            }
        }
    }

    private function waitForDeploymentCompletion(string $runId, ?int $maxWait = null, ?int $pollInterval = null): void {
        $maxWait = $maxWait ?? 2400; // Default 40 minutos
        $pollInterval = $pollInterval ?? 5; // Default 5 segundos
        $startTime = time();

        $this->logger->info("Waiting for deployment to complete", [
            'run_id' => $runId,
            'max_wait' => $maxWait,
            'poll_interval' => $pollInterval,
        ]);

        // IMPORTANTE: La conexión HTTP está ABIERTA (no cerramos con finishRequest)
        // Hacemos polling interno consultando el DeploymentStatus
        // La respuesta se enviará cuando termine
        header('Content-Type: application/json; charset=utf-8');

        while (true) {
            $elapsed = time() - $startTime;

            if ($elapsed >= $maxWait) {
                $this->response->setStatusCode(408);
                $this->response->addToBody(json_encode([
                    'error' => 'timeout',
                    'run_id' => $runId,
                    'message' => "Timeout esperando deployment ({$maxWait}s)",
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

            $status = $deploymentStatus->get();
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
                    'message' => $status['error_message'] ?? 'Deployment failed',
                    'elapsed_seconds' => $elapsed,
                    'failed_step' => $failedStep ? [
                        'phase' => $failedStep['phase'] ?? null,
                        'step_id' => $failedStep['step_id'] ?? null,
                        'command' => $failedStep['command'] ?? null,
                        'exit_code' => $failedStep['exit_code'] ?? null,
                        'is_timeout' => ($failedStep['exit_code'] ?? 0) === \Mariano\GitAutoDeploy\Executer::EXIT_CODE_TIMEOUT,
                        'output' => array_slice($failedStep['output'] ?? [], -20), // Últimas 20 líneas
                    ] : null,
                ], JSON_PRETTY_PRINT));
                $this->response->send('application/json; charset=utf-8');
                return;
            }

            // Aún ejecutándose, esperar y reintentar
            // Enviar un byte para mantener la conexión viva (evitar timeouts)
            if ($elapsed % 30 === 0) { // Cada 30 segundos
                echo " "; // Enviar espacio para mantener conexión
                flush();
            }
            sleep($pollInterval);
        }
    }

    private function handleWaitDeploymentRequest(string $runId): void {
        // Endpoint separado para esperar deployment (útil para casos avanzados)
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
                    'message' => 'No se encontró el estado del deployment. Puede que el run_id sea inválido o el deployment no haya comenzado aún.',
                ], JSON_PRETTY_PRINT)
            );
            $this->response->send('application/json; charset=utf-8');
            return;
        }

        $status = $deploymentStatus->get();
        $this->response->setStatusCode(200);
        // Asegurar que siempre se serializa como objeto JSON, incluso si está vacío
        // Si el array está vacío, convertirlo a objeto para que json_encode devuelva {} en lugar de []
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
            $this->logger->debug('Finishing request using fastcgi_finish_request...');
            fastcgi_finish_request();
            $this->logger->info('Request finished OK using fastcgi_finish_request');
        } else {
            $this->logger->warning('fastcgi_finish_request function not found, relying on ignore_user_abort');
            flush();
        }
    }
}
