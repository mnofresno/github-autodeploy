<?php

namespace Mariano\GitAutoDeploy;

use Ramsey\Uuid\Uuid;

class Hamster {
    private $runner;
    private $request;
    private $response;

    function __construct() {
        $this->response = new Response($this->getLastRunId());
        $this->runner = new Runner(
            $this->request = Request::fromHttp(),
            $this->response,
            new ConfigReader()
        );
    }

    function run() {
        if (($runId = $this->request->getQueryParam('previous_run_id')) !== '') {
            $this->response->setStatusCode(200);
            $this->response->addToBody("Given run Id: $runId");
            $this->response->addToBody(
                json_encode([
                    'message' => "Given run Id: $runId",
                    'results' => (new RunSearcher())->search($runId), JSON_PRETTY_PRINT
                ])
            );
            $this->response->send('application/json');
        } else {
            if ($this->request->getQueryParam('run_in_background') === 'true') {
                $this->response->addToBody("Thinking in background...{$this->response->getRunId()}");
                $this->response->setStatusCode(201);
                $this->response->send();
                $this->finishRequest();
                $this->runner->run();
            } else {
                $this->runner->run();
                $this->response->send();
            }
        }
    }

    private function finishRequest(): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            Logger::log($this->response->getRunId(), ['exception' => 'fatcgi_finish_request function not found']);
        }
    }

    private function getLastRunId(): string {
        return Uuid::uuid4();
    }
}
