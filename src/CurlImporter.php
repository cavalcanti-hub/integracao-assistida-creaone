<?php

declare(strict_types=1);

final class CurlImporter
{
    public const ALLOWED_HOST = 'creanet1.creasp.org.br';
    public const ALLOWED_PATH = '/_UI/Pages/ConsultaPublica/PesquisaART/PesquisaART.aspx';
    private const MAX_INPUT_BYTES = 4_000_000;

    public function parse(string $command): array
    {
        if ($command === '' || strlen($command) > self::MAX_INPUT_BYTES) {
            throw new InvalidArgumentException('O cURL está vazio ou excede o limite de 4 MB.');
        }

        $tokens = $this->tokenize($command);
        if ($tokens === []) {
            throw new InvalidArgumentException('Não foi possível interpretar o cURL informado.');
        }

        $executable = strtolower(basename(str_replace('\\', '/', array_shift($tokens))));
        if (!in_array($executable, ['curl', 'curl.exe'], true)) {
            throw new InvalidArgumentException('O texto deve começar com o comando cURL copiado pelo Chrome.');
        }

        $url = '';
        $method = '';
        $headers = [];
        $cookieStrings = [];
        $bodyParts = [];

        for ($i = 0, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '-H' || $token === '--header') {
                $this->requireValue($tokens, $i, $token);
                $this->addHeader($headers, $tokens[++$i]);
                continue;
            }
            if (str_starts_with($token, '--header=')) {
                $this->addHeader($headers, substr($token, 9));
                continue;
            }
            if ($token === '-b' || $token === '--cookie') {
                $this->requireValue($tokens, $i, $token);
                $cookieStrings[] = $tokens[++$i];
                continue;
            }
            if (str_starts_with($token, '--cookie=')) {
                $cookieStrings[] = substr($token, 9);
                continue;
            }
            if ($token === '-X' || $token === '--request') {
                $this->requireValue($tokens, $i, $token);
                $method = strtoupper($tokens[++$i]);
                continue;
            }
            if (str_starts_with($token, '--request=')) {
                $method = strtoupper(substr($token, 10));
                continue;
            }
            if (in_array($token, ['--data', '--data-raw', '--data-binary', '--data-ascii', '-d'], true)) {
                $this->requireValue($tokens, $i, $token);
                $bodyParts[] = $tokens[++$i];
                continue;
            }
            if ($token === '--data-urlencode') {
                $this->requireValue($tokens, $i, $token);
                $part = $tokens[++$i];
                $equals = strpos($part, '=');
                $bodyParts[] = $equals === false
                    ? rawurlencode($part)
                    : substr($part, 0, $equals + 1) . rawurlencode(substr($part, $equals + 1));
                continue;
            }
            foreach (['--data=', '--data-raw=', '--data-binary=', '--data-ascii='] as $prefix) {
                if (str_starts_with($token, $prefix)) {
                    $bodyParts[] = substr($token, strlen($prefix));
                    continue 2;
                }
            }
            if ($token === '--url') {
                $this->requireValue($tokens, $i, $token);
                $url = $tokens[++$i];
                continue;
            }
            if (str_starts_with($token, '--url=')) {
                $url = substr($token, 6);
                continue;
            }
            if (!str_starts_with($token, '-') && preg_match('#^https?://#i', $token)) {
                $url = $token;
            }
        }

        self::assertAllowedUrl($url);
        $body = implode('&', $bodyParts);
        if ($method === '') {
            $method = $bodyParts !== [] ? 'POST' : 'GET';
        }
        if ($method !== 'POST') {
            throw new InvalidArgumentException('Somente a requisição POST da consulta pública é aceita.');
        }
        if ($body === '') {
            throw new InvalidArgumentException('O cURL não contém o corpo POST da consulta.');
        }

        $cookieHeader = $this->headerValue($headers, 'cookie');
        if ($cookieHeader !== null) {
            $cookieStrings[] = $cookieHeader;
            $this->removeHeader($headers, 'cookie');
        }
        $cookies = $this->parseCookies(implode('; ', $cookieStrings));
        $form = [];
        parse_str($body, $form);
        $form = array_map(static fn ($value): string => is_array($value) ? '' : (string) $value, $form);

        return [
            'url' => $url,
            'method' => $method,
            'headers' => $headers,
            'cookies' => $cookies,
            'body' => $body,
            'form' => $form,
        ];
    }

    public static function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== self::ALLOWED_HOST
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)
            || strcasecmp((string) ($parts['path'] ?? ''), self::ALLOWED_PATH) !== 0
            || isset($parts['fragment'])) {
            throw new InvalidArgumentException('URL bloqueada. Somente a página pública PesquisaART.aspx do creanet1.creasp.org.br é permitida.');
        }
    }

    public function summary(array $request): array
    {
        $form = $request['form'] ?? [];
        $cookieNames = array_keys($request['cookies'] ?? []);
        sort($cookieNames, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'url' => $request['url'],
            'method' => $request['method'],
            'host' => parse_url($request['url'], PHP_URL_HOST),
            'cookie_names' => $cookieNames,
            'viewstate_detected' => !empty($form['__VIEWSTATE']),
            'viewstate_bytes' => strlen((string) ($form['__VIEWSTATE'] ?? '')),
            'turnstile_detected' => !empty($form['cf-turnstile-response']),
            'art_captured' => (string) ($form['ctl00$ctl00$MainContent$Main$NumeroART$NumeroARTTxt'] ?? ''),
        ];
    }

    private function tokenize(string $command): array
    {
        $tokens = [];
        $buffer = '';
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];
            if ($quote === null) {
                if ($char === "'" || $char === '"') {
                    $quote = $char;
                    continue;
                }
                if ($char === '\\') {
                    if ($i + 1 < $length && ($command[$i + 1] === "\n" || $command[$i + 1] === "\r")) {
                        $i++;
                        if ($command[$i] === "\r" && $i + 1 < $length && $command[$i + 1] === "\n") {
                            $i++;
                        }
                        continue;
                    }
                    if ($i + 1 < $length) {
                        $buffer .= $command[++$i];
                    }
                    continue;
                }
                if (ctype_space($char)) {
                    if ($buffer !== '') {
                        $tokens[] = $buffer;
                        $buffer = '';
                    }
                    continue;
                }
                $buffer .= $char;
                continue;
            }

            if ($char === $quote) {
                $quote = null;
                continue;
            }
            if ($quote === '"' && $char === '\\' && $i + 1 < $length) {
                $next = $command[$i + 1];
                if (in_array($next, ['"', '\\', '$', '`'], true)) {
                    $buffer .= $next;
                    $i++;
                    continue;
                }
            }
            $buffer .= $char;
        }

        if ($quote !== null) {
            throw new InvalidArgumentException('O comando cURL contém aspas não fechadas.');
        }
        if ($buffer !== '') {
            $tokens[] = $buffer;
        }
        return $tokens;
    }

    private function addHeader(array &$headers, string $line): void
    {
        $position = strpos($line, ':');
        if ($position === false) {
            return;
        }
        $name = trim(substr($line, 0, $position));
        $value = trim(substr($line, $position + 1));
        if ($name !== '' && !str_starts_with($name, ':')) {
            $headers[$name] = $value;
        }
    }

    private function parseCookies(string $cookieHeader): array
    {
        $cookies = [];
        foreach (explode(';', $cookieHeader) as $piece) {
            $position = strpos($piece, '=');
            if ($position === false) {
                continue;
            }
            $name = trim(substr($piece, 0, $position));
            $value = trim(substr($piece, $position + 1));
            if ($name !== '' && preg_match('/^[!#$%&\'*+.^_`|~0-9A-Za-z-]+$/', $name)) {
                $cookies[$name] = $value;
            }
        }
        return $cookies;
    }

    private function headerValue(array $headers, string $wanted): ?string
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, $wanted) === 0) {
                return $value;
            }
        }
        return null;
    }

    private function removeHeader(array &$headers, string $wanted): void
    {
        foreach (array_keys($headers) as $name) {
            if (strcasecmp($name, $wanted) === 0) {
                unset($headers[$name]);
            }
        }
    }

    private function requireValue(array $tokens, int $index, string $option): void
    {
        if (!array_key_exists($index + 1, $tokens)) {
            throw new InvalidArgumentException('A opção ' . $option . ' não possui valor.');
        }
    }
}
