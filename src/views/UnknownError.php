<?php

namespace GitAutoDeploy\views;

class UnknownError extends BaseView {
    private $message;

    function __construct(string $message = null) {
        $this->message = $message;
    }

    function render() {
        echo "<span style=\"color: #ff0000\">Unknown error making the hamster run:";
        echo "  <span>{$this->message}</span>\n";
        echo "</span>\n";
    }
}
