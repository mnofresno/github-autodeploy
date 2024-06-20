<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\errors\Forbidden;
use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
use Monolog\Logger;

class Security implements ISecurity {
    private $logger;
    private $params;
    private $githubClient;

    public function __construct(Logger $logger, GithubClient $githubClient) {
        $this->logger = $logger;
        $this->githubClient = $githubClient;
    }

    public function setParams(...$params): self {
        $this->params = $params;
        return $this;
    }

    public function assert(): void {
        $this->doAssert(...$this->params);
    }

    private function doAssert(array $allowedIps, array $headers, string $remoteAddr): void {
        $allowedIpsFromFile = $this->readAllowList();
        $allowedIps = array_merge($allowedIps, $allowedIpsFromFile);

        $ip = $this->getClientIp($headers, $remoteAddr);

        if ($this->isIpAllowed($ip, $allowedIps)) {
            $this->throwIfAllowed(true);
            return;
        }

        // If IP is not in the allow list, fetch additional ranges from GitHub
        $githubCidrs = $this->githubClient->fetchActionsCidrs();
        $this->updateAllowList($githubCidrs);

        // Re-check the IP against the updated allow list
        $allowedIps = array_merge($allowedIps, $githubCidrs);
        $this->throwIfAllowed($this->isIpAllowed($ip, $allowedIps));
    }

    private function getClientIp(array $headers, string $remoteAddr): string {
        if (array_key_exists("x-forwarded-for", $headers)) {
            $ips = explode(",", $headers["x-forwarded-for"]);
            return trim($ips[0]);
        }
        return $remoteAddr;
    }

    private function readAllowList(): array {
        $filePath = 'allow-list.txt';
        if (!file_exists($filePath)) {
            return [];
        }
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        return $lines !== false ? $lines : [];
    }

    private function isIpAllowed(string $ip, array $allowedIps): bool {
        foreach ($allowedIps as $allow) {
            if (strpos($allow, '/') !== false) {
                if ($this->ipInRange($ip, $allow)) {
                    return true;
                }
            } elseif (stripos($ip, $allow) !== false) {
                return true;
            }
        }
        return false;
    }

    private function ipInRange(string $ip, string $cidr): bool {
        list($subnet, $maskLength) = explode('/', $cidr);
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $maskLength);
        return ($ip & $mask) == ($subnet & $mask);
    }

    private function updateAllowList(array $githubCidrs): void {
        $filePath = 'allow-list.txt';
        $currentAllowList = $this->readAllowList();
        $newEntries = array_diff($githubCidrs, $currentAllowList);

        if (!empty($newEntries)) {
            file_put_contents($filePath, implode(PHP_EOL, $newEntries) . PHP_EOL, FILE_APPEND);
        }
    }

    private function throwIfAllowed(bool $allowed) {
        if (!$allowed) {
            throw new ForbiddenException(new Forbidden(), $this->logger);
        }
    }
}
