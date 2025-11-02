<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\RanCommand;

class Executer {
    private $configReader;
    public const EXIT_CODE_TIMEOUT = 124;

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

        $timeout = $this->configReader->get(ConfigReader::COMMAND_TIMEOUT) ?? 3600; // Default: 1 hora
        $result = $this->executeWithTimeout($command, $timeout);

        $whoami = $this->whoami();
        return new RanCommand($command, $result['output'], $whoami, $result['exit_code']);
    }

    private function executeWithTimeout(string $command, int $timeoutSeconds): array {
        $descriptorspec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorspec, $pipes);

        if (!is_resource($process)) {
            return [
                'output' => ['Error: No se pudo iniciar el proceso'],
                'exit_code' => 1,
            ];
        }

        // Cerrar el pipe de entrada ya que no escribimos nada
        fclose($pipes[0]);

        // Configurar los pipes como no bloqueantes
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = [];
        $stderr = [];
        $startTime = time();
        $pipesStatus = [1 => true, 2 => true];

        while (true) {
            $status = proc_get_status($process);

            // Leer stdout
            if ($pipesStatus[1]) {
                $line = fgets($pipes[1]);
                if ($line !== false) {
                    $output[] = rtrim($line, "\n\r");
                } else {
                    if (feof($pipes[1])) {
                        $pipesStatus[1] = false;
                        fclose($pipes[1]);
                    }
                }
            }

            // Leer stderr
            if ($pipesStatus[2]) {
                $line = fgets($pipes[2]);
                if ($line !== false) {
                    $stderr[] = rtrim($line, "\n\r");
                } else {
                    if (feof($pipes[2])) {
                        $pipesStatus[2] = false;
                        fclose($pipes[2]);
                    }
                }
            }

            // Verificar timeout
            $elapsed = time() - $startTime;
            if ($elapsed >= $timeoutSeconds) {
                // Terminar el proceso
                proc_terminate($process, SIGTERM);
                sleep(1);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, SIGKILL);
                }
                proc_close($process);

                $timeoutMessage = sprintf(
                    "Command timed out after %d seconds. Timeout limit: %d seconds",
                    $elapsed,
                    $timeoutSeconds
                );
                $output[] = $timeoutMessage;
                $output = array_merge($output, $stderr);
                return [
                    'output' => $output,
                    'exit_code' => self::EXIT_CODE_TIMEOUT,
                ];
            }

            // Verificar si el proceso terminó
            if (!$status['running']) {
                break;
            }

            // Evitar uso excesivo de CPU
            usleep(100000); // 100ms
        }

        // Cerrar pipes restantes
        if ($pipesStatus[1]) {
            while (!feof($pipes[1])) {
                $line = fgets($pipes[1]);
                if ($line !== false) {
                    $output[] = rtrim($line, "\n\r");
                }
            }
            fclose($pipes[1]);
        }

        if ($pipesStatus[2]) {
            while (!feof($pipes[2])) {
                $line = fgets($pipes[2]);
                if ($line !== false) {
                    $stderr[] = rtrim($line, "\n\r");
                }
            }
            fclose($pipes[2]);
        }

        $exitCode = $status['exitcode'];
        $output = array_merge($output, $stderr);

        proc_close($process);

        return [
            'output' => $output,
            'exit_code' => $exitCode,
        ];
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
