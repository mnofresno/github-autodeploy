<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\LogRotator;
use PHPUnit\Framework\TestCase;

class LogRotatorTest extends TestCase {
    private $testDir;
    private $logFile;

    protected function setUp(): void {
        $this->testDir = sys_get_temp_dir() . '/logrotator_test_' . uniqid();
        mkdir($this->testDir, 0777, true);
        $this->logFile = $this->testDir . '/deploy-log.log';
    }

    protected function tearDown(): void {
        if (is_dir($this->testDir)) {
            $this->removeDir($this->testDir);
        }
    }

    private function removeDir(string $dir): void {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testRotateIfNeededDoesNothingWhenFileIsSmall(): void {
        file_put_contents($this->logFile, 'small content');
        $rotator = new LogRotator($this->logFile, 1024, 3);

        $rotator->rotateIfNeeded();

        $this->assertFileExists($this->logFile);
        $this->assertFileEqualsData('small content', $this->logFile);
        $this->assertFileNotExists($this->logFile . '.1.gz');
    }

    public function testRotateIfNeededDoesNothingWhenFileDoesNotExist(): void {
        $rotator = new LogRotator($this->logFile, 1024, 3);

        $rotator->rotateIfNeeded();

        $this->assertFileNotExists($this->logFile);
    }

    public function testRotateCreatesCompressedFileAndEmptiesCurrent(): void {
        file_put_contents($this->logFile, str_repeat('x', 2048));
        $rotator = new LogRotator($this->logFile, 1024, 3);

        $rotator->rotate();

        $this->assertFileExists($this->logFile);
        $this->assertFileEqualsData('', $this->logFile);
        $this->assertFileExists($this->logFile . '.1.gz');
        $this->assertGreaterThan(0, filesize($this->logFile . '.1.gz'));
    }

    public function testRotatePreservesContentInCompressedFile(): void {
        $originalContent = "line1\nline2\nline3\n";
        file_put_contents($this->logFile, $originalContent);
        $rotator = new LogRotator($this->logFile, 1024, 3);

        $rotator->rotate();

        $decompressed = gzdecode(file_get_contents($this->logFile . '.1.gz'));
        $this->assertEquals($originalContent, $decompressed);
    }

    public function testRotateShiftsExistingRotatedFiles(): void {
        file_put_contents($this->logFile, str_repeat('x', 2048));
        file_put_contents($this->logFile . '.1.gz', gzencode('old rotated 1'));
        file_put_contents($this->logFile . '.2.gz', gzencode('old rotated 2'));
        $rotator = new LogRotator($this->logFile, 1024, 3);

        $rotator->rotate();

        // .1.gz should now be what was .2.gz
        $content2 = gzdecode(file_get_contents($this->logFile . '.2.gz'));
        $this->assertEquals('old rotated 1', $content2);

        // .3.gz should be what was .1.gz
        $content3 = gzdecode(file_get_contents($this->logFile . '.3.gz'));
        $this->assertEquals('old rotated 2', $content3);
    }

    public function testRotateRemovesOldestWhenAtCapacity(): void {
        file_put_contents($this->logFile, str_repeat('x', 2048));
        file_put_contents($this->logFile . '.1.gz', gzencode('rotated 1'));
        file_put_contents($this->logFile . '.2.gz', gzencode('rotated 2'));
        file_put_contents($this->logFile . '.3.gz', gzencode('rotated 3 - should be removed'));
        $rotator = new LogRotator($this->logFile, 1024, 3);

        $rotator->rotate();

        // .3.gz (the oldest, which was at max capacity) should have been removed before shift
        // After shift: old .2 -> .3, old .1 -> .2, current -> .1
        // The original .3 should be gone
        $this->assertFileNotExists($this->logFile . '.4.gz');
    }

    public function testGetAllLogFilePathsReturnsCurrentAndRotated(): void {
        file_put_contents($this->logFile, 'current');
        file_put_contents($this->logFile . '.1.gz', gzencode('rotated 1'));
        file_put_contents($this->logFile . '.2.gz', gzencode('rotated 2'));
        $rotator = new LogRotator($this->logFile, 1024, 5);

        $files = $rotator->getAllLogFilePaths();

        $this->assertCount(3, $files);
        $this->assertContains($this->logFile, $files);
        $this->assertContains($this->logFile . '.1.gz', $files);
        $this->assertContains($this->logFile . '.2.gz', $files);
    }

    public function testGetAllLogFilePathsReturnsOnlyCurrentWhenNoRotated(): void {
        file_put_contents($this->logFile, 'current');
        $rotator = new LogRotator($this->logFile, 1024, 5);

        $files = $rotator->getAllLogFilePaths();

        $this->assertCount(1, $files);
        $this->assertContains($this->logFile, $files);
    }

    public function testGetAllLogFilePathsReturnsEmptyWhenNoFiles(): void {
        $rotator = new LogRotator($this->logFile, 1024, 5);

        $files = $rotator->getAllLogFilePaths();

        $this->assertCount(0, $files);
    }

    public function testDefaultMaxFileSizeIs10MB(): void {
        $rotator = new LogRotator($this->logFile);

        $this->assertEquals(10 * 1024 * 1024, $rotator->getMaxFileSizeBytes());
    }

    public function testDefaultMaxFilesIs5(): void {
        $rotator = new LogRotator($this->logFile);

        $this->assertEquals(5, $rotator->getMaxFiles());
    }

    public function testCustomMaxFileSize(): void {
        $rotator = new LogRotator($this->logFile, 512, 3);

        $this->assertEquals(512, $rotator->getMaxFileSizeBytes());
    }

    public function testRotateMultipleTimes(): void {
        // First fill and rotate
        file_put_contents($this->logFile, 'batch1');
        $rotator = new LogRotator($this->logFile, 10, 3);
        $rotator->rotate();

        // Second fill and rotate
        file_put_contents($this->logFile, 'batch2');
        $rotator = new LogRotator($this->logFile, 10, 3);
        $rotator->rotate();

        // .1.gz should contain batch2, .2.gz should contain batch1
        $content1 = gzdecode(file_get_contents($this->logFile . '.1.gz'));
        $this->assertEquals('batch2', $content1);

        $content2 = gzdecode(file_get_contents($this->logFile . '.2.gz'));
        $this->assertEquals('batch1', $content2);
    }
}
