<?php

declare(strict_types=1);

final class BrowserBridgeRequest
{
    public static function prepareLocalOnly(): void
    {
        $origin = self::currentOrigin();
        if ($origin !== null && !self::isLocalOrigin($origin)) {
            self::rejectOrigin('local_origin_only', 'local');
        }
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function prepareCors(): void
    {
        $origin = self::currentOrigin();
        if ($origin === null || self::isLocalOrigin($origin)) {
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
                http_response_code(204);
                exit;
            }
            return;
        }

        if (!self::isExtensionOrigin($origin)) {
            self::rejectOrigin('external_origin_blocked', 'external');
        }

        $status = (new BrowserBridgeStore())->bridgeStatus();
        $pinnedOrigin = is_string($status['extension_origin'] ?? null) ? (string) $status['extension_origin'] : null;
        if ($pinnedOrigin !== null && $pinnedOrigin !== '' && $pinnedOrigin !== $origin) {
            self::rejectOrigin('extension_origin_mismatch', 'extension');
        }

        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, X-Atlantica-Bridge-Token');
        header('Access-Control-Max-Age: 600');

        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function validateToken(array $config): void
    {
        $provided = strtoupper(trim((string) ($_SERVER['HTTP_X_ATLANTICA_BRIDGE_TOKEN'] ?? '')));
        $expected = strtoupper(trim((string) ($config['connection_code'] ?? '')));
        if ($provided === '' || $expected === '' || !hash_equals($expected, $provided)) {
            jsonResponse(['ok' => false, 'message' => 'Código de conexão inválido.'], 401);
        }
    }

    public static function readJson(int $maxBytes): array
    {
        $contentType = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
        if ($contentType !== 'application/json') {
            jsonResponse(['ok' => false, 'message' => 'Content-Type deve ser application/json.'], 415);
        }
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($length > $maxBytes) {
            jsonResponse(['ok' => false, 'message' => 'Conteúdo excede o limite permitido.'], 413);
        }
        $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
        if (!is_string($raw) || $raw === '' || strlen($raw) > $maxBytes) {
            jsonResponse(['ok' => false, 'message' => 'JSON vazio ou acima do limite permitido.'], 400);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            jsonResponse(['ok' => false, 'message' => 'JSON inválido ou UTF-8 malformado.'], 400);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            jsonResponse(['ok' => false, 'message' => 'Estrutura JSON inválida.'], 400);
        }
        return $decoded;
    }

    private static function isLoopback(): bool
    {
        return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
    }

    public static function currentOrigin(): ?string
    {
        $origin = trim((string) ($_SERVER['HTTP_ORIGIN'] ?? ''));
        return $origin === '' ? null : $origin;
    }

    public static function isLocalOrigin(?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }
        $parts = parse_url($origin);
        return is_array($parts)
            && ($parts['scheme'] ?? '') === 'http'
            && ($parts['host'] ?? '') === 'localhost'
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['path'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && (!isset($parts['port']) || (int) $parts['port'] === 80);
    }

    public static function isExtensionOrigin(?string $origin): bool
    {
        return is_string($origin) && preg_match('#^chrome-extension://[a-p]{32}$#', $origin) === 1;
    }

    private static function rejectOrigin(string $error, string $originKind): never
    {
        (new SafeLogger())->write('bridge_origin_rejected', [
            'endpoint' => (string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['REQUEST_URI'] ?? ''),
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
            'origin' => self::currentOrigin() ?? '',
            'origin_kind' => $originKind,
            'error' => $error,
            'result' => 'rejected',
        ]);
        jsonResponse(['ok' => false, 'message' => 'Origem não permitida.'], 403);
    }
}
