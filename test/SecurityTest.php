<?php

namespace GitAutoDeploy\Test;

use GitAutoDeploy\exceptions\ForbiddenException;
use GitAutoDeploy\Security;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase {
    /**
     * @runInSeparateProcess
     * Need separated process to be able to assert header modification by exception thrown
     */
    function testAssertBlockedIP() {
        $this->expectException(ForbiddenException::class);
        Security::assert(
            ['127.0.0.2', '192.168.1.14'],
            [],
            '127.0.0.5'
        );
    }

    function testAssertAllowedIP() {
        Security::assert(
            ['127.0.0.2', '192.168.2.14', '192.168.1.'],
            [],
            '192.168.1.16'
        );
        $this->assertTrue(true);
    }
}
