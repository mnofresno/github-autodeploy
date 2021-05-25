<?php

namespace GitAutoDeploy\exceptions;

use Exception;
use GitAutoDeploy\Logger;
use GitAutoDeploy\views\BaseView;

abstract class BaseException extends Exception {
    private $view;

    function __construct(BaseView $view) {
        header('HTTP/1.1 ' . $status = $this->getStatus());
        Logger::log(['exception' => get_class($this), 'status' => $status]);
        $this->view = $view;
    }

    function render() {
        $this->view->render();
    }

    abstract protected function getStatus(): string;
}