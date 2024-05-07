<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\views\Footer;
use Mariano\GitAutoDeploy\views\Header;
use Mariano\GitAutoDeploy\views\MissingRepoOrKey;
use Mariano\GitAutoDeploy\exceptions\BadRequestException;
use Mariano\GitAutoDeploy\exceptions\BaseException;
use Mariano\GitAutoDeploy\views\Command;
use Mariano\GitAutoDeploy\views\UnknownError;
use Monolog\Logger;
use Throwable;

class Runner {
    private $request;
    private $response;
    private $configReader;
    private $logger;
    private $security;

    function __construct(
        Request $request,
        Response &$response,
        ConfigReader $configReader,
        Logger $logger,
        Security $security
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->configReader = $configReader;
        $this->logger = $logger;
        $this->security = $security;
    }

    function run(): void {
        $this->response->addToBody((new Header())->render());
        try {
            $this->doRun();
            $this->response->setStatusCode(200);
        } catch (BaseException $e) {
            $this->response->addToBody($e->render());
            $this->response->setStatusCode($e->getStatusCode());
        } catch (Throwable $e) {
            $view = new UnknownError($e->getMessage());
            $this->response->addToBody($view->render());
            $this->response->setStatusCode(500);
        } finally {
            $this->response->addToBody(
                (new Footer($this->response->getRunId()))->render()
            );
        }
    }

    private function doRun(): void {
        $this->security->assert(
            $this->configReader->get(ConfigReader::IPS_ALLOWLIST),
            $this->request->getHeaders(),
            $this->request->getRemoteAddress()
        );
        $this->assertRepoAndKey(
            $this->request->getQueryParam(Request::REPO_QUERY_PARAM),
            $this->request->getQueryParam(Request::KEY_QUERY_PARAM)
        );
        $this->changeDirToRepoPath();
        $this->updateRepository(
            $this->getCommands()
        );
    }

    private function updateRepository(array $commands): void {
        flush();
        $log = [];
        $commandView = new Command();
        foreach ($commands AS $command) {
            $commandOutput = [];
            $exitCode = 0;
            exec("$command 2>&1", $commandOutput, $exitCode);
            $commandView->add($command, $commandOutput);
            $log []= ['command' => $command, 'output' => $commandOutput, 'exitCode' => $exitCode];
        }
        $commandsCount = count($commands);
        $this->logger->info("Ran {$commandsCount} commands", ['updatingCommands' => $log]);
        $this->response->addToBody($commandView->render());
    }

    private function changeDirToRepoPath(): void {
        chdir(
            $this->configReader->get(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $this->request->getQueryParam(Request::REPO_QUERY_PARAM)
        );
    }

    private function getCommands(): array {
        return $this->getCustomCommands() ?? $this->builtInCommands();
    }

    private function builtInCommands(): array {
        return [
            'echo $PWD',
            'whoami',
            'GIT_SSH_COMMAND="ssh -i '
                . $this->configReader->get(ConfigReader::SSH_KEYS_PATH)
                . '/'
                . $this->request->getQueryParam(Request::KEY_QUERY_PARAM)
                . '" git pull',
            'git status',
            'git submodule sync',
            'git submodule update',
            'git submodule status',
        ];
    }

    private function getCustomCommands(): ?array {
        return (new CustomCommands(
            $this->configReader,
            $this->request
        ))->get();
    }

    private function assertRepoAndKey(string $repo, string $key): void {
        if (!$repo || !$key) {
            throw new BadRequestException(new MissingRepoOrKey(), $this->logger);
        }
    }
}
