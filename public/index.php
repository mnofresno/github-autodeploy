<?php

require __DIR__ . '/../Autoloader.php';

Autoloader::load();

$app = new Mariano\GitAutoDeploy\Hamster();

$app->run();
