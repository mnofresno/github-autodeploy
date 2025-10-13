<?php

use Mariano\GitAutoDeploy\ContainerProvider;

require __DIR__ . '/../Autoloader.php';

Autoloader::load();

$container = (new ContainerProvider())->provide();

// Parse the URI to get just the path (without query string)
$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// Remove trailing slash for consistent comparison (except for root)
$requestPath = $requestPath !== '/' ? rtrim($requestPath, '/') : $requestPath;

if ($requestPath === '/self-update') {
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
