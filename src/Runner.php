<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\cli\CliSecurity;
use Mariano\GitAutoDeploy\views\Footer;
use Mariano\GitAutoDeploy\views\Header;
use Mariano\GitAutoDeploy\views\errors\MissingRepoOrKey;
use Mariano\GitAutoDeploy\exceptions\BadRequestException;
use Mariano\GitAutoDeploy\exceptions\BaseException;
use Mariano\GitAutoDeploy\views\Command;
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
        $this->doRun(new Security($this->logger, $this->ipAllowListManager));
    }

    private function doRun(ISecurity $security): void {
        $this->response->addViewToBody(new Header());
        try {
            $this->doRunWithSecurity($security);
            $this->response->setStatusCode($this->createdNewRepo ? 201 : 200);
        } catch (BaseException $e) {
            $this->response->addToBody($e->render());
            $this->response->setStatusCode($e->getStatusCode());
        } catch (Throwable $e) {
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
        $this->assertRepoAndKey(
            $this->request->getQueryParam(Request::REPO_QUERY_PARAM),
            $this->request->getQueryParam(Request::KEY_QUERY_PARAM)
        );
        $commandView = new Command();
        $this->changeDirToRepoPath($commandView);
        $this->updateRepository($commandView);
    }

    private function updateRepository(Command $commandView): void {
        flush();
        $this->runCollectionOfCommands($preFetchCommands = $this->getPreFetchCommands(), $commandView);
        $this->runCollectionOfCommands($fetchCommands = $this->getFetchCommands(), $commandView);
        $this->runCollectionOfCommands($postFetchCommands = $this->getPostFetchCommands(), $commandView);
        $commandsCount = count($preFetchCommands) + count($fetchCommands) + count($postFetchCommands);
        $this->logger->info("Ran {$commandsCount} commands", ['updating_commands' => $this->runningLog]);
        $this->response->addViewToBody($commandView);
    }

    private function runCollectionOfCommands(array $commands, Command $view) {
        foreach ($commands as $command) {
            $view->add($afterRan = $this->executer->run($command));
            $this->runningLog [] = $afterRan->jsonSerialize();
        }
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
                    $commandView
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
        return $deployConfig
            ? $deployConfig->postFetchCommands()
            : [];
    }

    private function getPreFetchCommands(): array {
        $deployConfig = $this->deployConfigReader->fetchRepoConfig($this->request->getQueryParam(Request::REPO_QUERY_PARAM));
        return $deployConfig
            ? $deployConfig->preFetchCommands()
            : [];
    }

    private function cloneRepoCommands(string $repoDirectory): array {
        $repoKey = escapeshellarg($this->request->getQueryParam(Request::REPO_QUERY_PARAM));
        $reposTemplatePath = $this->configReader->get(ConfigReader::REPOS_TEMPLATE_URI);
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
