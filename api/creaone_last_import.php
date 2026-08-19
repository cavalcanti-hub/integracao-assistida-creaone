<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse(['ok' => false, 'message' => 'Método não permitido.'], 405);
}
header('Cache-Control: no-store, max-age=0');
$record = (new BrowserBridgeStore())->lastImport();
jsonResponse([
    'ok' => true,
    'has_import' => $record !== null,
    'import' => $record,
]);

