<?php

namespace Mariano\GitAutoDeploy;

class LogRotator {
    public const DEFAULT_MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB
    public const DEFAULT_MAX_FILES = 5;

    private $logFilePath;
    private $logDir;
    private $maxFileSizeBytes;
    private $maxFiles;

    public function __construct(string $logFilePath, ?int $maxFileSizeBytes = null, ?int $maxFiles = null) {
        $this->logFilePath = $logFilePath;
        $this->logDir = dirname($logFilePath);
        $this->maxFileSizeBytes = $maxFileSizeBytes ?? self::DEFAULT_MAX_FILE_SIZE_BYTES;
        $this->maxFiles = $maxFiles ?? self::DEFAULT_MAX_FILES;
    }

    public function rotateIfNeeded(): void {
        if (!file_exists($this->logFilePath)) {
            return;
        }

        if (filesize($this->logFilePath) < $this->maxFileSizeBytes) {
            return;
        }

        $this->rotate();
    }

    public function rotate(): void {
        if (!file_exists($this->logFilePath)) {
            return;
        }

        // Remove oldest rotated file if at capacity
        $this->removeOldest();

        // Shift existing rotated files: deploy-log.4.gz -> 5.gz, etc.
        for ($i = $this->maxFiles - 1; $i >= 1; $i--) {
            $src = $this->rotatedFileName($i);
            $dst = $this->rotatedFileName($i + 1);
            if (file_exists($src)) {
                rename($src, $dst);
            }
        }

        // Compress current rotated (deploy-log.1.gz -> already compressed, but we need to handle .1)
        // Move current log to .1 and compress it
        $tempRotated = $this->logFilePath . '.1';
        rename($this->logFilePath, $tempRotated);

        // Compress the rotated file
        $this->compressFile($tempRotated);

        // Create empty new log file
        file_put_contents($this->logFilePath, '');
    }

    /**
     * Get all log file paths (current + rotated compressed), newest first.
     */
    public function getAllLogFilePaths(): array {
        $files = [];

        if (file_exists($this->logFilePath)) {
            $files[] = $this->logFilePath;
        }

        for ($i = 1; $i <= $this->maxFiles; $i++) {
            $rotatedGz = $this->rotatedFileName($i);
            $rotatedPlain = $this->logFilePath . '.' . $i;
            // Check compressed first (our rotator and Monolog with compression)
            if (file_exists($rotatedGz)) {
                $files[] = $rotatedGz;
            } elseif (file_exists($rotatedPlain)) {
                // Fallback: Monolog rotated without compression
                $files[] = $rotatedPlain;
            }
        }

        return $files;
    }

    public function getMaxFileSizeBytes(): int {
        return $this->maxFileSizeBytes;
    }

    public function getMaxFiles(): int {
        return $this->maxFiles;
    }

    private function rotatedFileName(int $index): string {
        return $this->logFilePath . '.' . $index . '.gz';
    }

    private function removeOldest(): void {
        $oldest = $this->rotatedFileName($this->maxFiles);
        if (file_exists($oldest)) {
            unlink($oldest);
        }
    }

    private function compressFile(string $filePath): void {
        $gzFilePath = $filePath . '.gz';
        $content = file_get_contents($filePath);
        file_put_contents($gzFilePath, gzcompress($content));
        unlink($filePath);
    }
}
