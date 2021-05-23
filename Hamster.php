<?php

namespace GithubAutoDeploy;

use GithubAutoDeploy\Request as Request;
use GithubAutoDeploy\views\Footer;
use GithubAutoDeploy\views\Main;
use GithubAutoDeploy\views\MissingRepoOrKey;

class Hamster {
    private $request;
    private $configReader;

    function __construct() {
        $this->request = Request::fromHttp();
        $this->configReader = new ConfigReader();
    }

    function run() {
        Main::render();
        Security::assert(
            $this->configReader->getKey('IPsAllowList'),
            $this->request->getHeaders(),
            $this->request->getRemoteAddress()
        );
        $escapedRepo = $this->request->getQueryParam('repo');
        $escapedKey = $this->request->getQueryParam('key');
        if (!$escapedRepo || !$escapedKey) {
            MissingRepoOrKey::render();
            exit;
        }
        flush();
        $this->doRun($escapedRepo, $escapedKey);
        Footer::render();
    }

    private function doRun(string $escapedRepo, string $escapedKey) {
        chdir(
            $this->configReader->getKey('ReposBasePath')
            . DIRECTORY_SEPARATOR
            . $escapedRepo
        );

        // Actually run the update

        $commands = array(
            'echo $PWD',
            'whoami',
            'GIT_SSH_COMMAND="ssh -i ' . $this->configReader->getKey('SSHKeysPath') . '/' . $escapedKey . '" git pull',
            'git status',
            'git submodule sync',
            'git submodule update',
            'git submodule status',
        //    'test -e /usr/share/update-notifier/notify-reboot-required && echo "system restart required"',
        );

        $output = "\n";

        $log = "####### ".date('Y-m-d H:i:s'). " #######\n";

        foreach($commands AS $command){
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
}
