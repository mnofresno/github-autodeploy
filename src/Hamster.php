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

class Hamster {
    private $request;
    private $configReader;

    function __construct() {
        $this->request = Request::fromHttp();
        $this->configReader = new ConfigReader();
    }

    function run() {
        Header::show();
        try {
            $this->doRun();
        } catch (BaseException $e) {
            $e->render();
        } catch (Throwable $e) {
            $view = new UnknownError($e->getMessage());
            $view->render();
        }
        finally {
            Footer::show();
            exit;
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

        $commandView->render();
    }

    private function changeDirToRepoPath() {
        chdir(
            $this->configReader->getKey(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $this->request->getQueryParam('repo')
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
        $customCommands = $this->configReader->getKey(ConfigReader::CUSTOM_UPDATE_COMMANDS);
        return $customCommands
            ? array_map(function (string $command) {
                $hydratedCommand = str_replace(
                    '$' . Request::REPO_QUERY_PARAM,
                    $this->request->getQueryParam(Request::REPO_QUERY_PARAM),
                    $command
                );
                $hydratedCommand = str_replace(
                    '$' . Request::KEY_QUERY_PARAM,
                    $this->request->getQueryParam(Request::KEY_QUERY_PARAM),
                    $hydratedCommand
                );
                $hydratedCommand = str_replace(
                    '$' . ConfigReader::REPOS_BASE_PATH,
                    $this->configReader->getKey(ConfigReader::REPOS_BASE_PATH),
                    $hydratedCommand
                );
                $hydratedCommand = str_replace(
                    '$' . ConfigReader::SSH_KEYS_PATH,
                    $this->configReader->getKey(ConfigReader::SSH_KEYS_PATH),
                    $hydratedCommand
                );
                return $hydratedCommand;
            }, $customCommands)
            : null;
    }

    private function assertRepoAndKey(string $repo, string $key) {
        if (!$repo || !$key) {
            throw new BadRequestException(
                new MissingRepoOrKey()
            );
        }
    }
}
