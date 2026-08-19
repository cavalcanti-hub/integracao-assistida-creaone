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
$payload = BrowserBridgeRequest::readJson((int) ($config['max_payload_bytes'] ?? 1_048_576));

try {
    $capture = (new BrowserImportValidator())->validate($payload);
    $store = new BrowserBridgeStore();
    $record = $store->saveImport($capture);
    if (BrowserBridgeRequest::isExtensionOrigin($origin)) {
        $store->rememberExtensionOrigin($origin);
    }
    $store->touchBridge(null, $origin);
    (new SafeLogger())->write('browser_import', [
        'request_id' => requestId(),
        'art_number' => $capture['art']['numero'],
        'works_count' => count($capture['obras']),
        'activities_count' => count($capture['atividades']),
        'result' => 'imported',
    ]);
    jsonResponse([
        'ok' => true,
        'message' => 'ART recebida com sucesso.',
        'import_id' => $record['import_id'],
        'art_number' => $capture['art']['numero'],
    ]);
} catch (InvalidArgumentException $exception) {
    $status = $exception->getMessage() === 'Dado de sessão não permitido.' ? 400 : 422;
    (new SafeLogger())->write('browser_import', [
        'request_id' => requestId(),
        'result' => 'rejected',
        'error' => $exception->getMessage(),
    ]);
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], $status);
} catch (Throwable) {
    (new SafeLogger())->write('browser_import', [
        'request_id' => requestId(),
        'result' => 'error',
        'error' => 'Falha ao armazenar a captura local.',
    ]);
    jsonResponse(['ok' => false, 'message' => 'Não foi possível armazenar a captura local.'], 500);
}
