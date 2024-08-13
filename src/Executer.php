<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\RanCommand;

class Executer {
    private $configReader;

    public const WHITELISTED_STRINGS = [
        '$(git symbolic-ref --short HEAD)',
        'echo $PWD',
    ];

    public function __construct(ConfigReader $configReader) {
        $this->configReader = $configReader;
    }

    public function run(string $command): RanCommand {
        [$replacedWhitelisted, $restoreWhiteListedStrings] = $this->removeWhiteListedStrings($command);
        $commandAfterEscaping = escapeshellcmd($replacedWhitelisted);
        $command = $restoreWhiteListedStrings($commandAfterEscaping);
        $commandOutput = [];
        $exitCode = 0;
        exec("$command 2>&1", $commandOutput, $exitCode);
        $whoami = $this->whoami();
        return new RanCommand($command, $commandOutput, $whoami, $exitCode);
    }

    private function removeWhiteListedStrings(string $command): array {
        $idsToRestore = [];
        $whitelist = $this->configReader->get(ConfigReader::WHITELISTED_STRINGS_KEY) ?? self::WHITELISTED_STRINGS;
        foreach ($whitelist as $currentStr) {
            $replacedWithId = uniqid('removed_str');
            $idsToRestore[$replacedWithId] = $currentStr;
            $command = str_replace($currentStr, $replacedWithId, $command);
        }
        return [
            $command,
            function (string $afterEscaping) use ($idsToRestore): string {
                foreach ($idsToRestore as $id => $originalStr) {
                    $afterEscaping = str_replace($id, $originalStr, $afterEscaping);
                }
                return $afterEscaping;
            },
        ];
    }

    private function whoami(): string {
        return exec('whoami');
    }
}
