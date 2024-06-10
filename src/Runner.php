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

    function __construct(
        Request $request,
        Response &$response,
        ConfigReader $configReader,
        Logger $logger
    ) {
        $this->request = $request;
        $this->response = $response;
        $this->configReader = $configReader;
        $this->logger = $logger;
    }

    function runForCli(): void {
        $this->doRun(new CliSecurity());
    }

    function run(): void {
        $this->doRun(new Security($this->logger));
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
            $view = new UnknownError($e->getMessage());
            $this->response->addViewToBody($view);
            $this->response->setStatusCode(500);
        } finally {
            $this->response->addViewToBody(new Footer($this->response->getRunId()));
        }
    }

    private function doRunWithSecurity(ISecurity $security): void {
        $security->setParams(
            $this->configReader->get(ConfigReader::IPS_ALLOWLIST),
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
            $whoami = $this->whoami();
            $commandView->add($command, $commandOutput, $whoami);
            $log []= [
                'command' => $command,
                'output' => $commandOutput,
                'running_user' => $whoami,
                'exitCode' => $exitCode
            ];
        }
        $commandsCount = count($commands);
        $this->logger->info("Ran {$commandsCount} commands", ['updatingCommands' => $log]);
        $this->response->addViewToBody($commandView);
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
            $this->request,
            $this->logger
        ))->get();
    }

    private function assertRepoAndKey(string $repo, string $key): void {
        if (!$repo || !$key) {
            throw new BadRequestException(new MissingRepoOrKey(), $this->logger);
        }
    }
}
