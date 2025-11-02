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

        $this->runCollectionOfCommands($preFetchCommands, $commandView, DeploymentStatus::PHASE_PRE_FETCH);
        $this->runCollectionOfCommands($fetchCommands, $commandView, DeploymentStatus::PHASE_FETCH);
        $this->runCollectionOfCommands($postFetchCommands, $commandView, DeploymentStatus::PHASE_POST_FETCH);

        $commandsCount = count($preFetchCommands) + count($fetchCommands) + count($postFetchCommands);
        $this->logger->info("Ran {$commandsCount} commands", ['updating_commands' => $this->runningLog]);
        $this->deploymentStatus->markSuccess();
        $this->response->addViewToBody($commandView);
    }

    private function runCollectionOfCommands(array $commands, Command $view, string $phase) {
        if (empty($commands)) {
            return;
        }

        $this->deploymentStatus->startPhase($phase);
        $this->logger->info("Starting phase: {$phase}", ['phase' => $phase, 'commands_count' => count($commands)]);

        $stepId = count($this->runningLog);
        foreach ($commands as $command) {
            $this->deploymentStatus->startStep($command, $phase);
            $this->logger->debug("Running command: {$command}", ['phase' => $phase, 'step_id' => $stepId]);

            $afterRan = $this->executer->run($command);
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

    private function whoami(): string {
        return exec('whoami');
    }

    private function changeDirToRepoPath(Command $commandView): void {
        $repoDirectory = $this->configReader->get(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $this->request->getQueryParam(Request::REPO_QUERY_PARAM);
        if (!is_dir($repoDirectory)) {
            if ($this->createRepoIfNotExists) {
                $parentDirectory = dirname($repoDirectory);
                chdir($parentDirectory);
                $this->runCollectionOfCommands(
                    $this->cloneRepoCommands($repoDirectory),
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

    private function cloneRepoCommands(string $repoDirectory): array {
        if (!$this->configReader->get(ConfigReader::ENABLE_CLONE)) {
            throw new BadRequestException(new RepoNotExists(), $this->logger);
        }
        $repoKey = escapeshellarg($this->request->getQueryParam(Request::REPO_QUERY_PARAM));
        $queryParamClonePath = $this->request->getQueryParam(Request::CLONE_PATH_QUERY_PARAM);
        $reposTemplatePath = $queryParamClonePath === ''
            ? $this->configReader->get(ConfigReader::REPOS_TEMPLATE_URI)
            : $queryParamClonePath;
        $repoCloneUri = str_replace(ConfigReader::REPO_KEY_TEMPLATE_PLACEHOLDER, $repoKey, $reposTemplatePath);
        return [
            'echo $PWD',
            'GIT_SSH_COMMAND="ssh -i '
                . $this->configReader->get(ConfigReader::SSH_KEYS_PATH)
                . '/'
                . $this->request->getQueryParam(Request::KEY_QUERY_PARAM)
                . "\" git clone '$repoCloneUri'"
                . " '$repoDirectory'",
        ];
    }

    private function builtInCommands(): array {
        return [
            'echo $PWD',
            'whoami',
            'GIT_SSH_COMMAND="ssh -i '
                . $this->configReader->get(ConfigReader::SSH_KEYS_PATH)
                . '/'
                . $this->request->getQueryParam(Request::KEY_QUERY_PARAM)
                . '" git fetch origin',
            'git reset --hard origin/$(git symbolic-ref --short HEAD)',
        ];
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
