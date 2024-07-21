<?php

namespace Mariano\GitAutoDeploy\views\errors;

use Mariano\GitAutoDeploy\views\BaseView;

class InvalidDeployFile extends BaseView {
    private $message;

    public function __construct($message = null) {
        $this->message = @json_encode($message);
    }

    public function render(): string {
        return "<span style=\"color: #ff0000\">Ouch, sorry that must have hurt!:"
            . "  <span>{$this}</span>\n";
    }

    public function __toString(): string {
        return "Invalid deploy file, command: {$this->message} Is not a string!!";
    }
}
