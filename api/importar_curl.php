<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
requirePost();
verifyCsrf();

try {
    $command = postString('curl');
    $importer = new CurlImporter();
    $request = $importer->parse($command);
    $session = new CreaOneSession();
    $session->import($request);
    (new SafeLogger())->write('import', [
        'request_id' => requestId(),
        'result' => 'imported',
    ]);

    jsonResponse([
        'ok' => true,
        'message' => 'Requisição importada com segurança.',
        'import' => $importer->summary($request),
        'session' => $session->summary(),
    ]);
} catch (InvalidArgumentException $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable) {
    jsonResponse(['ok' => false, 'message' => 'Não foi possível importar o cURL informado.'], 500);
}

