<?php

namespace GitAutoDeploy;

class Logger {
    static function log(array $message) {
        $json_message = json_encode($message);
        $date = date('Y-m-d H:i:s');
        file_put_contents (
            implode(DIRECTORY_SEPARATOR, [
                __DIR__,
                '..',
                'deploy-log.log'
            ]),
            "$date - $json_message\n",
            FILE_APPEND
        );
    }
}
