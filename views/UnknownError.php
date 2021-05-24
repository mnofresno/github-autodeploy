<?php

namespace GithubAutoDeploy\views;

class UnknownError implements ViewInterface {
    private $message;

    function __construct(string $message) {
        $this->message = $message;
    }

    function render() {
        header('HTTP/1.1 422 Unknown Error');
        echo "<span style=\"color: #ff0000\">Error making the hamster run.</span>\n";
        echo "<span style=\"color: #ff0000\">{$this->message}</span>\n";
    }
}
