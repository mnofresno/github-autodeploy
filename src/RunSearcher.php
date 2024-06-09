<?php

namespace Mariano\GitAutoDeploy;

use Ramsey\Uuid\Uuid;

class RunSearcher {
    private $logFileName = null;

    public function __construct(?string $logFileName = null) {
        $this->logFileName = $logFileName;
    }

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
        $context = explode(' - ', $logRow);
        $jsonContext = count($context) === 2
            ? @$context[1]
            : null;
        if (!$this->isJson($jsonContext)) {
            $jsonContext = null;
        }
        $date = @$context[0];
        $logLevel = null;
        $message = null;
        if (!$jsonContext) {
            $pattern = '/\[(.+)\]\s(.+):\s(.+)\s(\{.+\})\s\[\]/';
            if (preg_match($pattern, $logRow, $matches)) {
                $date = $matches[1];
                $logLevel = $matches[2];
                $message = $matches[3];
                $jsonContext = $matches[4];
            }
        }
        $result = json_decode($jsonContext, true);
        $result['date'] = $date;
        $result['logLevel'] = $logLevel;
        $result['message'] = $message;
        return $result;
    }

    private function isJson(?string $input): bool {
        json_decode($input);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function read(string $runId): string {
        if (!Uuid::isValid($runId)) {
            return '';
        }
        $fileName = escapeshellarg($this->logFileName ?? $this->fileName());
        $runId = escapeshellarg($runId);
        exec("cat {$fileName} | grep {$runId}", $searchOutput);
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
