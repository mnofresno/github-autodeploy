<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\DeploymentLock;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class DeploymentLockTest extends TestCase {
    private $lockDir;

    protected function setUp(): void {
        $this->lockDir = sys_get_temp_dir() . '/git-autodeploy-locks-' . uniqid();
        mkdir($this->lockDir, 0755, true);
    }

    protected function tearDown(): void {
        foreach (glob($this->lockDir . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->lockDir);
    }

    public function testSameRepositoryCannotBeLockedTwice(): void {
        $first = new DeploymentLock('control-gastos', $this->lockDir);
        $second = new DeploymentLock('control-gastos', $this->lockDir);

        $first->acquire(0);

        $this->expectException(RuntimeException::class);
        $second->acquire(0);
    }

    public function testDifferentRepositoriesUseDifferentLocks(): void {
        $first = new DeploymentLock('control-gastos', $this->lockDir);
        $second = new DeploymentLock('other-repo', $this->lockDir);

        $first->acquire(0);
        $second->acquire(0);

        $this->addToAssertionCount(1);
    }
}
