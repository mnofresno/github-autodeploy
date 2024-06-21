<?php

namespace Mariano\GitAutoDeploy\Test;

use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\GithubClient;
use Mariano\GitAutoDeploy\IPAllowListManager;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class IPAllowListManagerTest extends TestCase {
    private $ipAllowListManager;
    private $configMock;
    private $githubClientMock;
    private $allowListFile;

    public function setUp(): void {
        $this->configMock = $this->createMock(ConfigReader::class);
        $this->allowListFile = 'ips-allow-list-test.txt';
        $this->configMock->method('get')->willReturn($this->allowListFile);
        $this->githubClientMock = $this->createMock(GithubClient::class);

        $this->ipAllowListManager = new IPAllowListManager(
            $this->configMock,
            $this->githubClientMock,
            new Logger('test_logger')
        );
        file_put_contents($this->allowListFile, ""); // Start with an empty file
    }

    public function tearDown(): void {
        if (file_exists($this->allowListFile)) {
            unlink($this->allowListFile);
        }
    }

    public function testGetAllowedIpsOrRangesEmptyFile(): void {
        $this->assertEmpty($this->ipAllowListManager->getAllowedIpsOrRanges());
    }

    public function testGetAllowedIpsOrRangesInvalidCidr(): void {
        file_put_contents($this->allowListFile, "invalid_cidr\n");
        $allowedIpsOrRanges = $this->ipAllowListManager->getAllowedIpsOrRanges();
        $this->assertEmpty($allowedIpsOrRanges);
    }

    public function testUpdateAllowListWithGithubCidrsNoNewEntries(): void {
        $this->githubClientMock->expects($this->once())
            ->method('fetchAllowedRangesLists')
            ->willReturn(['192.168.1.0/24', '192.168.2.0/24']);

        $initialAllowList = $this->ipAllowListManager->getAllowedIpsOrRanges();
        $updatedAllowList = $this->ipAllowListManager->updateAllowListWithGithubCidrs();

        $this->assertCount(2, $updatedAllowList); // Both CIDRs should be added
        $this->assertGreaterThanOrEqual(count($initialAllowList), count($updatedAllowList));
    }

    public function testUpdateAllowListWithGithubCidrsWithNewEntries(): void {
        // Simulate that the GitHub client returns an empty array (no new entries)
        $this->githubClientMock->expects($this->once())
            ->method('fetchAllowedRangesLists')
            ->willReturn([]);

        $initialAllowList = $this->ipAllowListManager->getAllowedIpsOrRanges();
        $updatedAllowList = $this->ipAllowListManager->updateAllowListWithGithubCidrs();

        // Expecting the initial allow list (empty in this case) to be returned
        $this->assertEquals($initialAllowList, $updatedAllowList);
    }

    public function testUpdateAllowListWithInvalidGithubCidrs(): void {
        $this->githubClientMock->expects($this->once())
            ->method('fetchAllowedRangesLists')
            ->willReturn(['invalid_cidr']);

        $initialAllowList = $this->ipAllowListManager->getAllowedIpsOrRanges();
        $updatedAllowList = $this->ipAllowListManager->updateAllowListWithGithubCidrs();

        $this->assertEquals($initialAllowList, $updatedAllowList);
    }
}
