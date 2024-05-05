<?php

namespace Mariano\GitAutoDeploy;

use Mariano\GitAutoDeploy\Request;
use Mariano\GitAutoDeploy\views\Footer;
use Mariano\GitAutoDeploy\views\Header;
use Mariano\GitAutoDeploy\views\MissingRepoOrKey;
use Mariano\GitAutoDeploy\exceptions\BadRequestException;
use Mariano\GitAutoDeploy\exceptions\BaseException;
use Mariano\GitAutoDeploy\views\Command;
use Mariano\GitAutoDeploy\views\UnknownError;
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

    function run(): void {
        $this->response->addToBody(Header::show());
        try {
            if ($this->request->getQueryParam('run_in_background') === 'true') {
                $this->response->setStatusCode(201);
                $this->finishRequest();
            }
            $this->doRun();
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

    private function finishRequest(): void {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            Logger::log(['fatcgi_finish_request function not found']);
        }
    }

    private function doRun(): void {
        Security::assert(
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
            throw new BadRequestException(
                new MissingRepoOrKey()
            );
        }
    }
}
