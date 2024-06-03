<?php

namespace Mariano\GitAutoDeploy\views;
use JsonSerializable;

class RanCommand extends BaseView implements JsonSerializable {
    private $command;
    private $commandOutput;

    public function __construct(string $command, array $commandOutput = []) {
        $this->command = $command;
        $this->commandOutput = $commandOutput;
    }

    public function render(): string {
        $output = implode("\n", $this->commandOutput);
        $html = "<span style=\"color: #6BE234;\">\$</span>";
        $html .= "  <span style=\"color: #729FCF;\">{$this->command}\n</span>";
        $html .= htmlentities(trim($output));
        return $html;
    }

    public function jsonSerialize(): array {
        return [
            'command' => $this->command,
            'commandOutput' => $this->commandOutput
        ];
    }
}
