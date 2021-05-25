<?php

namespace GithubAutoDeploy;

use Exception;
use GithubAutoDeploy\Request;
use GithubAutoDeploy\views\Footer;
use GithubAutoDeploy\views\Header;
use GithubAutoDeploy\views\MissingRepoOrKey;
use GithubAutoDeploy\exceptions\BadRequestException;
use GithubAutoDeploy\exceptions\BaseException;
use GithubAutoDeploy\views\UnknownError;

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
        } catch (Exception $e) {
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
            $this->configReader->getKey('IPsAllowList'),
            $this->request->getHeaders(),
            $this->request->getRemoteAddress()
        );
        $escapedRepo = $this->request->getQueryParam('repo');
        $escapedKey = $this->request->getQueryParam('key');
        $this->assertRepoAndKey($escapedRepo, $escapedKey);
        flush();
        $this->updateRepository($escapedRepo, $escapedKey);
    }

    private function updateRepository(string $escapedRepo, string $escapedKey) {
        chdir(
            $this->configReader->getKey('ReposBasePath')
            . DIRECTORY_SEPARATOR
            . $escapedRepo
        );

        // Actually run the update

        $output = "\n";

        $log = "####### ".date('Y-m-d H:i:s'). " #######\n";

        foreach($this->getCommands($escapedKey) AS $command){
            // Run it
            $tmp = shell_exec("$command 2>&1");
            // Output
            $output .= "<span style=\"color: #6BE234;\">\$</span> <span style=\"color: #729FCF;\">{$command}\n</span>";
            $output .= htmlentities(trim($tmp)) . "\n";

            $log  .= "\$ $command\n".trim($tmp)."\n";
        }

        $log .= "\n";

        file_put_contents (__DIR__ . '/deploy-log.log', $log, FILE_APPEND);

        echo $output;
    }

    private function getCommands(string $escapedKey): array {
        return array(
            'echo $PWD',
            'whoami',
            'GIT_SSH_COMMAND="ssh -i ' . $this->configReader->getKey('SSHKeysPath') . '/' . $escapedKey . '" git pull',
            'git status',
            'git submodule sync',
            'git submodule update',
            'git submodule status',
        //    'test -e /usr/share/update-notifier/notify-reboot-required && echo "system restart required"',
        );
    }

    private function assertRepoAndKey(string $repo, string $key) {
        if (!$repo || !$key) {
            throw new BadRequestException(
                new MissingRepoOrKey()
            );
        }
    }
}
