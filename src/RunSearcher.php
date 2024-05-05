<?php

namespace Mariano\GitAutoDeploy;

class RunSearcher {
    private $driver;

    public function __construct(ILoggerDriver $driver) {
        $this->driver = $driver;
    }

    public function search(string $runId): array {
        $driver = new LoggerDriver();
        $logContents = $driver->read();
        $foundRows = [];
        foreach (explode('\n', $logContents) as $logRow) {
            if (strpos($logRow, $runId) !== false) {
                $foundRows []= $this->parse($logRow);
            }
        }
        return $foundRows;
    }

    private function parse(string $logRow): array {
        return json_decode(explode(' - ', $logRow)[1], true);
    }
}
