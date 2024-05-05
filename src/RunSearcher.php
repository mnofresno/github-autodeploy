<?php

namespace Mariano\GitAutoDeploy;

use Ramsey\Uuid\Uuid;

class RunSearcher {
    public function search(string $runId): array {
        $logContents = $this->read($runId);
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

    private function read(string $runId): string {
        if (!Uuid::isValid($runId)) {
            return '';
        }
        exec("cat {$this->fileName()} | grep {$runId}", $searchOutput);
        return implode('\n', $searchOutput);
    }

    private function fileName(): string {
        return implode(DIRECTORY_SEPARATOR, [
            __DIR__,
            '..',
            'deploy-log.log'
        ]);
    }
}
