<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
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
    public function testAssertBlockedIP() {
        $this->expectException(ForbiddenException::class);
        $this->subject->setParams(
            ['127.0.0.2', '192.168.1.14'],
            [],
            '127.0.0.5'
        )->assert();
    }

    public function testAssertAllowedIP() {
        $this->subject->setParams(
            ['127.0.0.2', '192.168.2.14', '192.168.1.'],
            [],
            '192.168.1.16'
        )->assert();
        $this->assertTrue(true);
    }
}
