<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
requirePost();
verifyCsrf();

try {
    $session = new CreaOneSession();
    $request = $session->importedRequest();
    $result = (new ReplayService($session))->executeArt($request, 'exact_replay');
    $result['session'] = $session->summary();
    jsonResponse($result);
} catch (RuntimeException $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable) {
    jsonResponse(['ok' => false, 'message' => 'Falha interna ao executar o Replay Exato.'], 500);
}

