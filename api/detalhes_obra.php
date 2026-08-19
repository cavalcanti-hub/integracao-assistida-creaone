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
    if (!$summary['viewstate']['present']) {
        throw new RuntimeException('VIEWSTATE ausente.');
    }

    $index = filter_input(INPUT_POST, 'index', FILTER_VALIDATE_INT);
    if ($index === false || $index === null || $index < 0 || $index > 100) {
        throw new InvalidArgumentException('Obra inválida.');
    }
    $request = $session->buildDetailsRequest($index);
    $result = (new ReplayService($session))->executeDetails($request);
    $result['session'] = $session->summary();
    jsonResponse($result);
} catch (InvalidArgumentException | RuntimeException $exception) {
    jsonResponse(['ok' => false, 'message' => $exception->getMessage()], 422);
} catch (Throwable) {
    jsonResponse(['ok' => false, 'message' => 'Falha interna ao carregar os detalhes da obra.'], 500);
}

