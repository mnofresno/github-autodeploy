<?php

namespace Mariano\GitAutoDeploy;

class Logger {
    static function log(string $runId, array $message, Request $requestContext = null, ILoggerDriver $loggerDriver = null) {
        $date = date('Y-m-d H:i:s');
        $request = $requestContext ?? Request::fromHttp();
        $driver = $loggerDriver ?? new LoggerDriver();
        $contextualizedMessage = [
            'context' => [
                'runId' => $runId,
                'timestamp' => $date,
                'repo' => $request->getQueryParam(Request::REPO_QUERY_PARAM),
                'key' => $request->getQueryParam(Request::KEY_QUERY_PARAM),
                'request' => [
                    'body' => $request->getBody(),
                    'headers' => $request->getHeaders(),
                    'remote_address' => $request->getRemoteAddress()
                ]
            ],
            'message' => $message
        ];
        $jsonMessage = json_encode($contextualizedMessage);
        $driver->write($jsonMessage, $date);
    }
}
