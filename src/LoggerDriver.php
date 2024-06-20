<?php

namespace Mariano\GitAutoDeploy;

class LoggerDriver implements ILoggerDriver {
    public function write(string $content, string $date): void {
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
