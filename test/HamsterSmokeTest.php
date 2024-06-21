<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\Hamster;
use PHPUnit\Framework\TestCase;

class HamsterSmokeTest extends TestCase {
    use ContainerAwareTrait;

    public function testHamsterConstruct(): void {
        $mockConfig = $this->createMock(ConfigReader::class);
        $this->set(ConfigReader::class, $mockConfig);
        $subject = $this->get(Hamster::class);
        $this->assertNotNull($subject);
    }
}
