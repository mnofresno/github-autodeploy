<?php

namespace Mariano\GitAutoDeploy;

class Hamster {
    private $runner;
    private $response;

    function __construct() {
        $this->response = new Response();
        $this->runner = new Runner(
            Request::fromHttp(),
            $this->response,
            new ConfigReader()
        );
    }

    function run() {
        $this->runner->run();
        $this->response->send();
    }
}
