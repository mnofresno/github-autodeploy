<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\cli\CliSecurity;
use Mariano\GitAutoDeploy\views\Footer;
use Mariano\GitAutoDeploy\views\Header;
use Mariano\GitAutoDeploy\views\errors\MissingRepoOrKey;
use Mariano\GitAutoDeploy\exceptions\BadRequestException;
use Mariano\GitAutoDeploy\exceptions\BaseException;
use Mariano\GitAutoDeploy\views\Command;
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

    public function run(): void {
        $this->doRun(new Security($this->logger, $this->ipAllowListManager));
    }

    private function doRun(ISecurity $security): void {
        $this->response->addViewToBody(new Header());
        try {
            $this->doRunWithSecurity($security);
            $this->response->setStatusCode(200);
        } catch (BaseException $e) {
            $this->response->addToBody($e->render());
            $this->response->setStatusCode($e->getStatusCode());
        } catch (Throwable $e) {
            file_put_contents('/tmp/logcom', $e->getMessage(), FILE_APPEND);
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
        $this->changeDirToRepoPath();
        $this->updateRepository();
    }

    private function updateRepository(): void {
        flush();
        $this->runningLog = [];
        $commandView = new Command();
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

    private function changeDirToRepoPath(): void {
        chdir(
            $this->configReader->get(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $this->request->getQueryParam(Request::REPO_QUERY_PARAM)
        );
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
