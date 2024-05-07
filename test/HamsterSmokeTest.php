<?php

namespace Mariano\GitAutoDeploy\Test;

use DI\Container;
use Mariano\GitAutoDeploy\ContainerProvider;
use Mariano\GitAutoDeploy\Hamster;
use PHPUnit\Framework\TestCase;

class HamsterSmokeTest extends TestCase {
    public function testHamsterConstruct(): void {
        $subject = $this->getContainer()->get(Hamster::class);
        $this->assertNotNull($subject);
    }

    private function getContainer(): Container {
        $provider = new ContainerProvider();
        return $provider->provide();

    }
}
