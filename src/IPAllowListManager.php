<?php

namespace Mariano\GitAutoDeploy;

use Monolog\Logger;

class IPAllowListManager {
    public const ALLOW_LIST_FILE_KEY = 'ips_allow_list_file';
    public const DEFAULT_ALLOW_LIST_FILE = __DIR__ . "/../ips-allow-list.txt";

    private $configReader;
    private $githubClient;
    private $logger;

    public function __construct(ConfigReader $configReader, GithubClient $githubClient, Logger $logger) {
        $this->configReader = $configReader;
        $this->githubClient = $githubClient;
        $this->logger = $logger;
    }

    private function filePath(): string {
        return $this->configReader->get(self::ALLOW_LIST_FILE_KEY) ?? self::DEFAULT_ALLOW_LIST_FILE;
    }

    public function getAllowedIpsOrRanges(): array {
        if (!file_exists($this->filePath())) {
            return [];
        }
        $lines = file($this->filePath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines !== false ? $lines : [];
    }

    public function updateAllowListWithGithubCidrs(): array {
        try {
            $githubCidrs = $this->githubClient->fetchActionsCidrs();
        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch GitHub CIDRs: " . $e->getMessage());
            return [];
        }

        $currentAllowList = $this->getAllowedIpsOrRanges();
        $newEntries = array_diff($githubCidrs, $currentAllowList);

        if (!empty($newEntries)) {
            $newEntriesString = json_encode($newEntries);
            $this->logger->info("Adding new IPs or ranges to the list: {$newEntriesString}");
            file_put_contents($this->filePath(), implode(PHP_EOL, $newEntries) . PHP_EOL, FILE_APPEND);
        } else {
            $this->logger->info("No new IPs or ranges fetched from GitHub.");
        }

        return array_merge($currentAllowList, $newEntries);
    }
}
