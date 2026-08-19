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
$origin = BrowserBridgeRequest::currentOrigin();
$store = new BrowserBridgeStore();
if (BrowserBridgeRequest::isExtensionOrigin($origin)) {
    $store->rememberExtensionOrigin($origin);
}
$payload = BrowserBridgeRequest::readJson(1024);

try {
    if ($payload === ['poll' => true]) {
        $command = $store->consumeCommand();
        jsonResponse([
            'ok' => true,
            'has_command' => $command !== null,
            'command' => $command,
            'state' => $command['status'] ?? 'pending',
        ]);
    }

    if (array_key_exists('status', $payload)) {
        $commandId = is_string($payload['status']) ? trim($payload['status']) : '';
        if ($commandId === '') {
            jsonResponse(['ok' => false, 'message' => 'Solicitação de status inválida.'], 400);
        }
        $command = $store->commandStatus($commandId);
        jsonResponse([
            'ok' => true,
            'has_command' => $command !== null,
            'command' => $command,
            'state' => $command['status'] ?? 'missing',
        ]);
    }

    if (array_key_exists('ack', $payload)) {
        $commandId = is_string($payload['ack']) ? trim($payload['ack']) : '';
        if ($commandId === '') {
            jsonResponse(['ok' => false, 'message' => 'Solicitação de confirmação inválida.'], 400);
        }
        $command = $store->acknowledgeCommand($commandId);
        if ($command === null) {
            jsonResponse(['ok' => false, 'message' => 'Comando local não encontrado.'], 404);
        }
        jsonResponse([
            'ok' => true,
            'has_command' => true,
            'command' => $command,
            'state' => $command['status'] ?? 'acknowledged',
        ]);
    }

    jsonResponse(['ok' => false, 'message' => 'Solicitação de comando inválida.'], 400);
} catch (Throwable) {
    jsonResponse(['ok' => false, 'message' => 'Não foi possível consultar os comandos locais.'], 500);
}
