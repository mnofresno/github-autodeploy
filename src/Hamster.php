<?php

namespace Mariano\GitAutoDeploy;

class Hamster {
    private $runner;
    private $request;
    private $response;

    function __construct() {
        $this->response = new Response();
        $this->runner = new Runner(
            $this->request = Request::fromHttp(),
            $this->response,
            new ConfigReader()
        );
    }

    function run() {
        if ($this->request->getQueryParam('run_in_background') === 'true') {
            $this->response->addToBody("Thinking in background...");
            $this->response->setStatusCode(201);
            $this->response->send();
            $this->finishRequest();
            $this->runner->run();
        } else {
            $this->runner->run();
            $this->response->send();
        }
    }

    private function finishRequest(): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            Logger::log(['fatcgi_finish_request function not found']);
        }
    }
}
