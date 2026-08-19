<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
BrowserBridgeRequest::prepareLocalOnly();
requirePost();

$configFile = ATLANTICA_ROOT . '/config/browser_bridge.php';
if (!is_file($configFile)) {
    jsonResponse(['ok' => false, 'message' => 'Ponte local não configurada.'], 503);
}
$config = require $configFile;
BrowserBridgeRequest::validateToken(is_array($config) ? $config : []);
$payload = BrowserBridgeRequest::readJson(4096);

try {
    $art = (new BrowserCommandValidator())->openArt($payload);
    $command = (new BrowserBridgeStore())->saveCommand($art);
    (new SafeLogger())->write('browser_command', [
        'request_id' => requestId(),
        'art_number' => $art,
        'result' => 'created',
    ]);
    jsonResponse([
        'ok' => true,
        'message' => 'Comando criado para a extensão.',
        'command_id' => $command['command_id'],
    ]);
} catch (InvalidArgumentException $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable) {
    jsonResponse(['ok' => false, 'message' => 'Não foi possível enviar a consulta para a extensão.'], 500);
}
