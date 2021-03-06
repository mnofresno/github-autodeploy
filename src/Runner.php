<?php

namespace GitAutoDeploy;

use GitAutoDeploy\Request;
use GitAutoDeploy\views\Footer;
use GitAutoDeploy\views\Header;
use GitAutoDeploy\views\MissingRepoOrKey;
use GitAutoDeploy\exceptions\BadRequestException;
use GitAutoDeploy\exceptions\BaseException;
use GitAutoDeploy\views\Command;
use GitAutoDeploy\views\UnknownError;
use Throwable;

class Runner {
    private $request;
    private $response;
    private $configReader;

    function __construct(Request $request, Response &$response, ConfigReader $configReader) {
        $this->request = $request;
        $this->response = $response;
        $this->configReader = $configReader;
    }

    function run() {
        $this->response->addToBody(Header::show());
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
        }
        finally {
            $this->response->addToBody(Footer::show());
        }
    }

    private function doRun() {
        Security::assert(
            $this->configReader->getKey(ConfigReader::IPS_ALLOWLIST),
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

    private function updateRepository(array $commands) {
        flush();
        // Actually run the update
        $log = [];
        $commandView = new Command();
        foreach($commands AS $command){
            // Run it
            $commandOutput = [];
            $exitCode = 0;
            exec("$command 2>&1", $commandOutput, $exitCode);
            // Output
            $commandView->add($command, $commandOutput);
            $log []= ['command' => $command, 'output' => $commandOutput, 'exitCode' => $exitCode];
        }
        Logger::log(['updatingCommands' => $log]);

        $this->response->addToBody($commandView->render());
    }

    private function changeDirToRepoPath() {
        chdir(
            $this->configReader->getKey(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $this->request->getQueryParam(Request::REPO_QUERY_PARAM)
        );
    }

    private function getCommands(): array {
        return $this->getCustomCommands() ?? [
            'echo $PWD',
            'whoami',
            'GIT_SSH_COMMAND="ssh -i '
                . $this->configReader->getKey(ConfigReader::SSH_KEYS_PATH)
                . '/'
                . $this->request->getQueryParam(Request::KEY_QUERY_PARAM)
                . '" git pull',
            'git status',
            'git submodule sync',
            'git submodule update',
            'git submodule status',
        ];
    }

    private function getCustomCommands() {
        return (new CustomCommands(
            $this->configReader,
            $this->request
        ))->get();
    }

    private function assertRepoAndKey(string $repo, string $key) {
        if (!$repo || !$key) {
            throw new BadRequestException(
                new MissingRepoOrKey()
            );
        }
    }
}
