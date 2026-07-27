<?php

use Mariano\GitAutoDeploy\ContainerProvider;
use Mariano\GitAutoDeploy\DeploymentStatus;
use Mariano\GitAutoDeploy\PublicDeploymentDiagnostics;

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
            'sanitized-deployment-diagnostics-v1',
            'github-actions-run-auth-v1',
        ],
    ], JSON_UNESCAPED_SLASHES);
} elseif ($requestPath === '/deployment-diagnostics') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $runId = isset($_GET['run_id']) && is_string($_GET['run_id']) ? trim($_GET['run_id']) : '';
    if ($runId === '' || preg_match('/^[A-Za-z0-9._:-]+$/', $runId) !== 1) {
        http_response_code(400);
        echo json_encode(['error' => 'invalid_run_id'], JSON_UNESCAPED_SLASHES);
        return;
    }
    $status = DeploymentStatus::load($runId);
    if (!$status || !$status->exists()) {
        http_response_code(404);
        echo json_encode(['error' => 'deployment_status_not_found'], JSON_UNESCAPED_SLASHES);
        return;
    }
    echo json_encode([
        'run_id' => $runId,
        'diagnostics' => PublicDeploymentDiagnostics::fromStatus($status->get()),
    ], JSON_UNESCAPED_SLASHES);
} else {
    $app = $container->get(Mariano\GitAutoDeploy\Hamster::class);
    $app->run();
}

function runSelfUpdate() {
    $command = escapeshellcmd(__DIR__ . '/../install.sh --self-update');
    return shell_exec($command);
}
