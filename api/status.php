<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';

$session = new CreaOneSession();
jsonResponse([
    'ok' => true,
    'session' => $session->summary(),
    'csrf' => csrfToken(),
]);

