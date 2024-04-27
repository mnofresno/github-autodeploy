<?php

namespace Mariano\GitAutoDeploy\exceptions;

use Exception;
use Mariano\GitAutoDeploy\Logger;
use Mariano\GitAutoDeploy\views\BaseView;

abstract class BaseException extends Exception {
    private $view;

    function __construct(BaseView $view) {
        Logger::log(['exception' => get_class($this), 'statusCode' => $this->getStatusCode()]);
        $this->view = $view;
    }

    function render(): string {
        return $this->view->render();
    }

    abstract function getStatusCode(): int;
}
