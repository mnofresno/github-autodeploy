<?php

namespace Mariano\GitAutoDeploy\views;
use JsonSerializable;

class Command extends BaseView implements JsonSerializable {
    private $ranCommands = [];

    public function add(string $command, array $commandOutput = [], string $runningUser = null): void {
        $this->ranCommands[] = new RanCommand($command, $commandOutput, $runningUser);
    }

    public function render(): string {
        return implode(
            "\n",
            array_map(function (RanCommand $command) {
                return $command->render();
            }, $this->ranCommands)
        );
    }

    public function jsonSerialize(): array {
        return array_map(function (RanCommand $command) {
            return $command->jsonSerialize();
        } , $this->ranCommands);
    }
}
