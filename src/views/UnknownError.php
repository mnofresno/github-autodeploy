<?php

namespace Mariano\GitAutoDeploy\views;

class UnknownError extends BaseView {
    private $message;

    function __construct(string $message = null) {
        $this->message = $message;
    }

    function render(): string {
        return "<span style=\"color: #ff0000\">Unknown error making the hamster run:"
            ."  <span>{$this->message}</span>\n"
            ."</span>\n";
    }
}
