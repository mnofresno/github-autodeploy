<?php

use Mariano\GitAutoDeploy\ContainerProvider;

require __DIR__ . '/../Autoloader.php';

Autoloader::load();

$container = (new ContainerProvider())->provide();

$requestPath = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestPath = $requestPath !== '/' ? rtrim($requestPath, '/') : $requestPath;

if ($requestPath === '/self-update') {
    $output = runSelfUpdate();
    echo nl2br(htmlspecialchars($output, ENT_QUOTES, 'UTF-8'));
} elseif ($requestPath === '/controller-capabilities') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'service' => 'github-autodeploy',
        'capabilities' => [
            'repo-owner-bootstrap-v1',
            'sanitized-deployment-status-v1',
        ],
    ], JSON_UNESCAPED_SLASHES);
} else {
    $app = $container->get(Mariano\GitAutoDeploy\Hamster::class);
    $app->run();
}

function runSelfUpdate() {
    $command = escapeshellcmd(__DIR__ . '/../install.sh --self-update');
    return shell_exec($command);
}
