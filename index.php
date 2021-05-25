<?php

require __DIR__ . '/Autoloader.php';

Autoloader::load();

$app = new GitAutoDeploy\Hamster();

$app->run();
