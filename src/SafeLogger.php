<?php

declare(strict_types=1);

final class SafeLogger
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? ATLANTICA_ROOT . '/storage/logs';
    }

    public function write(string $action, array $context = []): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0770, true);
        }

        $allowed = [
            'request_id', 'http_status', 'duration_ms', 'response_bytes',
            'result', 'update_panel', 'viewstate_updated', 'art_found',
            'modal_found', 'error',
            'curl_errno', 'curl_error', 'ssl_verify_result',
            'art_number', 'works_count', 'activities_count',
            'endpoint', 'method', 'origin', 'origin_kind',
        ];
        $safe = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $context)) {
                $safe[$key] = is_string($context[$key])
                    ? mb_substr($context[$key], 0, 500)
                    : $context[$key];
            }
        }

        $record = [
            'timestamp' => date(DATE_ATOM),
            'action' => preg_replace('/[^a-z0-9_.-]/i', '_', $action),
        ] + $safe;

        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($line !== false) {
            file_put_contents(
                $this->directory . '/creaone-' . date('Y-m-d') . '.log',
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }
}
