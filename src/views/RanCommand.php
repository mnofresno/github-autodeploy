<?php

namespace Mariano\GitAutoDeploy\views;

use JsonSerializable;

class RanCommand extends BaseView implements JsonSerializable {
    private $command;
    private $commandOutput;
    private $runningUser;

    public function __construct(string $command, array $commandOutput = [], string $runningUser = null) {
        $this->command = $command;
        $this->commandOutput = $commandOutput;
        $this->runningUser = $runningUser;
    }

    public function render(): string {
        $output = implode("\n", $this->commandOutput);
        $html = "<span style=\"color: #6BE234;\">\$</span>";
        $html .= "  <span style=\"color: #729FCF;\">{$this->runningUser}@host {$this->command}\n</span>";
        $html .= htmlentities(trim($output));
        return $html;
    }

    public function jsonSerialize(): array {
        return [
            'command' => $this->command,
            'commandOutput' => $this->commandOutput,
            'runningUser' => $this->runningUser,
        ];
    }
}
