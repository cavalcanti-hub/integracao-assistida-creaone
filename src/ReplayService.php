<?php

declare(strict_types=1);

final class ReplayService
{
    public function __construct(
        private readonly CreaOneSession $session,
        private readonly CreaOneClient $client = new CreaOneClient(),
        private readonly AspNetDeltaParser $deltaParser = new AspNetDeltaParser(),
        private readonly ArtParser $artParser = new ArtParser(),
        private readonly SafeLogger $logger = new SafeLogger(),
        private readonly ReplayDiagnosticStore $replayDiagnosticStore = new ReplayDiagnosticStore(),
    ) {
    }

    public function executeArt(array $request, string $action): array
    {
        $requestIdentifier = requestId();
        try {
            $http = $this->client->send($request);
        } catch (Throwable $exception) {
            $diagnostic = $this->emptyDiagnostic($request, $requestIdentifier) + [
                'error' => $exception->getMessage(),
            ];
            $this->addTlsDiagnostic($diagnostic, $exception);
            $this->recordReplayDiagnostic($action, $request, '', $diagnostic, false, false, false);
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write($action, $this->logContext($diagnostic, 'connection_error'));
            return ['ok' => false, 'message' => $exception->getMessage(), 'diagnostic' => $diagnostic];
        }

        $diagnostic = $this->diagnosticFromHttp($request, $http, $requestIdentifier);
        $this->session->applyResponseCookies($http['response_cookies']);

        if ($this->isRejected($http)) {
            $diagnostic['error'] = 'Token ou sessão rejeitados pelo servidor.';
            $this->recordEarlyHttpReplay($action, $request, $http, $diagnostic);
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write($action, $this->logContext($diagnostic, 'session_or_token_rejected'));
            return ['ok' => false, 'message' => 'SESSÃO OU TOKEN NÃO ACEITO', 'diagnostic' => $diagnostic];
        }
        if ($http['http_status'] >= 400 || $http['http_status'] === 0) {
            $diagnostic['error'] = 'Erro HTTP do CREA.';
            $this->recordEarlyHttpReplay($action, $request, $http, $diagnostic);
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write($action, $this->logContext($diagnostic, 'http_error'));
            return ['ok' => false, 'message' => 'Erro HTTP do CREA (status ' . $http['http_status'] . ').', 'diagnostic' => $diagnostic];
        }
        if ($http['http_status'] >= 300) {
            $diagnostic['error'] = 'Sessão expirada.';
            $this->recordEarlyHttpReplay($action, $request, $http, $diagnostic);
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write($action, $this->logContext($diagnostic, 'redirect_or_expired'));
            return ['ok' => false, 'message' => 'Sessão expirada.', 'diagnostic' => $diagnostic];
        }

        try {
            $delta = $this->deltaParser->parse($http['body']);
            $diagnostic['update_panel'] = $delta['primary_html'] !== null;
            $diagnostic['viewstate_updated'] = $this->session->applyHiddenFields($delta['hidden_fields']);
            $html = $delta['primary_html'] ?? ($delta['full_html'] ?? '');
            $updatePanelHtml = $delta['primary_html'] ?? '';
            $parserFound = $html !== '' && $this->artParser->hasArtResult($html);
            $replayAnalysis = $this->recordReplayDiagnostic(
                $action,
                $request,
                $updatePanelHtml,
                $diagnostic,
                true,
                $delta['primary_html'] !== null,
                $parserFound,
            );
            if ($html === '') {
                $diagnostic['error'] = 'UpdatePanel não identificado.';
                $this->session->saveDiagnostic($diagnostic);
                $this->logger->write($action, $this->logContext($diagnostic, 'update_panel_missing'));
                return ['ok' => false, 'message' => 'UpdatePanel não identificado.', 'diagnostic' => $diagnostic];
            }

            $diagnostic['art_found'] = $parserFound;
            if (!$diagnostic['art_found']) {
                $message = $replayAnalysis['classification'] === 'ART_EXPLICITAMENTE_NAO_ENCONTRADA'
                    ? 'O CREA informou explicitamente que a ART não foi encontrada.'
                    : 'Resposta recebida, mas o resultado da ART não foi reconhecido.';
                $diagnostic['error'] = $message;
                $this->session->saveDiagnostic($diagnostic);
                $this->logger->write($action, $this->logContext($diagnostic, strtolower($replayAnalysis['classification'])));
                return ['ok' => false, 'message' => $message, 'diagnostic' => $diagnostic];
            }

            $art = $this->artParser->parse($html);
            $this->session->saveResult($art, $diagnostic);
            $this->logger->write($action, $this->logContext($diagnostic, 'success'));
            return [
                'ok' => true,
                'message' => 'Requisição aceita e ART encontrada.',
                'art' => $art,
                'diagnostic' => $diagnostic,
            ];
        } catch (Throwable $exception) {
            $diagnostic['error'] = str_starts_with($exception->getMessage(), 'Resposta ASP.NET inválida')
                ? $exception->getMessage()
                : 'Resposta ASP.NET inválida.';
            if ($action === 'exact_replay' && !isset($diagnostic['classification'])) {
                $this->recordReplayDiagnostic($action, $request, '', $diagnostic, false, false, false);
            }
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write($action, $this->logContext($diagnostic, 'invalid_response'));
            return ['ok' => false, 'message' => $diagnostic['error'], 'diagnostic' => $diagnostic];
        }
    }

    public function executeDetails(array $request): array
    {
        $requestIdentifier = requestId();
        try {
            $http = $this->client->send($request);
        } catch (Throwable $exception) {
            $diagnostic = $this->emptyDiagnostic($request, $requestIdentifier) + ['error' => $exception->getMessage()];
            $this->addTlsDiagnostic($diagnostic, $exception);
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write('details', $this->logContext($diagnostic, 'connection_error'));
            return ['ok' => false, 'message' => $exception->getMessage(), 'diagnostic' => $diagnostic];
        }

        $diagnostic = $this->diagnosticFromHttp($request, $http, $requestIdentifier);
        $this->session->applyResponseCookies($http['response_cookies']);
        if ($this->isRejected($http)) {
            $diagnostic['error'] = 'Token ou sessão rejeitados pelo servidor.';
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write('details', $this->logContext($diagnostic, 'session_or_token_rejected'));
            return ['ok' => false, 'message' => 'SESSÃO OU TOKEN NÃO ACEITO', 'diagnostic' => $diagnostic];
        }
        if ($http['http_status'] >= 400 || $http['http_status'] === 0) {
            $diagnostic['error'] = 'Erro HTTP do CREA.';
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write('details', $this->logContext($diagnostic, 'http_error'));
            return ['ok' => false, 'message' => 'Erro HTTP do CREA (status ' . $http['http_status'] . ').', 'diagnostic' => $diagnostic];
        }

        try {
            $delta = $this->deltaParser->parse($http['body']);
            $diagnostic['update_panel'] = $delta['primary_html'] !== null;
            $diagnostic['viewstate_updated'] = $this->session->applyHiddenFields($delta['hidden_fields']);
            $html = $delta['primary_html'] ?? ($delta['full_html'] ?? '');
            if ($html === '') {
                throw new RuntimeException('UpdatePanel não identificado.');
            }
            $parsed = $this->artParser->parseWorkDetails($html);
            $diagnostic['modal_found'] = $parsed['modal_found'];
            $this->session->saveDiagnostic($diagnostic);

            if (!$parsed['modal_found']) {
                $diagnostic['error'] = 'Modal de detalhes não encontrado.';
                $this->session->saveDiagnostic($diagnostic);
                $this->logger->write('details', $this->logContext($diagnostic, 'modal_missing'));
                return ['ok' => false, 'message' => 'Modal de detalhes não encontrado na resposta do CREA.', 'diagnostic' => $diagnostic];
            }

            $this->logger->write('details', $this->logContext($diagnostic, 'success'));
            return [
                'ok' => true,
                'message' => 'Detalhes da obra carregados.',
                'details' => $parsed['details'],
                'atividades' => $parsed['atividades'],
                'diagnostic' => $diagnostic,
            ];
        } catch (Throwable $exception) {
            $diagnostic['error'] = $exception->getMessage();
            $this->session->saveDiagnostic($diagnostic);
            $this->logger->write('details', $this->logContext($diagnostic, 'invalid_response'));
            return ['ok' => false, 'message' => $exception->getMessage(), 'diagnostic' => $diagnostic];
        }
    }

    private function diagnosticFromHttp(array $request, array $http, string $requestIdentifier): array
    {
        return [
            'request_id' => $requestIdentifier,
            'url' => $request['url'],
            'method' => 'POST',
            'http_status' => $http['http_status'],
            'content_type' => $http['content_type'],
            'duration_ms' => $http['duration_ms'],
            'response_bytes' => $http['response_bytes'],
            'update_panel' => false,
            'viewstate_updated' => false,
            'art_found' => false,
            'modal_found' => false,
            'turnstile_sent' => !empty($request['form']['cf-turnstile-response']),
            'timestamp' => date(DATE_ATOM),
        ];
    }

    private function emptyDiagnostic(array $request, string $requestIdentifier): array
    {
        return [
            'request_id' => $requestIdentifier,
            'url' => $request['url'] ?? CurlImporter::ALLOWED_HOST,
            'method' => 'POST',
            'http_status' => 0,
            'content_type' => '',
            'duration_ms' => 0,
            'response_bytes' => 0,
            'update_panel' => false,
            'viewstate_updated' => false,
            'art_found' => false,
            'modal_found' => false,
            'turnstile_sent' => !empty($request['form']['cf-turnstile-response']),
            'timestamp' => date(DATE_ATOM),
        ];
    }

    private function isRejected(array $http): bool
    {
        if (in_array($http['http_status'], [401, 403], true)) {
            return true;
        }
        $rawSample = mb_strtolower(mb_substr($http['body'], 0, 40_000), 'UTF-8');
        $textSample = mb_strtolower(mb_substr(strip_tags($http['body']), 0, 20_000), 'UTF-8');
        $invalidTerms = ['inválid', 'invalid', 'expirad', 'rejeitad', 'obrigatór', 'required'];
        $mentionsChallenge = str_contains($textSample, 'turnstile') || str_contains($textSample, 'captcha');
        foreach ($invalidTerms as $term) {
            if ($mentionsChallenge && str_contains($textSample, $term)) {
                return true;
            }
        }
        return str_contains($textSample, 'sessão expirada')
            || str_contains($textSample, 'sessao expirada')
            || (str_contains($rawSample, 'cf-chl-') && str_contains($textSample, 'just a moment'));
    }

    private function recordEarlyHttpReplay(string $action, array $request, array $http, array &$diagnostic): void
    {
        if ($action !== 'exact_replay') {
            return;
        }

        try {
            $delta = $this->deltaParser->parse($http['body']);
            $updatePanelHtml = $delta['primary_html'] ?? '';
            $parserFound = $updatePanelHtml !== '' && $this->artParser->hasArtResult($updatePanelHtml);
            $this->recordReplayDiagnostic(
                $action,
                $request,
                $updatePanelHtml,
                $diagnostic,
                true,
                $delta['primary_html'] !== null,
                $parserFound,
            );
        } catch (Throwable) {
            $this->recordReplayDiagnostic($action, $request, '', $diagnostic, false, false, false);
        }
    }

    private function recordReplayDiagnostic(
        string $action,
        array $request,
        string $updatePanelHtml,
        array &$diagnostic,
        bool $deltaValid,
        bool $updatePanelFound,
        bool $parserFound,
    ): array {
        $artField = 'ctl00$ctl00$MainContent$Main$NumeroART$NumeroARTTxt';
        $analysis = $this->replayDiagnosticStore->analyze(
            $updatePanelHtml,
            (string) ($request['form'][$artField] ?? ''),
            (int) ($diagnostic['http_status'] ?? 0),
            (int) ($diagnostic['response_bytes'] ?? 0),
            $deltaValid,
            $updatePanelFound,
            $parserFound,
        );

        foreach ($analysis as $key => $value) {
            $diagnostic[$key] = $value;
        }
        $diagnostic['update_panel'] = $updatePanelFound;
        $diagnostic['art_found'] = $parserFound;

        if ($action === 'exact_replay') {
            $this->replayDiagnosticStore->persist($updatePanelHtml, $analysis);
        }

        return $analysis;
    }

    private function logContext(array $diagnostic, string $result): array
    {
        return [
            'request_id' => $diagnostic['request_id'] ?? null,
            'http_status' => $diagnostic['http_status'] ?? 0,
            'duration_ms' => $diagnostic['duration_ms'] ?? 0,
            'response_bytes' => $diagnostic['response_bytes'] ?? 0,
            'update_panel' => $diagnostic['update_panel'] ?? false,
            'viewstate_updated' => $diagnostic['viewstate_updated'] ?? false,
            'art_found' => $diagnostic['art_found'] ?? false,
            'modal_found' => $diagnostic['modal_found'] ?? false,
            'result' => $result,
            'error' => $diagnostic['error'] ?? null,
            'curl_errno' => $diagnostic['curl_errno'] ?? null,
            'curl_error' => $diagnostic['curl_error'] ?? null,
            'ssl_verify_result' => $diagnostic['ssl_verify_result'] ?? null,
        ];
    }

    private function addTlsDiagnostic(array &$diagnostic, Throwable $exception): void
    {
        if (!$exception instanceof CreaOneTlsException) {
            return;
        }
        $diagnostic['curl_errno'] = $exception->curlErrno;
        $diagnostic['curl_error'] = $exception->curlError;
        $diagnostic['ssl_verify_result'] = $exception->sslVerifyResult;
    }
}
