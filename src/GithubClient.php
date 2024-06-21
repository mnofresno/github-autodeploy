<?php

namespace Mariano\GitAutoDeploy;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Monolog\Logger;

class GithubClient {
    public const GITHUB_META_API_URL_CONFIG_KEY = 'github_meta_api_url';
    public const GITHUB_RANGES_LISTS_CONFIG_KEY = 'github_ranges_lists';

    private $configReader;
    private $logger;
    private $httpClient;

    public function __construct(ConfigReader $configReader, Logger $logger, Client $httpClient) {
        $this->configReader = $configReader;
        $this->logger = $logger;
        $this->httpClient = $httpClient;
    }

    public function fetchAllowedRangesLists(): array {
        $url = $this->configReader->get(self::GITHUB_META_API_URL_CONFIG_KEY);
        if (!$url) {
            $this->logger->warning("The url for github meta API IP ranges is not configured, lists emptied.");
            return [];
        }
        $includeLists = $this->configReader->get(self::GITHUB_RANGES_LISTS_CONFIG_KEY) ?? [];
        if (!$includeLists) {
            $this->logger->warning("Include lists for github meta api is not configured, lists emptied.");
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'PHP',
                    'Accept' => 'application/json',
                ],
            ]);
            $data = json_decode($response->getBody()->getContents(), true);
        } catch (RequestException $e) {
            $this->logger->error("Error has occured trying to get github meta api data: {$e->getMessage()}");
            return [];
        }

        $this->logger->debug("Github meta API has been hit, updating lists.");
        $result = [];
        foreach ($includeLists as $listName) {
            $result = array_merge($result, $data[$listName] ?? []);
        }
        return $result;
    }
}
