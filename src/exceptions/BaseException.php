<?php

namespace Mariano\GitAutoDeploy\exceptions;

use Exception;
use Mariano\GitAutoDeploy\views\BaseView;
use Monolog\Logger;

abstract class BaseException extends Exception {
    private $view;

    public function __construct(BaseView $view, Logger $logger) {
        $this->view = $view;
        parent::__construct((string) $this->view);
        $logger->error($this->getMessage(), ['statusCode' => $this->getStatusCode()]);
    }

    public function render(): string {
        return $this->view->render();
    }

    abstract public function getStatusCode(): int;
}
