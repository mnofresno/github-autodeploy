<?php

namespace Mariano\GitAutoDeploy;

use Monolog\Logger;

class IPAllowListManager {
    public const ALLOW_LIST_FILE_KEY = 'ips_allow_list_file';
    public const DEFAULT_ALLOW_LIST_FILE = __DIR__ . "/../ips-allow-list.list";

    private $configReader;
    private $githubClient;
    private $logger;
    private $invalidCidrs = [];
    private $logThreshold = 10;  // Log after collecting 10 invalid CIDRs
    private $lastLogTime = 0;
    private $logInterval = 60; // Log at most once every 60 seconds

    public function __construct(ConfigReader $configReader, GithubClient $githubClient, Logger $logger) {
        $this->configReader = $configReader;
        $this->githubClient = $githubClient;
        $this->logger = $logger;
    }

    private function filePath(): string {
        return $this->configReader->get(self::ALLOW_LIST_FILE_KEY) ?? self::DEFAULT_ALLOW_LIST_FILE;
    }

    public function getAllowedIpsOrRanges(): array {
        $filePath = $this->filePath();
        if (!file_exists($filePath)) {
            $this->logger->info("Allow list file does not exist: {$filePath}");
            return [];
        }
        $this->logger->info("Reading allow list file: {$filePath}");
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $validLines = array_filter($lines, function ($line) {
            return $this->isValidCidr($line) || filter_var($line, FILTER_VALIDATE_IP);
        });
        return $validLines !== false ? $validLines : [];
    }

    public function updateAllowListWithGithubCidrs(): array {
        try {
            $githubCidrs = $this->githubClient->fetchAllowedRangesLists();
        } catch (\Exception $e) {
            $this->logger->error("Failed to fetch GitHub CIDRs: " . $e->getMessage());
            return [];
        }

        $currentAllowList = $this->getAllowedIpsOrRanges();
        $newEntries = [];

        foreach ($githubCidrs as $cidr) {
            if ($this->isValidCidr($cidr) && !in_array($cidr, $currentAllowList)) {
                $newEntries[] = $cidr;
            } else {
                $this->invalidCidrs[] = $cidr;
                $this->logInvalidCidrs();
            }
        }

        if (!empty($newEntries)) {
            $newEntriesString = json_encode($newEntries);
            $this->logger->info("Adding new IPs or ranges to the list: {$newEntriesString}");
            file_put_contents($this->filePath(), implode(PHP_EOL, $newEntries) . PHP_EOL, FILE_APPEND);
        } else {
            $this->logger->info("No new IPs or ranges fetched from GitHub.");
        }

        return $this->getAllowedIpsOrRanges();
    }

    private function logInvalidCidrs() {
        $currentTime = time();
        if (count($this->invalidCidrs) >= $this->logThreshold || ($currentTime - $this->lastLogTime) >= $this->logInterval) {
            $invalidCidrsString = json_encode($this->invalidCidrs);
            $this->logger->warning("Invalid or duplicate CIDRs detected: {$invalidCidrsString}");
            $this->invalidCidrs = [];  // Clear the invalid CIDRs
            $this->lastLogTime = $currentTime;
        }
    }

    private function isValidCidr(string $cidr): bool {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2 || !is_numeric($parts[1])) {
            return false;
        }
        $ip = $parts[0];
        $mask = (int) $parts[1];

        // Check if the IP is valid and the mask is within the correct range for IPv4 or IPv6
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && $mask >= 0 && $mask <= 32) {
            return true;
        } elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) && $mask >= 0 && $mask <= 128) {
            return true;
        }

        return false;
    }
}
