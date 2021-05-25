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
        $escapedRepo = $this->request->getQueryParam('repo');
        $escapedKey = $this->request->getQueryParam('key');
        $this->assertRepoAndKey($escapedRepo, $escapedKey);
        $this->changeDirToRepoPath($escapedRepo);
        $this->updateRepository(
            $this->getCommands($escapedKey)
        );
    }

    private function updateRepository(array $commands) {
        flush();
        // Actually run the update
        $log = [];
        $commandView = new Command();
        foreach($commands AS $command){
            // Run it
            $commandOutput = shell_exec("$command 2>&1");
            // Output
            $commandView->add($command, $commandOutput);
            $log []= ['command' => $command, 'output' => $commandOutput];
        }
        Logger::log(['updatingCommands' => $log]);

        $commandView->render();
    }

    private function changeDirToRepoPath(string $escapedRepo) {
        chdir(
            $this->configReader->getKey(ConfigReader::REPOS_BASE_PATH)
            . DIRECTORY_SEPARATOR
            . $escapedRepo
        );
    }

    private function getCommands(string $escapedKey): array {
        return [
            'echo $PWD',
            'whoami',
            'GIT_SSH_COMMAND="ssh -i '
                . $this->configReader->getKey(ConfigReader::SSH_KEYS_PATH)
                . '/'
                . $escapedKey
                . '" git pull',
            'git status',
            'git submodule sync',
            'git submodule update',
            'git submodule status',
        //    'test -e /usr/share/update-notifier/notify-reboot-required && echo "system restart required"',
        ];
    }

    private function assertRepoAndKey(string $repo, string $key) {
        if (!$repo || !$key) {
            throw new BadRequestException(
                new MissingRepoOrKey()
            );
        }
    }
}
