<?php

use Mariano\GitAutoDeploy\ContainerProvider;

require __DIR__ . '/../Autoloader.php';

Autoloader::load();

$container = (new ContainerProvider())->provide();

$app = $container->get(Mariano\GitAutoDeploy\Hamster::class);

$app->run();
