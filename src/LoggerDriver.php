<?php

namespace Mariano\GitAutoDeploy;

class LoggerDriver implements ILoggerDriver {
    private $logRotator;

    public function __construct(?LogRotator $logRotator = null) {
        $this->logRotator = $logRotator ?? new LogRotator($this->fileName());
    }

    public function write(string $content, string $date): void {
        $this->logRotator->rotateIfNeeded();
        file_put_contents(
            $this->fileName(),
            "$date - $content\n",
            FILE_APPEND
        );
    }

    private function fileName(): string {
        return implode(DIRECTORY_SEPARATOR, [
            __DIR__,
            '..',
            'deploy-log.log',
        ]);
    }
}
