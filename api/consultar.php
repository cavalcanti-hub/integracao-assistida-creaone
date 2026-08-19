<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
requirePost();
verifyCsrf();

try {
    $session = new CreaOneSession();
    $summary = $session->summary();
    if (!$summary['configured']) {
        throw new RuntimeException('Sessão CreaOne não configurada.');
    }
    if (!$summary['turnstile']['present']) {
        throw new RuntimeException('Token Turnstile não presente.');
    }
    if (!$summary['viewstate']['present']) {
        throw new RuntimeException('VIEWSTATE ausente.');
    }

    $request = $session->buildControlledRequest(postString('art'));
    $result = (new ReplayService($session))->executeArt($request, 'controlled_query');
    $result['session'] = $session->summary();
    jsonResponse($result);
} catch (InvalidArgumentException | RuntimeException $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable) {
    jsonResponse(['ok' => false, 'message' => 'Falha interna ao consultar a ART.'], 500);
}

