<?php

use Mariano\GitAutoDeploy\ContainerProvider;

require __DIR__ . '/../Autoloader.php';

Autoloader::load();

$container = (new ContainerProvider())->provide();

if ($_SERVER['REQUEST_URI'] === '/self-update') {
    $output = runSelfUpdate();
    echo nl2br(htmlspecialchars($output, ENT_QUOTES, 'UTF-8'));
} else {
    $app = $container->get(Mariano\GitAutoDeploy\Hamster::class);
    $app->run();
}

function runSelfUpdate() {
    $command = escapeshellcmd(__DIR__ . '/../install.sh --self-update');
    return shell_exec($command);
}
