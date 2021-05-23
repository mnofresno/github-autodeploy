<?php

namespace GithubAutoDeploy;

class ConfigReader {
    private $config;

    function __construct() {
        $this->config = json_decode(
            file_get_contents(__DIR__ . '/config.json'),
            true
        );
    }

    function getKey(string $key) {
        return $this->config[$key];
    }
}
