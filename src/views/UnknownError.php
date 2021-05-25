<?php

namespace GithubAutoDeploy\views;

class UnknownError implements ViewInterface {
    private $message;

    function __construct(string $message) {
        $this->message = $message;
    }

    function render() {
        echo "<span style=\"color: #ff0000\">Error making the hamster run:";
        echo "  <span>{$this->message}</span>\n";
        echo "</span>\n";
    }
}
