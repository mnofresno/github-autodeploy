<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\errors\Forbidden;
use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
use Monolog\Logger;

class Security implements ISecurity {
    private $logger;
    private $params;
    private $ipAllowListManager;

    public function __construct(Logger $logger, IPAllowListManager $ipAllowListManager) {
        $this->logger = $logger;
        $this->ipAllowListManager = $ipAllowListManager;
    }

    public function setParams(...$params): self {
        $this->params = $params;
        return $this;
    }

    public function assert(): void {
        $this->doAssert(...$this->params);
    }

    private function doAssert(array $allowedIpsOrRanges, array $headers, string $remoteAddr): void {
        $ip = $this->getClientIp($headers, $remoteAddr);
        $allowedIpsOrRanges = array_merge($allowedIpsOrRanges, $this->ipAllowListManager->getAllowedIpsOrRanges());
        $this->logger->info("Checking IP $ip against allowed IPs or ranges.");

        if ($this->isIpAllowed($ip, $allowedIpsOrRanges)) {
            $this->throwIfApplies(true);
            return;
        }

        $updatedAllowList = $this->ipAllowListManager->updateAllowListWithGithubCidrs();
        $this->logger->info("Updated allow list from GitHub CIDRs.");

        $this->throwIfApplies($this->isIpAllowed($ip, $updatedAllowList));
    }

    private function getClientIp(array $headers, string $remoteAddr): string {
        if (array_key_exists("x-forwarded-for", $headers)) {
            $ips = explode(",", $headers["x-forwarded-for"]);
            return trim($ips[0]);
        }
        return $remoteAddr;
    }

    private function isIpAllowed(string $ip, array $allowedIpsOrRanges): bool {
        foreach ($allowedIpsOrRanges as $allow) {
            if (strpos($allow, '/') !== false) {
                if (!$this->ipInRange($ip, $allow)) {
                    $this->logger->warning("Invalid CIDR format detected: $allow");
                } elseif ($this->ipInRange($ip, $allow)) {
                    $this->logger->debug("IP $ip was detected in range: " . print_r($allow, true));
                    return true;
                }
            } elseif (stripos($ip, $allow) !== false) {
                $this->logger->debug("IP $ip was directly detected in allow list: " . print_r($allow, true));
                return true;
            }
        }
        return false;
    }

    private function ipInRange(string $ip, string $cidr): bool {
        list($subnet, $maskLength) = explode('/', $cidr);
        if (!filter_var($subnet, FILTER_VALIDATE_IP) || !is_numeric($maskLength)) {
            return false;
        }
        $ip = ip2long($ip);
        $subnet = ip2long($subnet);
        $mask = -1 << (32 - $maskLength);
        return ($ip & $mask) == ($subnet & $mask);
    }

    private function throwIfApplies(bool $doThrow) {
        if (!$doThrow) {
            $this->logger->warning("Access denied: IP not in allowed list or ranges.");
            throw new ForbiddenException(new Forbidden(), $this->logger);
        }
    }
}
