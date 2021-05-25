<?php

require __DIR__ . '/Autoloader.php';

Autoloader::load();

$app = new GithubAutoDeploy\Hamster();

$app->run();
