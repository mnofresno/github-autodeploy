<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\errors\Forbidden;
use Mariano\GitAutoDeploy\exceptions\ForbiddenException;
use Monolog\Logger;

class Security implements ISecurity {
    private $logger;
    private $params;
    private $ipAllowListManager;
    private $githubRunVerifier;

    public function __construct(
        Logger $logger,
        IPAllowListManager $ipAllowListManager,
        ?callable $githubRunVerifier = null
    ) {
        $this->logger = $logger;
        $this->ipAllowListManager = $ipAllowListManager;
        $this->githubRunVerifier = $githubRunVerifier;
    }

    public function setParams(...$params): self {
        $this->params = $params;
        return $this;
    }

    public function assert(): void {
        $this->doAssert(...$this->params);
    }

    private function doAssert(array $allowedIpsOrRanges, array $headers, string $remoteAddr): void {
        if ($this->isTrustedGitHubActionsRun($headers)) {
            $this->logger->info('Authenticated exact GitHub Actions deployment run.');
            return;
        }

        $clientIp = $this->getClientIp($headers, $remoteAddr);
        $this->logger->info("Checking IP $clientIp against allowed IPs or ranges.");
        if ($this->isIpAllowed($clientIp, $allowedIpsOrRanges)) {
            return;
        }
        $updatedAllowList = $this->ipAllowListManager->updateAllowListWithGithubCidrs();
        $this->logger->info('Updated allow list from GitHub CIDRs.');
        if (!$this->isIpAllowed($clientIp, $updatedAllowList)) {
            $this->throwForbidden();
        }
    }

    private function isTrustedGitHubActionsRun(array $headers): bool {
        $token = trim((string) ($headers['x-github-actions-token'] ?? ''));
        $repository = trim((string) ($headers['x-github-repository'] ?? ''));
        $repositoryKey = trim((string) ($headers['x-autodeploy-repository'] ?? ''));
        $runId = trim((string) ($headers['x-github-run-id'] ?? ''));
        $commitSha = strtolower(trim((string) ($headers['x-github-sha'] ?? '')));

        if ($token === '' || $repository === '' || $repositoryKey === '' || $runId === '' || $commitSha === '') {
            return false;
        }
        if (preg_match('#^([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)$#', $repository, $matches) !== 1) {
            return false;
        }
        if ($matches[2] !== $repositoryKey || preg_match('/^[0-9]+$/', $runId) !== 1 || preg_match('/^[0-9a-f]{40}$/', $commitSha) !== 1) {
            return false;
        }

        $trustedOwners = getenv('AUTODEPLOY_TRUSTED_GITHUB_OWNERS');
        $trustedOwners = is_string($trustedOwners) && trim($trustedOwners) !== ''
            ? preg_split('/\s*,\s*/', trim($trustedOwners))
            : ['bpf-project', 'mnofresno'];
        if (!in_array($matches[1], $trustedOwners, true)) {
            return false;
        }

        if (is_callable($this->githubRunVerifier)) {
            return (bool) call_user_func($this->githubRunVerifier, [
                'token' => $token,
                'repository' => $repository,
                'repository_key' => $repositoryKey,
                'run_id' => $runId,
                'commit_sha' => $commitSha,
            ]);
        }

        return $this->verifyGitHubRunThroughApi($token, $repository, $runId, $commitSha);
    }

    private function verifyGitHubRunThroughApi(string $token, string $repository, string $runId, string $commitSha): bool {
        if (!function_exists('curl_init')) {
            return false;
        }

        $url = sprintf(
            'https://api.github.com/repos/%s/actions/runs/%s',
            rawurlencode(explode('/', $repository, 2)[0]) . '/' . rawurlencode(explode('/', $repository, 2)[1]),
            rawurlencode($runId)
        );
        $curl = curl_init($url);
        if ($curl === false) {
            return false;
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Accept: application/vnd.github+json',
                'Authorization: Bearer ' . $token,
                'User-Agent: github-autodeploy',
                'X-GitHub-Api-Version: 2022-11-28',
            ],
        ]);
        $body = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($httpCode !== 200 || !is_string($body)) {
            return false;
        }
        $run = json_decode($body, true);
        if (!is_array($run)) {
            return false;
        }

        $allowedEvents = ['push', 'workflow_dispatch', 'repository_dispatch'];
        $allowedStatuses = ['queued', 'in_progress'];
        return (string) ($run['id'] ?? '') === $runId
            && strtolower((string) ($run['head_sha'] ?? '')) === $commitSha
            && (string) ($run['repository']['full_name'] ?? '') === $repository
            && in_array((string) ($run['event'] ?? ''), $allowedEvents, true)
            && in_array((string) ($run['status'] ?? ''), $allowedStatuses, true);
    }

    private function getClientIp(array $headers, string $remoteAddr): string {
        if (array_key_exists('x-forwarded-for', $headers)) {
            $ips = explode(',', $headers['x-forwarded-for']);
            return trim($ips[0]);
        }
        return $remoteAddr;
    }

    private function isIpAllowed(string $ip, array $allowedIpsOrRanges): bool {
        foreach ($allowedIpsOrRanges as $allow) {
            if (strpos($allow, '/') !== false) {
                if ($this->ipInRange($ip, $allow)) {
                    $this->logger->debug('IP was detected in an allowed CIDR range.');
                    return true;
                }
            } elseif (stripos($ip, $allow) !== false) {
                $this->logger->debug('IP was directly detected in the allow list.');
                return true;
            }
        }
        return false;
    }

    private function ipInRange(string $ip, string $cidr): bool {
        list($subnet, $maskLength) = explode('/', $cidr);

        if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            if ($maskLength < 0 || $maskLength > 32) {
                return false;
            }
            $ip = ip2long($ip);
            $subnet = ip2long($subnet);
            if ($ip === false || $subnet === false) {
                return false;
            }
            $mask = -1 << (32 - $maskLength);
            return ($ip & $mask) == ($subnet & $mask);
        } elseif (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            if ($maskLength < 0 || $maskLength > 128) {
                return false;
            }
            $ip = inet_pton($ip);
            $subnet = inet_pton($subnet);
            if ($ip === false || $subnet === false) {
                return false;
            }
            $mask = str_repeat('f', $maskLength / 4) . str_repeat('0', 32 - $maskLength / 4);
            $mask = pack('H*', $mask);
            return ($ip & $mask) == ($subnet & $mask);
        }
        return false;
    }

    private function throwForbidden() {
        $this->logger->warning('Access denied: neither GitHub Actions run authentication nor IP allowlist validation succeeded.');
        throw new ForbiddenException(new Forbidden(), $this->logger);
    }
}
