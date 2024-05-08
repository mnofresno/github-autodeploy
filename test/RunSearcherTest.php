<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\RunSearcher;
use PHPUnit\Framework\TestCase;

class RunSearcherTest extends TestCase {
    public function testSearchValidNotFound(): void {
        $subject = new RunSearcher();
        $result = $subject->search('8b7f5891-04e0-4216-b299-314965940b96');
        $this->assertEquals([], $result);
    }

    public function testSearchFoundInvalidUUID(): void {
        $testRunId = 'this-is_not_valid-uuid';
        file_put_contents(__DIR__ . '/../deploy-log.log',  \date('Y-m-d H:i:s') . ' - {"runId":"9b7f5891-04e0-4216-b299-314965940b96"}');
        $subject = new RunSearcher();
        $result = $subject->search($testRunId);
        $this->assertEquals([], $result);
    }

    public function testSearchFound(): void {
        $testRunId = '9b7f5891-04e0-4216-b299-314965940b96';
        file_put_contents(__DIR__ . '/../deploy-log.log',  ($givenDate = date('Y-m-d H:i:s')) . ' - {"runId":"9b7f5891-04e0-4216-b299-314965940b96"}');
        $subject = new RunSearcher();
        $result = $subject->search($testRunId);
        $this->assertEquals([
            [
                'runId' => $testRunId,
                'date' => $givenDate,
                'logLevel' => null,
                'message' => null
            ]
        ], $result);
    }

    public function testSearchFoundMonolog(): void {
        $line = '[2024-05-08T03:09:04.454598+00:00] github-autodeploy.INFO: Ran 4 commands {"context":{"runId":"1a781415-67f7-4b1c-a8ed-c240bcdf66f7","repo":"pepe_project"}} []';
        file_put_contents(__DIR__ . '/../deploy-log.log', $line);
        $subject = new RunSearcher();
        $result = $subject->search('1a781415-67f7-4b1c-a8ed-c240bcdf66f7');
        $this->assertEquals([
            [
                'context' => [
                    'runId' => '1a781415-67f7-4b1c-a8ed-c240bcdf66f7',
                    'repo' => 'pepe_project'
                ],
                'date' => '2024-05-08T03:09:04.454598+00:00',
                'logLevel' => 'github-autodeploy.INFO',
                'message' => 'Ran 4 commands'
            ]
        ], $result);
    }
}
