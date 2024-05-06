<?php

namespace Mariano\GitAutoDeploy;

use Ramsey\Uuid\Uuid;

class Hamster {
    private $runner;
    private $request;
    private $response;
    private $configReader;

    function __construct() {
        $this->response = new Response($this->getLastRunId());
        $this->runner = new Runner(
            $this->request = Request::fromHttp(),
            $this->response,
            $this->configReader = new ConfigReader()
        );
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
            $this->response->send('application/json');
            exit();
        } else {
            if ($this->request->getQueryParam('run_in_background') === 'true') {
                ini_set('ignore_user_abort', 'On');
                Logger::log($this->response->getRunId(), ['background_run' => true]);
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
                Logger::log($this->response->getRunId(), ['background_run' => false]);
                $this->runner->run();
                $this->response->send();
            }
        }
    }

    private function finishRequest(): void {
        if (function_exists('fastcgi_finish_request')) {
            Logger::log($this->response->getRunId(), ['message' => 'Finishing request...']);
            fastcgi_finish_request();
            Logger::log($this->response->getRunId(), ['message' => 'Request finished OK']);
        } else {
            Logger::log($this->response->getRunId(), ['exception' => 'fatcgi_finish_request function not found']);
        }
    }

    private function getLastRunId(): string {
        return Uuid::uuid4();
    }
}
