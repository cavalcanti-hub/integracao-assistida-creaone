<?php

declare(strict_types=1);

final class ReplayDiagnosticStore
{
    private const LITERAL_TERMS = [
        'UCARTRes',
        'SpanNumeroART',
        'MainContent_Main_ResultGroup',
        'SituacaoART',
        'BAIXADA',
        'cf-turnstile',
        'turnstile',
        'captcha',
        'Captcha',
        'ErrorLabel',
        'erro',
        'Erro',
        'inválido',
        'invalido',
        'expirado',
        'sessão',
        'Sessão',
        'validation',
    ];

    private const SENSITIVE_MARKERS = [
        '__VIEWSTATE',
        'cf-turnstile-response',
        'ASP.NET_SessionId',
        '.ASPXAUTH',
        'HWWAFSESID',
    ];

    public function __construct(private readonly ?string $directory = null)
    {
    }

    public function analyze(
        string $html,
        string $requestedArt,
        int $httpStatus,
        int $responseBytes,
        bool $deltaValid,
        bool $updatePanelFound,
        bool $parserFound,
    ): array {
        $literalTerms = $this->findLiteralTerms($html);
        $errorTexts = $this->extractErrorTexts($html);
        $artNumberFound = $requestedArt !== '' && str_contains($html, $requestedArt);
        $ucartresFound = str_contains($html, 'UCARTRes');
        $spanNumeroArtFound = str_contains($html, 'SpanNumeroART');
        $situacaoArtFound = str_contains($html, 'SituacaoART');
        $captchaTerms = array_values(array_intersect(
            ['cf-turnstile', 'turnstile', 'captcha', 'Captcha'],
            $literalTerms
        ));

        $analysis = [
            'timestamp' => date(DATE_ATOM),
            'http_status' => $httpStatus,
            'response_bytes' => $responseBytes,
            'delta_valid' => $deltaValid,
            'update_panel_found' => $updatePanelFound,
            'update_panel_bytes' => strlen($html),
            'art_number_requested' => $requestedArt,
            'art_number_found_in_html' => $artNumberFound,
            'ucartres_found' => $ucartresFound,
            'span_numero_art_found' => $spanNumeroArtFound,
            'result_group_found' => str_contains($html, 'MainContent_Main_ResultGroup'),
            'situacao_art_found' => $situacaoArtFound,
            'baixada_found' => str_contains($html, 'BAIXADA'),
            'captcha_terms_found' => $captchaTerms,
            'literal_terms_found' => $literalTerms,
            'error_texts_found' => $errorTexts,
        ];
        $analysis['classification'] = $this->classify($analysis, $parserFound);

        return $analysis;
    }

    public function persist(string $updatePanelHtml, array $diagnostic): array
    {
        $directory = $this->directory ?? ATLANTICA_ROOT . '/storage/debug';
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return ['html_saved' => false, 'json_saved' => false];
        }

        $containsSensitiveMarker = $this->containsSensitiveMarker($updatePanelHtml);
        $htmlToSave = $containsSensitiveMarker ? '' : $updatePanelHtml;
        $diagnostic['update_panel_html_saved'] = !$containsSensitiveMarker;
        if ($containsSensitiveMarker) {
            $diagnostic['storage_warning'] = 'HTML não persistido porque continha um campo sensível.';
        }

        $htmlSaved = @file_put_contents(
            $directory . '/last-replay-updatepanel.html',
            $htmlToSave,
            LOCK_EX
        ) !== false;
        $diagnostic['update_panel_html_saved'] = $htmlSaved && !$containsSensitiveMarker;

        $json = json_encode(
            $diagnostic,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $jsonSaved = $json !== false && @file_put_contents(
            $directory . '/last-replay-diagnostic.json',
            $json . PHP_EOL,
            LOCK_EX
        ) !== false;

        return ['html_saved' => $htmlSaved && !$containsSensitiveMarker, 'json_saved' => $jsonSaved];
    }

    private function findLiteralTerms(string $html): array
    {
        $found = [];
        foreach (self::LITERAL_TERMS as $term) {
            if (str_contains($html, $term)) {
                $found[] = $term;
            }
        }
        return $found;
    }

    private function extractErrorTexts(string $html): array
    {
        if ($html === '') {
            return [];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body><div id="__diagnostic_root">' . $html . '</div></body></html>',
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $texts = [];

        foreach ($xpath->query('//*[@id or @class] | //span | //label | //p | //li | //td') ?: [] as $node) {
            if (!$node instanceof DOMElement || $this->isHidden($node)) {
                continue;
            }
            $text = $this->cleanText($node->textContent);
            if ($text === '' || mb_strlen($text, 'UTF-8') > 1000 || $this->containsSensitiveMarker($text)) {
                continue;
            }

            $attributes = mb_strtolower($node->getAttribute('id') . ' ' . $node->getAttribute('class'), 'UTF-8');
            $normalizedText = mb_strtolower($text, 'UTF-8');
            $attributeSuggestsError = preg_match('/error|validation|validator|alert|danger|warning|erro/u', $attributes) === 1;
            $textSuggestsError = preg_match('/captcha|turnstile|inválid|invalido|expirad|sessão|sessao|\berro\b|não encontrada|nao encontrada|não existe|nao existe|validation/u', $normalizedText) === 1;

            if (($attributeSuggestsError || $textSuggestsError) && !in_array($text, $texts, true)) {
                $texts[] = $text;
            }
        }

        return array_slice($texts, 0, 20);
    }

    private function classify(array $analysis, bool $parserFound): string
    {
        $messages = mb_strtolower(implode(' ', $analysis['error_texts_found']), 'UTF-8');
        foreach ([
            'art não encontrada',
            'art nao encontrada',
            'nenhuma art encontrada',
            'nenhuma art foi encontrada',
            'a art informada não existe',
            'a art informada nao existe',
        ] as $explicitNotFoundText) {
            if (str_contains($messages, $explicitNotFoundText)) {
                return 'ART_EXPLICITAMENTE_NAO_ENCONTRADA';
            }
        }

        if ($parserFound || $analysis['span_numero_art_found']) {
            return 'ART_PRESENTE';
        }

        $rejectionTermFound = $this->containsAny($messages, ['inválid', 'invalido', 'expirad', 'rejeitad', 'obrigatór']);
        if ($analysis['captcha_terms_found'] !== [] && $rejectionTermFound) {
            return 'POSSIVEL_CAPTCHA_OU_TURNSTILE';
        }
        if ($this->containsAny($messages, ['sessão', 'sessao']) && $rejectionTermFound) {
            return 'POSSIVEL_SESSAO_REJEITADA';
        }

        return 'RESPOSTA_INESPERADA';
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function containsSensitiveMarker(string $content): bool
    {
        foreach (self::SENSITIVE_MARKERS as $marker) {
            if (stripos($content, $marker) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isHidden(DOMElement $node): bool
    {
        for ($current = $node; $current instanceof DOMElement; $current = $current->parentNode) {
            $style = strtolower($current->getAttribute('style'));
            if ($current->hasAttribute('hidden')
                || strtolower($current->getAttribute('aria-hidden')) === 'true'
                || str_contains($style, 'display:none')
                || str_contains($style, 'display: none')
                || str_contains($style, 'visibility:hidden')
                || str_contains($style, 'visibility: hidden')) {
                return true;
            }
        }
        return false;
    }

    private function cleanText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
