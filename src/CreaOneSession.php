<?php

declare(strict_types=1);

final class CreaOneSession
{
    private const KEY = 'creaone_lab';
    private const ART_FIELD = 'ctl00$ctl00$MainContent$Main$NumeroART$NumeroARTTxt';
    private const SEARCH_BUTTON = 'ctl00$ctl00$MainContent$Main$BtnBuscar';
    private const SCRIPT_MANAGER = 'ctl00$ctl00$ScriptManager1';
    private const UPDATE_PANEL = 'ctl00$ctl00$MainContent$UpdatePanelPrincipal';
    private const DEFAULT_DETAILS_TARGET = 'ctl00$ctl00$MainContent$Main$UCARTRes1025ObraServico$BoxTitleDadosContrato$GrdDadosObraServico$ctl02$BtnVisualizar';

    public function clear(): void
    {
        unset($_SESSION[self::KEY]);
    }

    public function import(array $request): void
    {
        $form = $request['form'] ?? [];
        $_SESSION[self::KEY] = [
            'imported_request' => $request,
            'state' => [
                '__VIEWSTATE' => $form['__VIEWSTATE'] ?? '',
                '__VIEWSTATEGENERATOR' => $form['__VIEWSTATEGENERATOR'] ?? '',
                '__VIEWSTATEENCRYPTED' => $form['__VIEWSTATEENCRYPTED'] ?? '',
                '__EVENTTARGET' => $form['__EVENTTARGET'] ?? '',
                '__EVENTARGUMENT' => $form['__EVENTARGUMENT'] ?? '',
            ],
            'current_cookies' => $request['cookies'] ?? [],
            'current_art' => $form[self::ART_FIELD] ?? '',
            'captured_at' => date(DATE_ATOM),
            'last_request' => null,
            'last_art' => null,
            'works' => [],
        ];
    }

    public function isConfigured(): bool
    {
        return isset($_SESSION[self::KEY]['imported_request']);
    }

    public function importedRequest(): array
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Sessão CreaOne não configurada.');
        }
        return $_SESSION[self::KEY]['imported_request'];
    }

    public function applyHiddenFields(array $fields): bool
    {
        if (!$this->isConfigured()) {
            return false;
        }

        $updated = false;
        foreach (['__VIEWSTATE', '__VIEWSTATEGENERATOR', '__VIEWSTATEENCRYPTED', '__EVENTTARGET', '__EVENTARGUMENT'] as $name) {
            if (array_key_exists($name, $fields)) {
                $_SESSION[self::KEY]['state'][$name] = (string) $fields[$name];
                if (str_starts_with($name, '__VIEWSTATE')) {
                    $updated = true;
                }
            }
        }
        return $updated;
    }

    public function applyResponseCookies(array $cookies): void
    {
        if (!$this->isConfigured() || $cookies === []) {
            return;
        }
        foreach ($cookies as $name => $value) {
            $_SESSION[self::KEY]['current_cookies'][(string) $name] = (string) $value;
        }
    }

    public function saveResult(array $art, array $diagnostic): void
    {
        $_SESSION[self::KEY]['last_art'] = $art;
        $_SESSION[self::KEY]['works'] = $art['obras'] ?? [];
        $_SESSION[self::KEY]['last_request'] = $diagnostic;
        if (!empty($art['art']['numero'])) {
            $_SESSION[self::KEY]['current_art'] = $art['art']['numero'];
        }
    }

    public function saveDiagnostic(array $diagnostic): void
    {
        if ($this->isConfigured()) {
            $_SESSION[self::KEY]['last_request'] = $diagnostic;
        }
    }

    public function lastArt(): ?array
    {
        return $_SESSION[self::KEY]['last_art'] ?? null;
    }

    public function lastDiagnostic(): ?array
    {
        return $_SESSION[self::KEY]['last_request'] ?? null;
    }

    public function buildControlledRequest(string $artNumber): array
    {
        if (!preg_match('/^\d{5,30}$/', $artNumber)) {
            throw new InvalidArgumentException('Informe um número de ART válido, usando somente dígitos.');
        }

        $request = $this->requestWithCurrentState();
        $form = $request['form'];
        $form[self::ART_FIELD] = $artNumber;
        $form['__EVENTTARGET'] = '';
        $form['__EVENTARGUMENT'] = '';
        $form[self::SCRIPT_MANAGER] = self::UPDATE_PANEL . '|' . self::SEARCH_BUTTON;
        $form[self::SEARCH_BUTTON] = 'Buscar';
        $form['__ASYNCPOST'] = 'true';

        $request['form'] = $form;
        $request['body'] = http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        $_SESSION[self::KEY]['current_art'] = $artNumber;
        return $request;
    }

    public function buildDetailsRequest(int $workIndex): array
    {
        $works = $_SESSION[self::KEY]['works'] ?? [];
        $target = $works[$workIndex]['event_target'] ?? ($workIndex === 0 ? self::DEFAULT_DETAILS_TARGET : '');
        if (!is_string($target) || !preg_match('/^ctl00\$ctl00\$MainContent\$Main\$UCARTRes1025ObraServico\$.+\$BtnVisualizar$/', $target)) {
            throw new InvalidArgumentException('A ação de detalhes solicitada não pertence à tabela de obras retornada.');
        }

        $request = $this->requestWithCurrentState();
        $form = $request['form'];
        unset($form[self::SEARCH_BUTTON]);
        $form['__EVENTTARGET'] = $target;
        $form['__EVENTARGUMENT'] = '';
        $form[self::SCRIPT_MANAGER] = self::UPDATE_PANEL . '|' . $target;
        $form['__ASYNCPOST'] = 'true';
        $form['ctl00$ctl00$MainContent$Main$UCARTRes1025ObraServico$BoxTitleDadosContrato$GrdDadosObraServico$ctl04$ddlGridRowPageSize'] ??= '10';
        $form['ctl00$ctl00$MainContent$Main$UCARTRes1025ObraServico$BoxTitleAtividadesTecnicas$GrdAtividadesTecnicas$ctl05$ddlGridRowPageSize'] ??= '10';

        $request['form'] = $form;
        $request['body'] = http_build_query($form, '', '&', PHP_QUERY_RFC1738);
        return $request;
    }

    public function summary(): array
    {
        if (!$this->isConfigured()) {
            return [
                'configured' => false,
                'session_status' => 'Não configurada',
                'turnstile' => ['present' => false, 'status' => 'Token ausente'],
                'viewstate' => ['present' => false, 'bytes' => 0, 'label' => 'Ausente'],
                'last_request' => null,
                'last_art' => null,
            ];
        }

        $request = $this->importedRequest();
        $token = (string) ($request['form']['cf-turnstile-response'] ?? '');
        $viewstate = (string) ($_SESSION[self::KEY]['state']['__VIEWSTATE'] ?? '');
        $capturedAt = strtotime((string) ($_SESSION[self::KEY]['captured_at'] ?? '')) ?: time();
        $age = time() - $capturedAt;

        return [
            'configured' => true,
            'session_status' => 'Importada',
            'turnstile' => [
                'present' => $token !== '',
                'status' => $token === '' ? 'Token ausente' : ($age > 300 ? 'Token possivelmente expirado' : 'Token presente'),
            ],
            'viewstate' => [
                'present' => $viewstate !== '',
                'bytes' => strlen($viewstate),
                'label' => $viewstate !== '' ? 'Carregado' : 'Ausente',
            ],
            'last_request' => $_SESSION[self::KEY]['last_request'] ?? null,
            'last_art' => $_SESSION[self::KEY]['last_art'] ?? null,
            'import' => (new CurlImporter())->summary($request),
            'captured_at' => $_SESSION[self::KEY]['captured_at'] ?? null,
            'current_art' => $_SESSION[self::KEY]['current_art'] ?? '',
        ];
    }

    private function requestWithCurrentState(): array
    {
        $request = $this->importedRequest();
        $request['cookies'] = $_SESSION[self::KEY]['current_cookies'] ?? ($request['cookies'] ?? []);
        $form = $request['form'] ?? [];
        foreach (($_SESSION[self::KEY]['state'] ?? []) as $name => $value) {
            if ($value !== '' || array_key_exists($name, $form)) {
                $form[$name] = $value;
            }
        }
        $request['form'] = $form;
        return $request;
    }
}
