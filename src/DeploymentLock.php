<?php

namespace Mariano\GitAutoDeploy;

use RuntimeException;

/**
 * Serializes deployments for the same repository while allowing unrelated
 * repositories to deploy independently.
 */
class DeploymentLock {
    private $handle;
    private $lockPath;

    public function __construct(string $repo, ?string $lockDir = null) {
        $lockDir = $lockDir ?? __DIR__ . '/../deployment-locks';
        if (!is_dir($lockDir) && !mkdir($lockDir, 0755, true) && !is_dir($lockDir)) {
            throw new RuntimeException('Unable to create deployment lock directory');
        }

        $this->lockPath = rtrim($lockDir, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . hash('sha256', $repo)
            . '.lock';
    }

    public function acquire(int $timeoutSeconds = 3600): void {
        $this->handle = fopen($this->lockPath, 'c');
        if ($this->handle === false) {
            throw new RuntimeException('Unable to open deployment lock');
        }

        $deadline = microtime(true) + max(0, $timeoutSeconds);
        do {
            if (flock($this->handle, LOCK_EX | LOCK_NB)) {
                return;
            }

            if (microtime(true) >= $deadline) {
                $this->release();
                throw new RuntimeException('Timed out waiting for deployment lock');
            }

            usleep(250000);
        } while (true);
    }

    public function release(): void {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct() {
        $this->release();
    }
}
