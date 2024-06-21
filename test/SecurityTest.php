<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\Exceptions\ForbiddenException;
use Mariano\GitAutoDeploy\GithubClient;
use Mariano\GitAutoDeploy\IPAllowListManager;
use Mariano\GitAutoDeploy\Security;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase {
    private $subject;
    private $allowListFile;
    private $githubClientMock;
    private $ipAllowListManager;
    private $configMock;

    public function setUp(): void {
        $this->configMock = $this->createMock(ConfigReader::class);
        $this->allowListFile = 'ips-ranges-allow-list.txt';
        $this->configMock->method('get')->willReturn($this->allowListFile);
        $this->githubClientMock = $this->createMock(GithubClient::class);

        $this->ipAllowListManager = new IPAllowListManager(
            $this->configMock,
            $this->githubClientMock,
            $this->createMock(Logger::class)
        );
        $this->subject = new Security($this->createMock(Logger::class), $this->ipAllowListManager);
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
            ['127.0.0.2', '192.168.1.14'],
            [],
            '127.0.0.5'
        )->assert();
    }

    public function testAssertAllowedIP() {
        file_put_contents($this->allowListFile, "192.168.1.0/24\n");
        $this->subject->setParams(
            ['127.0.0.2', '192.168.2.14', '192.168.1.'],
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
        $this->githubClientMock->expects($this->once())
            ->method('fetchAllowedRangesLists')
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
        $cidrs = ['192.168.3.0/24', '192.168.4.0/24'];
        file_put_contents($this->allowListFile, "192.168.1.0/24\n");

        $this->githubClientMock->expects($this->once())
            ->method('fetchAllowedRangesLists')
            ->willReturn($cidrs);

        $this->subject->setParams(
            [],
            [],
            '192.168.3.16'
        )->assert();
        $this->assertTrue(true);

        $updatedAllowList = file($this->allowListFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertCount(3, $updatedAllowList); // Original + 2 new CIDR ranges
        $this->assertContains('192.168.3.0/24', $updatedAllowList);
        $this->assertContains('192.168.4.0/24', $updatedAllowList);
    }

    public function testAssertIpWithInvalidCidrInFile() {
        file_put_contents($this->allowListFile, "invalid_cidr\n");
        $this->expectException(ForbiddenException::class);
        $this->subject->setParams(
            [],
            [],
            '192.168.1.16'
        )->assert();
    }

    // New test cases
    public function testAssertAllowedIPv6() {
        file_put_contents($this->allowListFile, "2001:db8::/32\n");
        $this->subject->setParams(
            [],
            [],
            '2001:db8::1'
        )->assert();
        $this->assertTrue(true);
    }

    public function testAssertBlockedIPv6() {
        file_put_contents($this->allowListFile, "2001:db8::/32\n");
        $this->expectException(ForbiddenException::class);
        $this->subject->setParams(
            [],
            [],
            '2001:db9::1'
        )->assert();
    }

    public function testAssertIPv6InvalidCidr() {
        file_put_contents($this->allowListFile, "2001:db8::/129\n"); // Invalid mask
        $this->expectException(ForbiddenException::class);
        $this->subject->setParams(
            [],
            [],
            '2001:db8::1'
        )->assert();
    }
}
