<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
BrowserBridgeRequest::prepareCors();
requirePost();

$configFile = ATLANTICA_ROOT . '/config/browser_bridge.php';
if (!is_file($configFile)) {
    jsonResponse(['ok' => false, 'message' => 'Ponte local não configurada.'], 503);
}
$config = require $configFile;
BrowserBridgeRequest::validateToken(is_array($config) ? $config : []);
(new BrowserBridgeStore())->touchBridge(null, BrowserBridgeRequest::currentOrigin());
jsonResponse(['ok' => true, 'message' => 'Extensão conectada.']);
