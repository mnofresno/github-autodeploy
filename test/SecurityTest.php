<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Security;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase {
    private $subject;

    public function setUp(): void {
        $this->subject = new Security($this->createMock(Logger::class));
    }

    /**
     * @runInSeparateProcess
     * Need separated process to be able to assert header modification by exception thrown
     */
    function testAssertBlockedIP() {
        $this->expectException(ForbiddenException::class);
        $this->subject->assert(
            ['127.0.0.2', '192.168.1.14'],
            [],
            '127.0.0.5'
        );
    }

    function testAssertAllowedIP() {
        $this->subject->assert(
            ['127.0.0.2', '192.168.2.14', '192.168.1.'],
            [],
            '192.168.1.16'
        );
        $this->assertTrue(true);
    }
}
