<?php

declare(strict_types=1);

final class BrowserCommandValidator
{
    public function openArt(array $payload): string
    {
        if (array_keys($payload) !== ['art'] || !is_string($payload['art'])) {
            throw new InvalidArgumentException('Informe somente o número da ART.');
        }
        $art = trim($payload['art']);
        if (!preg_match('/^\d{5,30}$/', $art)) {
            throw new InvalidArgumentException('Número da ART inválido.');
        }
        return $art;
    }
}
