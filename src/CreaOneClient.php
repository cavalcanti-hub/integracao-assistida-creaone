<?php

declare(strict_types=1);

final class CreaOneClient
{
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT = 35;

    public function send(array $request): array
    {
        if (!extension_loaded('curl')) {
            throw new RuntimeException('A extensão cURL do PHP não está habilitada.');
        }

        $url = (string) ($request['url'] ?? '');
        CurlImporter::assertAllowedUrl($url);
        if (($request['method'] ?? '') !== 'POST') {
            throw new InvalidArgumentException('Somente POST é permitido para o CREA.');
        }

        $responseHeaders = [];
        $headers = $this->buildHeaders($request['headers'] ?? []);
        $cookieLine = $this->buildCookieLine($request['cookies'] ?? []);
        $caBundle = $this->resolveCaBundle();
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Não foi possível iniciar o cliente HTTP.');
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => (string) ($request['body'] ?? ''),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIE => $cookieLine,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TOTAL_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->headerValue($request['headers'] ?? [], 'user-agent') ?? 'Atlantica-CreaOne-Lab/1.0',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                $length = strlen($line);
                $position = strpos($line, ':');
                if ($position !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $value = trim(substr($line, $position + 1));
                    $responseHeaders[$name][] = $value;
                }
                return $length;
            },
        ];
        if ($caBundle !== null) {
            $options[CURLOPT_CAINFO] = $caBundle;
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $error = curl_error($handle);
        $errorNumber = curl_errno($handle);
        $info = curl_getinfo($handle);
        curl_close($handle);

        if ($body === false) {
            $sslVerifyResult = (int) ($info['ssl_verify_result'] ?? 0);
            if ($this->isTlsError($errorNumber, $error, $sslVerifyResult)) {
                throw new CreaOneTlsException($errorNumber, $error, $sslVerifyResult);
            }
            throw new RuntimeException('Falha na comunicação com o CREA: ' . ($error !== '' ? $error : 'erro cURL ' . $errorNumber));
        }

        return [
            'http_status' => (int) ($info['http_code'] ?? 0),
            'duration_ms' => (int) round(((float) ($info['total_time'] ?? 0)) * 1000),
            'response_bytes' => strlen($body),
            'content_type' => (string) ($info['content_type'] ?? ''),
            'body' => $body,
            'response_cookies' => $this->extractResponseCookies($responseHeaders['set-cookie'] ?? []),
        ];
    }

    private function buildHeaders(array $source): array
    {
        $blocked = ['host', 'content-length', 'cookie', 'connection', 'accept-encoding'];
        $headers = [];
        $hasContentType = false;
        foreach ($source as $name => $value) {
            $lower = strtolower(trim((string) $name));
            if ($lower === '' || in_array($lower, $blocked, true) || str_starts_with($lower, ':')) {
                continue;
            }
            if (!preg_match('/^[a-z0-9-]+$/i', $lower)) {
                continue;
            }
            if ($lower === 'content-type') {
                $hasContentType = true;
            }
            $headers[] = trim((string) $name) . ': ' . str_replace(["\r", "\n"], '', (string) $value);
        }
        if (!$hasContentType) {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
        }
        return $headers;
    }

    private function buildCookieLine(array $cookies): string
    {
        $parts = [];
        foreach ($cookies as $name => $value) {
            if (preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', (string) $name)) {
                $parts[] = $name . '=' . str_replace(["\r", "\n", ';'], '', (string) $value);
            }
        }
        return implode('; ', $parts);
    }

    private function extractResponseCookies(array $setCookieHeaders): array
    {
        $cookies = [];
        foreach ($setCookieHeaders as $header) {
            $pair = explode(';', $header, 2)[0];
            $position = strpos($pair, '=');
            if ($position !== false) {
                $name = trim(substr($pair, 0, $position));
                if ($name !== '') {
                    $cookies[$name] = trim(substr($pair, $position + 1));
                }
            }
        }
        return $cookies;
    }

    private function headerValue(array $headers, string $wanted): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, $wanted) === 0) {
                return (string) $value;
            }
        }
        return null;
    }

    private function resolveCaBundle(): ?string
    {
        $configFile = ATLANTICA_ROOT . '/config/app.php';
        $config = is_file($configFile) ? require $configFile : [];
        $configured = is_array($config) ? ($config['ca_bundle'] ?? null) : null;
        $candidates = [
            'config/app.php' => is_string($configured) ? trim($configured) : '',
            'curl.cainfo' => trim((string) ini_get('curl.cainfo')),
            'openssl.cafile' => trim((string) ini_get('openssl.cafile')),
        ];

        foreach ($candidates as $source => $candidate) {
            if ($candidate === '') {
                continue;
            }
            $resolved = realpath($candidate);
            if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
                throw new CreaOneTlsException(
                    defined('CURLE_SSL_CACERT_BADFILE') ? CURLE_SSL_CACERT_BADFILE : 77,
                    'CA bundle configurado em ' . $source . ' não foi encontrado ou não pode ser lido.',
                    0,
                );
            }
            return $resolved;
        }

        return null;
    }

    private function isTlsError(int $curlErrno, string $curlError, int $sslVerifyResult): bool
    {
        $tlsErrorCodes = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];
        $message = strtolower($curlError);
        return in_array($curlErrno, $tlsErrorCodes, true)
            || $sslVerifyResult !== 0
            || str_contains($message, 'ssl')
            || str_contains($message, 'certificate');
    }
}
