<?php

namespace Mariano\GitAutoDeploy\exceptions;

use Exception;
use Mariano\GitAutoDeploy\views\BaseView;
use Monolog\Logger;

abstract class BaseException extends Exception {
    private $view;

    function __construct(BaseView $view, Logger $logger) {
        $logger->error($this->getMessage(), ['statusCode' => $this->getStatusCode()]);
        $this->view = $view;
    }

    function render(): string {
        return $this->view->render();
    }

    abstract function getStatusCode(): int;
}
