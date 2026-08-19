<?php

declare(strict_types=1);

final class CreaOneTlsException extends RuntimeException
{
    public function __construct(
        public readonly int $curlErrno,
        public readonly string $curlError,
        public readonly int $sslVerifyResult,
    ) {
        parent::__construct('Falha SSL ao estabelecer conexão HTTPS com CREA.');
    }
}

