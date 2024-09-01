<?php

namespace Mariano\GitAutoDeploy\views;

use JsonSerializable;

class RanCommand extends BaseView implements JsonSerializable {
    private $command;
    private $commandOutput;
    private $runningUser;
    private $exitCode;

    public function __construct(string $command, array $commandOutput = [], ?string $runningUser, int $exitCode) {
        $this->command = $command;
        $this->commandOutput = $commandOutput;
        $this->runningUser = $runningUser;
        $this->exitCode = $exitCode;
    }

    public function render(): string {
        $output = htmlentities(trim(implode("\n", $this->commandOutput)));
        $html = sprintf(
            '<span style="color: #729FCF;">%s@host <span style="color: #6BE234;">$</span> %s</span>',
            $this->runningUser,
            $this->command
        );
        $html .= sprintf(
            '<br/><span style="color: #729FCF;">exit code: %s</span><br/>',
            $this->exitCode
        );
        $html .= $output;

        return $html;
    }

    public function jsonSerialize(): array {
        return [
            'command' => $this->command,
            'command_output' => $this->commandOutput,
            'running_user' => $this->runningUser,
            'exit_code' => $this->exitCode,
        ];
    }

    public function runningUser(): ?string {
        return $this->runningUser;
    }

    public function exitCode(): int {
        return $this->exitCode;
    }
}
