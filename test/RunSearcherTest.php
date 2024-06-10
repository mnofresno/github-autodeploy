<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\RunSearcher;
use PHPUnit\Framework\TestCase;

class RunSearcherTest extends TestCase {
    public function testSearchValidNotFound(): void {
        $subject = new RunSearcher(new ConfigReader());
        $result = $subject->search('8b7f5891-04e0-4216-b299-314965940b96');
        $this->assertEquals([], $result);
    }

    public function testSearchFoundInvalidUUID(): void {
        $testRunId = 'this-is_not_valid-uuid';
        file_put_contents(__DIR__ . '/../deploy-log.log',  \date('Y-m-d H:i:s') . ' - {"runId":"9b7f5891-04e0-4216-b299-314965940b96"}');
        $subject = new RunSearcher(new ConfigReader());
        $result = $subject->search($testRunId);
        $this->assertEquals([], $result);
    }

    public function testSearchFoundOldFormatTrap(): void {
        $testRunId = '7826dedf-52a5-4170-8d4f-ac6170f7df1d';
        $subject = new RunSearcher(new ConfigReader(), __DIR__ . DIRECTORY_SEPARATOR . 'RunSearcherLogExample.txt');
        $result = $subject->search($testRunId);
        $this->assertEquals([
            [
                'date' => '2024-06-09T02:27:39.414352+00:00',
                'logLevel' => 'INFO',
                'message' => 'Ran 2 commands',
                'extra_context' => [],
                'context' => [
                    'runId' => $testRunId,
                    'repo' => 'oh gran proyecto',
                    'key' => 'oh_la_la_key',
                    'request' => ['body' => []],
                ],
                'updatingCommands' => [
                    [
                        'command' => 'composer install',
                        'output' => [
                            'When running some commands like composer',
                            '  Problem 1',
                            '    - A command output like composer, with hyphens and spaces mixed'
                        ],
                        'exitCode' => 2
                    ],
                    [
                        'command' => 'ls',
                        'output' => ['one_file'],
                        'exitCode' => 0
                    ]
                ]
            ]
        ], $result);
    }

    public function testSearchFoundWithOldFormat(): void {
        $testRunId = '9b7f5891-04e0-4216-b299-314965940b96';
        file_put_contents(__DIR__ . '/../deploy-log.log',  ($givenDate = date('Y-m-d H:i:s')) . ' - {"runId":"9b7f5891-04e0-4216-b299-314965940b96"}');
        $subject = new RunSearcher(new ConfigReader());
        $result = $subject->search($testRunId);
        $this->assertEquals([
            [
                'runId' => $testRunId,
                'date' => $givenDate,
                'logLevel' => null,
                'message' => null,
                'extra_context' => []
            ]
        ], $result);
    }

    public function testSearchFoundMonolog(): void {
        $line = '[2024-05-08T03:09:04.454598+00:00] github-autodeploy.INFO: Ran 4 commands {"context":{"runId":"1a781415-67f7-4b1c-a8ed-c240bcdf66f7","repo":"pepe_project"}} []';
        file_put_contents(__DIR__ . '/../deploy-log.log', $line);
        $subject = new RunSearcher(new ConfigReader());
        $result = $subject->search('1a781415-67f7-4b1c-a8ed-c240bcdf66f7');
        $this->assertEquals([
            [
                'context' => [
                    'runId' => '1a781415-67f7-4b1c-a8ed-c240bcdf66f7',
                    'repo' => 'pepe_project',
                ],
                'date' => '2024-05-08T03:09:04.454598+00:00',
                'logLevel' => 'INFO',
                'message' => 'Ran 4 commands',
                'extra_context' => [],
            ]
        ], $result);
    }
}
