<?php

namespace Mariano\GitAutoDeploy\exceptions;

use Mariano\GitAutoDeploy\views\BaseView;
use Monolog\Logger;

class DeploymentFailedException extends BaseException {
    private $phase;
    private $stepId;
    private $command;
    private $exitCode;
    private $output;

    public function __construct(
        string $phase,
        int $stepId,
        string $command,
        int $exitCode,
        array $output,
        BaseView $view,
        Logger $logger
    ) {
        parent::__construct($view, $logger);
        $this->phase = $phase;
        $this->stepId = $stepId;
        $this->command = $command;
        $this->exitCode = $exitCode;
        $this->output = $output;
    }

    public function getPhase(): string {
        return $this->phase;
    }

    public function getStepId(): int {
        return $this->stepId;
    }

    public function getCommand(): string {
        return $this->command;
    }

    public function getExitCode(): int {
        return $this->exitCode;
    }

    public function getOutput(): array {
        return $this->output;
    }

    public function getStatusCode(): int {
        return 500;
    }
}
