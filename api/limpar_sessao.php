<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
requirePost();
verifyCsrf();

(new CreaOneSession())->clear();
(new SafeLogger())->write('clear_session', ['request_id' => requestId(), 'result' => 'cleared']);
jsonResponse(['ok' => true, 'message' => 'Dados da sessão CreaOne removidos deste navegador.']);

