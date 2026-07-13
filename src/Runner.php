<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\cli\CliSecurity;
use Mariano\GitAutoDeploy\views\Footer;
use Mariano\GitAutoDeploy\views\Header;
use Mariano\GitAutoDeploy\views\errors\MissingRepoOrKey;
use Mariano\GitAutoDeploy\exceptions\BadRequestException;
use Mariano\GitAutoDeploy\exceptions\BaseException;
use Mariano\GitAutoDeploy\exceptions\DeploymentFailedException;
use Mariano\GitAutoDeploy\views\Command;
use Mariano\GitAutoDeploy\views\errors\DeploymentFailed;
use Mariano\GitAutoDeploy\views\errors\RepoNotExists;
use Mariano\GitAutoDeploy\views\errors\UnknownError;
use Monolog\Logger;
use Throwable;

class Runner {
    private $request;
    private $response;
    private $configReader;
    private $logger;
    private $ipAllowListManager;
    private $customCommands;
    private $deployConfigReader;
    private $executer;
    private $createRepoIfNotExists = false;
    private $createdNewRepo = false;
    private $deployCommitSha = 'unknown';

    private $runningLog = [];
    private $deploymentStatus;

    public function __construct(
        Request $request,
        Response &$response,
        ConfigReader $configReader,
        Logger $logger,
        IPAllowListManager $ipAllowListManager,
        CustomCommands $customCommands,
        DeployConfigReader $deployConfigReader,
        Executer $executer
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->configReader = $configReader;
        $this->logger = $logger;
        $this->ipAllowListManager = $ipAllowListManager;
        $this->customCommands = $customCommands;
        $this->deployConfigReader = $deployConfigReader;
        $this->executer = $executer;
    }

    public function runForCli(): void {
        $this->doRun(new CliSecurity());
    }

    public function run(bool $createRepoIfNotExists = false): void {
        $this->runningLog = [];
        $this->createRepoIfNotExists = $createRepoIfNotExists;
        $this->deploymentStatus = new DeploymentStatus($this->response->getRunId());
        $this->doRun(new Security($this->logger, $this->ipAllowListManager));
    }

    private function doRun(ISecurity $security): void {
        $this->response->addViewToBody(new Header());
        try {
            $this->doRunWithSecurity($security);
            $this->response->setStatusCode($this->createdNewRepo ? 201 : 200);
        } catch (BaseException $e) {
            if ($e instanceof DeploymentFailedException && $this->deploymentStatus) {
                // El estado ya fue marcado como fallido en runCollectionOfCommands
            } elseif ($this->deploymentStatus) {
                // Marcar como fallido para otros tipos de errores
                $this->deploymentStatus->markFailed(
                    'unknown',
                    -1,
                    'unknown',
                    [],
                    -1,
                    $e->getMessage()
                );
            }
            $this->response->addToBody($e->render());
            $this->response->setStatusCode($e->getStatusCode());
        } catch (Throwable $e) {
            if ($this->deploymentStatus) {
                $this->deploymentStatus->markFailed(
                    'unknown',
                    -1,
                    'unknown',
                    [],
                    -1,
                    $e->getMessage()
                );
            }
            $view = new UnknownError($e->getMessage());
            $this->response->addViewToBody($view);
            $this->response->setStatusCode(500);
        } finally {
            $this->response->addViewToBody(new Footer($this->response->getRunId()));
        }
    }

    private function doRunWithSecurity(ISecurity $security): void {
        $security->setParams(
            array_merge(
                $this->configReader->get(ConfigReader::IPS_ALLOWLIST),
                $this->ipAllowListManager->getAllowedIpsOrRanges()
            ),
            $this->request->getHeaders(),
            $this->request->getRemoteAddress()
        )->assert();
        $this->doRunAfterSecurity();
    }

    private function doRunAfterSecurity(): void {
        $repo = $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        $key = $this->request->getQueryParam(Request::KEY_QUERY_PARAM);
        $this->assertRepoAndKey($repo, $key);

        $commit = $this->request->getBody()['commit'] ?? [];
        $this->deployCommitSha = is_array($commit) && isset($commit['sha']) && is_string($commit['sha'])
            ? $commit['sha']
            : 'unknown';
        $this->deploymentStatus->initialize($repo, $key, $commit);

        $commandView = new Command();
        $this->changeDirToRepoPath($commandView);
        $this->updateRepository($commandView);
    }

    private function updateRepository(Command $commandView): void {
        flush();
        $preFetchCommands = $this->getPreFetchCommands();
        $fetchCommands = $this->getFetchCommands();
        $postFetchCommands = $this->getPostFetchCommands();
        $verboseMatchers = $this->getVerboseMatchers();

        $this->runCollectionOfCommands($preFetchCommands, $commandView, DeploymentStatus::PHASE_PRE_FETCH, $verboseMatchers);
        $this->runCollectionOfCommands($fetchCommands, $commandView, DeploymentStatus::PHASE_FETCH, $verboseMatchers);
        $this->runCollectionOfCommands($postFetchCommands, $commandView, DeploymentStatus::PHASE_POST_FETCH, $verboseMatchers);

        $commandsCount = count($preFetchCommands) + count($fetchCommands) + count($postFetchCommands);
        $this->logger->info("Ran {$commandsCount} commands", ['updating_commands' => $this->runningLog]);
        $this->deploymentStatus->markSuccess();
        $this->response->addViewToBody($commandView);
    }

    private function runCollectionOfCommands(array $commands, Command $view, string $phase, array $verboseMatchers = []): void {
        if (empty($commands)) {
            return;
        }

        $this->deploymentStatus->startPhase($phase);
        $this->logger->info("Starting phase: {$phase}", ['phase' => $phase, 'commands_count' => count($commands)]);

        $stepId = count($this->runningLog);
        foreach ($commands as $command) {
            $verbose = $this->isVerboseCommand($command, $verboseMatchers);
            $this->deploymentStatus->startStep($command, $phase, $verbose);
            $this->logger->debug("Running command: {$command}", ['phase' => $phase, 'step_id' => $stepId]);

            $outputCallback = $verbose
                ? function (string $line) use ($stepId): void {
                    $this->deploymentStatus->appendStepOutput($stepId, $line);
                }
            : null;

            $afterRan = $this->executer->run($command, $outputCallback);
            $this->deploymentStatus->completeStep($stepId, $afterRan->getCommandOutput(), $afterRan->exitCode());

            $view->add($afterRan);
            $this->runningLog [] = $afterRan->jsonSerialize();

            if ($afterRan->exitCode() !== 0) {
                $isTimeout = $afterRan->exitCode() === Executer::EXIT_CODE_TIMEOUT;
                $errorType = $isTimeout ? 'Command timed out' : 'Command failed';
                $errorMessage = sprintf(
                    "%s in phase '%s' with exit code %d: %s",
                    $errorType,
                    $phase,
                    $afterRan->exitCode(),
                    $command
                );
                $this->logger->error($errorMessage, [
                    'phase' => $phase,
                    'step_id' => $stepId,
                    'command' => $command,
                    'exit_code' => $afterRan->exitCode(),
                    'output' => $afterRan->getCommandOutput(),
                ]);

                $this->deploymentStatus->markFailed(
                    $phase,
                    $stepId,
                    $command,
                    $afterRan->getCommandOutput(),
                    $afterRan->exitCode(),
                    $errorMessage
                );

                throw new DeploymentFailedException(
                    $phase,
                    $stepId,
                    $command,
                    $afterRan->exitCode(),
                    $afterRan->getCommandOutput(),
                    new DeploymentFailed($phase, $stepId, $command, $afterRan->exitCode(), $afterRan->getCommandOutput()),
                    $this->logger
                );
            }

            $stepId++;
        }

        $this->logger->info("Completed phase: {$phase}", ['phase' => $phase, 'commands_count' => count($commands)]);
    }

    private function getVerboseMatchers(): array {
        $repoConfig = $this->deployConfigReader->fetchRepoConfig($this->request->getQueryParam(Request::REPO_QUERY_PARAM));
        if (!$repoConfig || !method_exists($repoConfig, 'verboseMatchers')) {
            return [];
        }

        return $repoConfig->verboseMatchers();
    }

    private function isVerboseCommand(string $command, array $verboseMatchers): bool {
        foreach ($verboseMatchers as $matcher) {
            $pattern = $this->normalizeVerboseMatcher($matcher);
            if (@preg_match($pattern, $command) === 1) {
                return true;
            }

            if (@preg_last_error() !== PREG_NO_ERROR) {
                $this->logger->warning('Invalid verbose matcher ignored', [
                    'matcher' => $matcher,
                    'pattern' => $pattern,
                ]);
            }
        }

        return false;
    }

    private function normalizeVerboseMatcher(string $matcher): string {
        return '~' . str_replace('~', '\~', $matcher) . '~';
    }

    private function whoami(): string {
        return exec('whoami');
    }

    private function changeDirToRepoPath(Command $commandView): void {
        $repoDirectory = $this->configReader->get(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        if (!is_dir($repoDirectory) || ($this->createRepoIfNotExists && !is_dir($repoDirectory . DIRECTORY_SEPARATOR . '.git'))) {
            if ($this->createRepoIfNotExists) {
                $parentDirectory = dirname($repoDirectory);
                chdir($parentDirectory);
                $transportConfig = $this->resolveGitTransportConfig();
                $this->runCollectionOfCommands(
                    $this->cloneRepoCommands($repoDirectory, $transportConfig),
                    $commandView,
                    DeploymentStatus::PHASE_PRE_FETCH
                );
                $this->createdNewRepo = true;
            } else {
                throw new BadRequestException(new RepoNotExists(), $this->logger);
            }
        }
        chdir($repoDirectory);
    }

    private function getFetchCommands(): array {
        if ($this->getPostFetchCommands()) {
            return $this->builtInCommands();
        }
        return $this->getCustomCommands() ?? $this->builtInCommands();
    }

    private function getPostFetchCommands(): array {
        $deployConfig = $this->deployConfigReader->fetchRepoConfig($this->request->getQueryParam(Request::REPO_QUERY_PARAM));
        $commands = $deployConfig
            ? $deployConfig->postFetchCommands()
            : [];
        return empty($commands) ? [] : array_map([$this->customCommands, 'hydratePlaceHolders'], $commands);
    }

    private function getPreFetchCommands(): array {
        $deployConfig = $this->deployConfigReader->fetchRepoConfig($this->request->getQueryParam(Request::REPO_QUERY_PARAM));
        $commands = $deployConfig
            ? $deployConfig->preFetchCommands()
            : [];
        return empty($commands) ? [] : array_map([$this->customCommands, 'hydratePlaceHolders'], $commands);
    }

    private function builtInCommands(): array {
        $repoPath = $this->configReader->get(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        $repoDir = escapeshellarg(realpath($repoPath) ?: $repoPath);
        $repoGitDir = escapeshellarg($this->buildDeployGitDir(realpath($repoPath) ?: $repoPath));
        $transportConfig = $this->resolveGitTransportConfig();
        $gitCommandPrefix = $this->buildGitCommandPrefix($transportConfig, $repoDir);
        $repoGitCommandPrefix = $gitCommandPrefix . ' --git-dir=' . $repoGitDir . ' --work-tree=' . $repoDir;
        $remoteUrl = $transportConfig['template_uri'] ?? '$(git config --get remote.origin.url)';
        return [
            'echo $PWD',
            'whoami',
            'sudo chown -R www-data:www-data ' . $repoDir,
            'mkdir -p ' . $repoGitDir,
            $repoGitCommandPrefix . ' init',
            $repoGitCommandPrefix . ' remote set-url origin "' . $remoteUrl . '"' . "\n"
                . 'if [ $? -ne 0 ]; then' . "\n"
                . '  ' . $repoGitCommandPrefix . ' remote add origin "' . $remoteUrl . '"' . "\n"
                . 'fi',
            $repoGitCommandPrefix . ' fetch --no-write-fetch-head origin main',
            'git --git-dir=' . $repoGitDir . ' --work-tree=' . $repoDir . ' reset --hard "origin/main"',
        ];
    }

    private function cloneRepoCommands(string $repoDirectory, ?array $transportConfig = null): array {
        if (!$this->configReader->get(ConfigReader::ENABLE_CLONE)) {
            throw new BadRequestException(new RepoNotExists(), $this->logger);
        }
        $transportConfig = $transportConfig ?? $this->resolveGitTransportConfig();
        $repoCloneUri = $transportConfig['template_uri'] ?? $this->configReader->resolveRepoTemplateUri(
            $this->request->getQueryParam(Request::REPO_QUERY_PARAM),
            $this->request->getQueryParam(Request::CLONE_PATH_QUERY_PARAM)
        );
        if (!$repoCloneUri) {
            throw new BadRequestException(new RepoNotExists(), $this->logger);
        }

        if (!$transportConfig) {
            $transportConfig = [
                'strategy' => preg_match('/^https?:\/\//i', $repoCloneUri) === 1 ? 'https' : 'ssh',
                'template_uri' => $repoCloneUri,
            ];
        }

        $repoDirArg = escapeshellarg($repoDirectory);
        $gitCommandPrefix = $this->buildGitCommandPrefix($transportConfig, $repoDirArg);
        return [
            'echo $PWD',
            $gitCommandPrefix . " clone '$repoCloneUri' " . $repoDirArg,
        ];
    }

    private function buildGitCommandPrefix(?array $transportConfig, string $repoDir): string {
        if (($transportConfig['strategy'] ?? 'ssh') === 'https') {
            $credentialHelper = $this->buildHttpsCredentialHelperArg($transportConfig);
            return $credentialHelper !== ''
                ? 'git ' . $credentialHelper
                : 'git';
        }

        $sshKey = $this->configReader->get(ConfigReader::SSH_KEYS_PATH)
            . '/'
            . $this->request->getQueryParam(Request::KEY_QUERY_PARAM);
        return 'GIT_SSH_COMMAND="ssh -i ' . $sshKey . '" git';
    }

    private function buildHttpsCredentialHelperArg(array $transportConfig): string {
        $credentialsFile = $transportConfig['credentials_file'] ?? '';
        if ($credentialsFile !== '') {
            return '-c ' . escapeshellarg('credential.helper=store --file=' . $credentialsFile);
        }

        $credentials = $transportConfig['credentials'] ?? [];
        $username = null;
        $token = null;
        if (is_array($credentials)) {
            $username = $credentials['username'] ?? $credentials['user'] ?? null;
            $token = $credentials['token'] ?? $credentials['password'] ?? null;
        }
        $username = $username ?: ($transportConfig['credentials_username'] ?? 'x-access-token');
        $token = $token ?: ($transportConfig['credentials_token'] ?? '');
        if (!is_string($token) || $token === '') {
            return '';
        }

        $host = parse_url($transportConfig['template_uri'] ?? '', PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        $credentialUrl = sprintf(
            'https://%s:%s@%s',
            rawurlencode((string) $username),
            rawurlencode($token),
            $host
        );
        $tempFile = tempnam(sys_get_temp_dir(), 'git-autodeploy-');
        if ($tempFile === false) {
            return '';
        }

        file_put_contents($tempFile, $credentialUrl . PHP_EOL);
        chmod($tempFile, 0600);
        register_shutdown_function(static function () use ($tempFile): void {
            @unlink($tempFile);
        });

        return '-c ' . escapeshellarg('credential.helper=store --file=' . $tempFile);
    }

    private function resolveGitTransportConfig(): ?array {
        $repoName = $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        $queryParams = $this->request->getQueryParamsAll();
        $clonePath = array_key_exists(Request::CLONE_PATH_QUERY_PARAM, $queryParams)
            ? $this->request->getQueryParam(Request::CLONE_PATH_QUERY_PARAM)
            : '';
        $transportConfig = $this->configReader->resolveRepoTransportConfig($repoName, $clonePath);
        $repoConfig = $this->deployConfigReader->fetchRepoConfig($repoName);
        if ($repoConfig && method_exists($repoConfig, 'gitTransport')) {
            $repoTransport = $repoConfig->gitTransport();
            if (is_array($repoTransport) && !empty($repoTransport)) {
                $transportConfig = $transportConfig
                    ? array_replace_recursive($transportConfig, $repoTransport)
                    : $repoTransport;
                $transportConfig = $this->configReader->normalizeRepoTransportConfig($transportConfig, $repoName);
            }
        }

        return $transportConfig;
    }

    private function buildDeployGitDir(string $repoPath): string {
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'git-autodeploy-'
            . sha1($repoPath . '|' . $this->deployCommitSha);
    }

    private function getCustomCommands(): ?array {
        return $this->customCommands->get();
    }

    private function assertRepoAndKey(string $repo, string $key): void {
        if (!$repo || !$key) {
            throw new BadRequestException(new MissingRepoOrKey(), $this->logger);
        }
    }
}
