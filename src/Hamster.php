<?php

namespace Mariano\GitAutoDeploy;

use Monolog\Logger;

class Hamster {
    private $runner;
    private $request;
    private $response;
    private $configReader;
    private $logger;

    function __construct(
        Logger $logger,
        Runner $runner,
        Response $response,
        Request $request,
        ConfigReader $configReader
    ) {
        $this->logger = $logger;
        $this->runner = $runner;
        $this->request = $request;
        $this->response = $response;
        $this->configReader = $configReader;
    }

    function run() {
        if (($runId = $this->request->getQueryParam('previous_run_id')) !== '') {
            $this->response->setStatusCode(200);
            $this->response->addToBody(
                json_encode([
                    'message' => "Given run Id: $runId",
                    'results' => (new RunSearcher())->search($runId)
                ], JSON_PRETTY_PRINT)
            );
            $this->response->send('application/json; charset=utf-8');
            exit();
        } else {
            if ($this->request->getQueryParam('run_in_background') === 'true') {
                ini_set('ignore_user_abort', 'On');
                $this->logger->info('Background run enabled');
                $website = $this->configReader->get('website') ?? '-website-not-configured-';
                $this->response->addToBody(
                    "Thinking in background...\n"
                    ."Please consume this:\n"
                    ."\tcurl {$website}?previous_run_id={$this->response->getRunId()}"
                );
                $this->response->setStatusCode(201);
                $this->response->send();
                $this->finishRequest();
                $this->runner->run();
            } else {
                $this->logger->info('Background run disabled');
                $this->runner->run();
                $this->response->send();
            }
        }
    }

    private function finishRequest(): void {
        if (function_exists('fastcgi_finish_request')) {
            $this->logger->debug('Finishing request...');
            fastcgi_finish_request();
            $this->logger->debug('Request finished OK');
        } else {
            $this->logger->error('fatcgi_finish_request function not found');
        }
    }
}
