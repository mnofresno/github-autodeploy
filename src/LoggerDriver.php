<?php

namespace Mariano\GitAutoDeploy;

class LoggerDriver implements ILoggerDriver {
    function write(string $content, string $date) {
        file_put_contents (
            implode(DIRECTORY_SEPARATOR, [
                __DIR__,
                '..',
                'deploy-log.log'
            ]),
            "$date - $content\n",
            FILE_APPEND
        );
    }
}
