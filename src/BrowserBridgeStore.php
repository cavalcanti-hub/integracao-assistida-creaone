<?php

declare(strict_types=1);

final class BrowserBridgeStore
{
    public const HEARTBEAT_MAX_AGE_SECONDS = 30;
    public const COMMAND_DELIVERY_MAX_AGE_SECONDS = 20;

    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function saveImport(array $capture): array
    {
        $record = $capture;
        $record['received_at'] = date(DATE_ATOM);
        $record['import_id'] = bin2hex(random_bytes(8));
        $this->writeJson('last-import.json', $record);
        return $record;
    }

    public function lastImport(): ?array
    {
        return $this->readJson('last-import.json');
    }

    public function touchBridge(?DateTimeInterface $at = null, ?string $origin = null): void
    {
        $timestamp = $at?->format(DATE_ATOM) ?? date(DATE_ATOM);
        $status = $this->readJson('bridge-status.json') ?? [];
        if (!is_array($status)) {
            $status = [];
        }
        $status['last_seen'] = $timestamp;
        if ($origin !== null && $this->isExtensionOrigin($origin)) {
            if (!isset($status['extension_origin']) || $status['extension_origin'] === '' || $status['extension_origin'] === $origin) {
                $status['extension_origin'] = $origin;
                $status['extension_origin_seen_at'] = $timestamp;
            }
        }
        $this->writeJson('bridge-status.json', $status);
    }

    public function rememberExtensionOrigin(string $origin, ?DateTimeInterface $at = null): void
    {
        if (!$this->isExtensionOrigin($origin)) {
            return;
        }

        $timestamp = $at?->format(DATE_ATOM) ?? date(DATE_ATOM);
        $status = $this->readJson('bridge-status.json') ?? [];
        if (!is_array($status)) {
            $status = [];
        }

        if (!isset($status['extension_origin']) || $status['extension_origin'] === '' || $status['extension_origin'] === $origin) {
            $status['extension_origin'] = $origin;
            $status['extension_origin_seen_at'] = $timestamp;
            $this->writeJson('bridge-status.json', $status);
        }
    }

    public function bridgeStatus(?DateTimeInterface $now = null): array
    {
        $status = $this->readJson('bridge-status.json');
        $lastSeen = is_array($status) ? ($status['last_seen'] ?? null) : null;
        $extensionOrigin = is_array($status) ? ($status['extension_origin'] ?? null) : null;
        $extensionOriginSeenAt = is_array($status) ? ($status['extension_origin_seen_at'] ?? null) : null;
        $timestamp = is_string($lastSeen) ? strtotime($lastSeen) : false;
        $currentTimestamp = ($now ?? new DateTimeImmutable())->getTimestamp();
        $age = $timestamp === false ? null : max(0, $currentTimestamp - $timestamp);
        return [
            'connected' => $age !== null && $age <= self::HEARTBEAT_MAX_AGE_SECONDS,
            'last_seen' => is_string($lastSeen) ? $lastSeen : null,
            'age_seconds' => $age,
            'extension_origin' => is_string($extensionOrigin) && $extensionOrigin !== '' ? $extensionOrigin : null,
            'extension_origin_seen_at' => is_string($extensionOriginSeenAt) && $extensionOriginSeenAt !== '' ? $extensionOriginSeenAt : null,
        ];
    }

    public function saveCommand(string $art): array
    {
        $command = [
            'command_id' => bin2hex(random_bytes(8)),
            'action' => 'open_art',
            'art' => $art,
            'created_at' => date(DATE_ATOM),
            'status' => 'pending',
            'delivered_at' => null,
            'acknowledged_at' => null,
            'consumed_at' => null,
            'delivery_count' => 0,
        ];
        $this->writeJson('bridge-command.json', $command);
        return $command;
    }

    public function consumeCommand(): ?array
    {
        return $this->claimCommand();
    }

    public function claimCommand(): ?array
    {
        $path = $this->path('bridge-command.json');
        if (!is_file($path)) {
            return null;
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível acessar o comando local.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Não foi possível bloquear o comando local.');
            }
            rewind($handle);
            $decoded = $this->normalizeCommand(json_decode((string) stream_get_contents($handle), true));
            if ($decoded === null) {
                flock($handle, LOCK_UN);
                return null;
            }
            if (($decoded['status'] ?? 'pending') === 'delivered' && !$this->isDeliveryExpired($decoded)) {
                flock($handle, LOCK_UN);
                return null;
            }
            $decoded['status'] = 'delivered';
            $decoded['delivered_at'] = date(DATE_ATOM);
            $decoded['delivery_count'] = (int) ($decoded['delivery_count'] ?? 0) + 1;
            $json = $this->encode($decoded);
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, $json . PHP_EOL) === false) {
                throw new RuntimeException('Não foi possível consumir o comando local.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
            return $this->publicCommand($decoded);
        } finally {
            fclose($handle);
        }
    }

    public function acknowledgeCommand(string $commandId): ?array
    {
        $path = $this->path('bridge-command.json');
        if (!is_file($path)) {
            return null;
        }
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível acessar o comando local.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Não foi possível bloquear o comando local.');
            }
            rewind($handle);
            $decoded = $this->normalizeCommand(json_decode((string) stream_get_contents($handle), true));
            if ($decoded === null || (string) ($decoded['command_id'] ?? '') !== $commandId) {
                flock($handle, LOCK_UN);
                return null;
            }
            if (($decoded['status'] ?? 'pending') === 'acknowledged') {
                flock($handle, LOCK_UN);
                return $this->publicCommand($decoded);
            }
            if (($decoded['status'] ?? 'pending') === 'pending' || $this->stringOrNull($decoded['delivered_at'] ?? null) === null) {
                flock($handle, LOCK_UN);
                return null;
            }
            $now = date(DATE_ATOM);
            $decoded['status'] = 'acknowledged';
            $decoded['acknowledged_at'] = $now;
            $decoded['consumed_at'] = $now;
            $json = $this->encode($decoded);
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, $json . PHP_EOL) === false) {
                throw new RuntimeException('Não foi possível confirmar o comando local.');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
            return $this->publicCommand($decoded);
        } finally {
            fclose($handle);
        }
    }

    public function commandStatus(?string $commandId = null): ?array
    {
        $command = $this->readJson('bridge-command.json');
        $normalized = $this->normalizeCommand($command);
        if ($normalized === null) {
            return null;
        }
        if ($commandId !== null && (string) ($normalized['command_id'] ?? '') !== $commandId) {
            return null;
        }
        return $this->publicCommand($normalized);
    }

    public function lastCommand(): ?array
    {
        $command = $this->readJson('bridge-command.json');
        $normalized = $this->normalizeCommand($command);
        return $normalized;
    }

    private function writeJson(string $filename, array $data): void
    {
        $json = $this->encode($data);
        if (file_put_contents($this->path($filename), $json . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível salvar a captura local.');
        }
    }

    private function readJson(string $filename): ?array
    {
        $path = $this->path($filename);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeCommand(null|array $command): ?array
    {
        if (!is_array($command)) {
            return null;
        }
        $commandId = trim((string) ($command['command_id'] ?? ''));
        $action = trim((string) ($command['action'] ?? ''));
        $art = trim((string) ($command['art'] ?? ''));
        $createdAt = trim((string) ($command['created_at'] ?? ''));
        if ($commandId === '' || $action === '' || $art === '' || $createdAt === '') {
            return null;
        }

        $status = trim((string) ($command['status'] ?? ''));
        $deliveredAt = $this->stringOrNull($command['delivered_at'] ?? null);
        $acknowledgedAt = $this->stringOrNull($command['acknowledged_at'] ?? null);
        $consumedAt = $this->stringOrNull($command['consumed_at'] ?? null);

        if ($status === '') {
            if ($acknowledgedAt !== null || $consumedAt !== null) {
                $status = 'acknowledged';
            } elseif ($deliveredAt !== null) {
                $status = 'delivered';
            } else {
                $status = 'pending';
            }
        }

        return [
            'command_id' => $commandId,
            'action' => $action,
            'art' => $art,
            'created_at' => $createdAt,
            'status' => $status,
            'delivered_at' => $deliveredAt,
            'acknowledged_at' => $acknowledgedAt,
            'consumed_at' => $consumedAt,
            'delivery_count' => max(0, (int) ($command['delivery_count'] ?? 0)),
        ];
    }

    private function publicCommand(array $command): array
    {
        return [
            'command_id' => (string) ($command['command_id'] ?? ''),
            'action' => (string) ($command['action'] ?? ''),
            'art' => (string) ($command['art'] ?? ''),
            'created_at' => (string) ($command['created_at'] ?? ''),
            'status' => (string) ($command['status'] ?? 'pending'),
            'delivered_at' => $this->stringOrNull($command['delivered_at'] ?? null),
            'acknowledged_at' => $this->stringOrNull($command['acknowledged_at'] ?? null),
            'consumed_at' => $this->stringOrNull($command['consumed_at'] ?? null),
            'delivery_count' => max(0, (int) ($command['delivery_count'] ?? 0)),
        ];
    }

    private function isDeliveryExpired(array $command): bool
    {
        $deliveredAt = $this->stringOrNull($command['delivered_at'] ?? null);
        if ($deliveredAt === null) {
            return false;
        }
        $timestamp = strtotime($deliveredAt);
        if ($timestamp === false) {
            return true;
        }
        return max(0, time() - $timestamp) > self::COMMAND_DELIVERY_MAX_AGE_SECONDS;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function path(string $filename): string
    {
        $directory = $this->directory ?? ATLANTICA_ROOT . '/storage/imports';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível preparar o armazenamento local.');
        }
        return $directory . '/' . $filename;
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('Não foi possível serializar o armazenamento local.');
        }
        return $json;
    }

    private function isExtensionOrigin(string $origin): bool
    {
        return preg_match('#^chrome-extension://[a-p]{32}$#', $origin) === 1;
    }
}
