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
        file_put_contents(__DIR__ . '/../deploy-log.log',  date('Y-m-d H:i:s') . ' - {"runId":"9b7f5891-04e0-4216-b299-314965940b96"}');
        $subject = new RunSearcher();
        $result = $subject->search($testRunId);
        $this->assertEquals([], $result);
    }

    public function testSearchFound(): void {
        $testRunId = '9b7f5891-04e0-4216-b299-314965940b96';
        file_put_contents(__DIR__ . '/../deploy-log.log',  date('Y-m-d H:i:s') . ' - {"runId":"9b7f5891-04e0-4216-b299-314965940b96"}');
        $subject = new RunSearcher();
        $result = $subject->search($testRunId);
        $this->assertEquals([
            ['runId' => $testRunId]
        ], $result);
    }
}
