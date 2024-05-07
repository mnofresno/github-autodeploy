<?php

namespace Mariano\GitAutoDeploy\Test;

use DI\Container;
use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\ContainerProvider;
use Mariano\GitAutoDeploy\Hamster;
use PHPUnit\Framework\TestCase;

class HamsterSmokeTest extends TestCase {
    public function testHamsterConstruct(): void {
        $mockConfig = $this->createMock(ConfigReader::class);
        $container = $this->getContainer();
        $container->set(ConfigReader::class, $mockConfig);
        $subject = $container->get(Hamster::class);
        $this->assertNotNull($subject);
    }

    private function getContainer(): Container {
        $provider = new ContainerProvider();
        return $provider->provide();

    }
}
