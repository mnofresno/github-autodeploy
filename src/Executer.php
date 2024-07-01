<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\RanCommand;

class Executer {
    public function run(string $command): RanCommand {
        $commandOutput = [];
        $exitCode = 0;
        exec("$command 2>&1", $commandOutput, $exitCode);
        $whoami = $this->whoami();
        return new RanCommand($command, $commandOutput, $whoami, $exitCode);
    }

    private function whoami(): string {
        return exec('whoami');
    }
}
