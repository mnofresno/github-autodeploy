<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
use Mariano\GitAutoDeploy\GithubClient;
use Mariano\GitAutoDeploy\Security;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase {
    private $subject;
    private $allowListFile;
    private $githubClient;

    public function setUp(): void {
        $this->githubClient = $this->createMock(GithubClient::class);
        $this->subject = new Security($this->createMock(Logger::class), $this->githubClient);
        $this->allowListFile = 'allow-list.txt';
        file_put_contents($this->allowListFile, "");
    }

    public function tearDown(): void {
        if (file_exists($this->allowListFile)) {
            unlink($this->allowListFile);
        }
    }

    /**
     * @runInSeparateProcess
     * Need separated process to be able to assert header modification by exception thrown
     */
    public function testAssertBlockedIP() {
        $this->expectException(ForbiddenException::class);
        $this->subject->setParams(
            [],
            [],
            '127.0.0.5'
        )->assert();
    }

    public function testAssertAllowedIP() {
        file_put_contents($this->allowListFile, "192.168.1.0/24\n");
        $this->subject->setParams(
            [],
            [],
            '192.168.1.16'
        )->assert();
        $this->assertTrue(true);
    }

    public function testAssertIpInCidrRange() {
        file_put_contents($this->allowListFile, "192.168.1.0/24\n");
        $this->subject->setParams(
            [],
            [],
            '192.168.1.50'
        )->assert();
        $this->assertTrue(true);
    }

    public function testAssertIpNotInAllowListOrCidrAndFetchFromGithub() {
        $this->githubClient->expects($this->once())
            ->method('fetchActionsCidrs')
            ->willReturn(['192.168.2.0/24']);

        file_put_contents($this->allowListFile, "192.168.1.0/24\n");

        $this->subject->setParams(
            [],
            [],
            '192.168.2.16'
        )->assert();
        $this->assertTrue(true);
    }

    public function testFetchGithubActionsCidrs() {
        // Simulating fetching from GitHub
        $cidrs = ['192.168.3.0/24', '192.168.4.0/24'];
        file_put_contents($this->allowListFile, "192.168.1.0/24\n");

        $this->githubClient->expects($this->once())
            ->method('fetchActionsCidrs')
            ->willReturn($cidrs);

        $this->subject->setParams(
            [],
            [],
            '192.168.3.16'
        )->assert();
        $this->assertTrue(true);

        $updatedAllowList = file($this->allowListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(3, $updatedAllowList);
        $this->assertContains('192.168.3.0/24', $updatedAllowList);
        $this->assertContains('192.168.4.0/24', $updatedAllowList);
    }
}
