<?php

namespace Mariano\GitAutoDeploy\views\errors;

use Mariano\GitAutoDeploy\views\BaseView;

class DeploymentFailed extends BaseView {
    private $phase;
    private $stepId;
    private $command;
    private $exitCode;
    private $output;

    public function __construct(string $phase, int $stepId, string $command, int $exitCode, array $output) {
        $this->phase = $phase;
        $this->stepId = $stepId;
        $this->command = $command;
        $this->exitCode = $exitCode;
        $this->output = $output;
    }

    public function render(): string {
        $phaseName = ucfirst(str_replace('_', ' ', $this->phase));
        $outputText = htmlentities(implode("\n", $this->output));
        $isTimeout = $this->exitCode === \Mariano\GitAutoDeploy\Executer::EXIT_CODE_TIMEOUT;
        $errorTitle = $isTimeout ? '⏱️ Deployment Failed - Timeout' : '❌ Deployment Failed';
        $errorMessage = $isTimeout
            ? "El comando excedió el tiempo máximo permitido (timeout). Esto indica que el comando puede estar bloqueado o tomando demasiado tiempo."
            : "El deployment falló en el step {$this->stepId} de la fase \"{$phaseName}\". Por favor revisa el comando y su salida para diagnosticar el problema.";
        $timeoutBadge = $isTimeout ? '<span style="color: #856404;">(TIMEOUT)</span>' : '';

        return <<<HTML
<div style="background-color: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; margin: 20px; border-radius: 5px;">
    <h2 style="margin-top: 0; color: #721c24;">{$errorTitle}</h2>
    <p><strong>Phase:</strong> {$phaseName}</p>
    <p><strong>Step {$this->stepId}:</strong></p>
    <pre style="background-color: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto;"><code>{$this->command}</code></pre>
    <p><strong>Exit Code:</strong> <span style="color: #dc3545; font-weight: bold;">{$this->exitCode}</span> {$timeoutBadge}</p>
    <p><strong>Output:</strong></p>
    <pre style="background-color: #f5f5f5; padding: 10px; border-radius: 3px; overflow-x: auto; max-height: 400px; overflow-y: auto;">{$outputText}</pre>
    <p style="margin-top: 15px; font-size: 0.9em; color: #856404;">
        {$errorMessage}
    </p>
</div>
HTML;
    }
}
