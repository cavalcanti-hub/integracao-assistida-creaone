<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse(['ok' => false, 'message' => 'Método não permitido.'], 405);
}
$configFile = ATLANTICA_ROOT . '/config/browser_bridge.php';
if (!is_file($configFile)) {
    jsonResponse(['ok' => false, 'message' => 'Ponte local não configurada.'], 503);
}
$config = require $configFile;
$status = (new BrowserBridgeStore())->bridgeStatus();
header('Cache-Control: no-store, max-age=0');
jsonResponse([
    'ok' => true,
    'connection_code' => (string) ($config['connection_code'] ?? ''),
    'base_url' => 'http://localhost/sistema_atlantica',
    'connected' => $status['connected'],
    'last_seen' => $status['last_seen'],
    'heartbeat_age_seconds' => $status['age_seconds'],
    'heartbeat_max_age_seconds' => BrowserBridgeStore::HEARTBEAT_MAX_AGE_SECONDS,
]);
