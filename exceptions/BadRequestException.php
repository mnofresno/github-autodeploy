<?php

namespace GithubAutoDeploy\exceptions;

use Exception;
use GithubAutoDeploy\views\ViewInterface;

class BadRequestException extends Exception {
    private $view;

    function __construct(ViewInterface $view) {
        $this->view = $view;
    }

    function render() {
        $this->view->render();
    }
}
