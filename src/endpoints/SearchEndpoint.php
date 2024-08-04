<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\config\ConfigReader;
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

    public function __invoke() {
        switch (true) {
            case ($runId = $this->request->getQueryParam('previous_run_id')) !== '':
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
            case $this->request->getQueryParam('run_in_background') === 'true':
                ini_set('ignore_user_abort', 'On');
                $this->logger->info('Background run enabled');
                $website = $this->configReader->get('website') ?? '-website-not-configured-';
                $this->response->addToBody(
                    "Thinking in background...\n"
                    . "Please consume this:\n"
                    . "\tcurl {$website}?previous_run_id={$this->response->getRunId()}"
                );
                $this->response->setStatusCode(201);
                $this->response->send();
                $this->finishRequest();
                $this->runner->run();
                return;
            default:
                $this->logger->info('Background run disabled');
                $this->runner->run();
                $this->response->send();
                return;
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
