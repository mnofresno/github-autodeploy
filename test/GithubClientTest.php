<?php

namespace Mariano\GitAutoDeploy\Test;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use Mariano\GitAutoDeploy\ConfigReader;
use Mariano\GitAutoDeploy\GithubClient;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class GithubClientTest extends TestCase {
    private $configReaderMock;
    private $loggerMock;
    private $httpClientMock;
    private $githubClient;

    protected function setUp(): void {
        $this->configReaderMock = $this->createMock(ConfigReader::class);
        $this->loggerMock = $this->createMock(Logger::class);
        $this->httpClientMock = $this->createMock(Client::class);
        $this->githubClient = new GithubClient($this->configReaderMock, $this->loggerMock, $this->httpClientMock);
    }

    public function testFetchAllowedRangesListsUrlNotConfigured(): void {
        $this->configReaderMock->method('get')->willReturnMap([
            [GithubClient::GITHUB_META_API_URL_CONFIG_KEY, null],
            [GithubClient::GITHUB_RANGES_LISTS_CONFIG_KEY, ['actions']],
        ]);

        $this->assertEmpty($this->githubClient->fetchAllowedRangesLists());
    }

    public function testFetchAllowedRangesListsIncludeListsNotConfigured(): void {
        $this->configReaderMock->method('get')->willReturnMap([
            [GithubClient::GITHUB_META_API_URL_CONFIG_KEY, 'https://api.github.com/meta'],
            [GithubClient::GITHUB_RANGES_LISTS_CONFIG_KEY, null],
        ]);

        $this->assertEmpty($this->githubClient->fetchAllowedRangesLists());
    }

    public function testFetchAllowedRangesListsErrorFetchingData(): void {
        $this->configReaderMock->method('get')->willReturnMap([
            [GithubClient::GITHUB_META_API_URL_CONFIG_KEY, 'https://invalid-url'],
            [GithubClient::GITHUB_RANGES_LISTS_CONFIG_KEY, ['actions']],
        ]);

        $this->httpClientMock->method('request')
            ->willThrowException(new RequestException("Error", new Request('GET', 'test')));

        $this->assertEmpty($this->githubClient->fetchAllowedRangesLists());
    }

    public function testFetchAllowedRangesListsSuccess(): void {
        $this->configReaderMock->method('get')->willReturnMap([
            [GithubClient::GITHUB_META_API_URL_CONFIG_KEY, 'https://api.github.com/meta'],
            [GithubClient::GITHUB_RANGES_LISTS_CONFIG_KEY, ['actions']],
        ]);

        $responseBody = json_encode([
            'actions' => ['192.30.252.0/22', '185.199.108.0/22'],
            'hooks' => ['192.30.252.0/23'],
        ]);

        $this->httpClientMock->method('request')
            ->willReturn(new Response(200, ['Content-Type' => 'application/json'], $responseBody));

        $result = $this->githubClient->fetchAllowedRangesLists();

        $this->assertEquals(['192.30.252.0/22', '185.199.108.0/22'], $result);
    }
}
