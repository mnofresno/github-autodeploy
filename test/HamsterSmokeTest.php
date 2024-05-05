<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\Hamster;
use PHPUnit\Framework\TestCase;

class HamsterSmokeTest extends TestCase {
    public function testHamsterConstruct(): void {
        $this->expectWarning();
        $subject = new Hamster();
        $this->assertNotNull($subject);
    }
}
