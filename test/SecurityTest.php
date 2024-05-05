<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
use Mariano\GitAutoDeploy\Response;
use Mariano\GitAutoDeploy\Security;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase {
    /**
     * @runInSeparateProcess
     * Need separated process to be able to assert header modification by exception thrown
     */
    function testAssertBlockedIP() {
        $this->expectException(ForbiddenException::class);
        Security::assert(
            'run_id_for_security_test',
            ['127.0.0.2', '192.168.1.14'],
            [],
            '127.0.0.5'
        );
    }

    function testAssertAllowedIP() {
        Security::assert(
            'run_id_for_security_test',
            ['127.0.0.2', '192.168.2.14', '192.168.1.'],
            [],
            '192.168.1.16'
        );
        $this->assertTrue(true);
    }
}
