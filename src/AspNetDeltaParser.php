<?php

declare(strict_types=1);

final class AspNetDeltaParser
{
    public const PRIMARY_PANEL = 'MainContent_UpdatePanelPrincipal';

    public function parse(string $response): array
    {
        if ($response === '') {
            throw new RuntimeException('Resposta ASP.NET inválida: corpo vazio.');
        }

        $trimmed = ltrim($response, "\xEF\xBB\xBF\r\n\t ");
        if ($trimmed !== '' && $trimmed[0] === '<') {
            return $this->parseHtmlResponse($response);
        }

        $segments = [];
        $hiddenFields = [];
        $updatePanels = [];
        $scripts = [];
        $cursor = 0;
        $total = strlen($response);

        while ($cursor < $total) {
            while ($cursor < $total && ($response[$cursor] === "\r" || $response[$cursor] === "\n")) {
                $cursor++;
            }
            if ($cursor >= $total) {
                break;
            }

            $lengthToken = $this->readToken($response, $cursor);
            if ($lengthToken === '' || !ctype_digit($lengthToken)) {
                throw new RuntimeException('Resposta ASP.NET inválida: segmento sem tamanho válido.');
            }
            $type = $this->readToken($response, $cursor);
            $id = $this->readToken($response, $cursor);
            $content = $this->readSizedContent($response, $cursor, (int) $lengthToken);
            $segment = ['type' => $type, 'id' => $id, 'content' => $content];
            $segments[] = $segment;

            if ($type === 'hiddenField') {
                $hiddenFields[$id] = $content;
            } elseif ($type === 'updatePanel') {
                $updatePanels[$id] = $content;
            } elseif ($type === 'scriptBlock' || $type === 'scriptStartupBlock') {
                $scripts[] = $segment;
            }
        }

        if ($segments === []) {
            throw new RuntimeException('Resposta ASP.NET inválida: nenhum segmento encontrado.');
        }

        return [
            'is_delta' => true,
            'segments' => $segments,
            'hidden_fields' => $hiddenFields,
            'update_panels' => $updatePanels,
            'scripts' => $scripts,
            'primary_html' => $updatePanels[self::PRIMARY_PANEL] ?? null,
        ];
    }

    private function parseHtmlResponse(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($document);
        $hiddenFields = [];
        foreach ($xpath->query('//input[@type="hidden" and @name]') ?: [] as $input) {
            $hiddenFields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        $panel = $xpath->query('//*[@id="' . self::PRIMARY_PANEL . '"]')->item(0);
        $panelHtml = $panel instanceof DOMNode ? $this->innerHtml($panel) : null;

        return [
            'is_delta' => false,
            'segments' => [],
            'hidden_fields' => $hiddenFields,
            'update_panels' => $panelHtml === null ? [] : [self::PRIMARY_PANEL => $panelHtml],
            'scripts' => [],
            'primary_html' => $panelHtml,
            'full_html' => $html,
        ];
    }

    private function readToken(string $response, int &$cursor): string
    {
        $delimiter = strpos($response, '|', $cursor);
        if ($delimiter === false) {
            throw new RuntimeException('Resposta ASP.NET inválida: delimitador ausente.');
        }
        $token = substr($response, $cursor, $delimiter - $cursor);
        $cursor = $delimiter + 1;
        return $token;
    }

    private function readSizedContent(string $response, int &$cursor, int $characterLength): string
    {
        $byteCandidate = substr($response, $cursor, $characterLength);
        $byteEnd = $cursor + strlen($byteCandidate);
        if (strlen($byteCandidate) === $characterLength && isset($response[$byteEnd]) && $response[$byteEnd] === '|') {
            $cursor = $byteEnd + 1;
            return $byteCandidate;
        }

        if (function_exists('mb_substr')) {
            $remainder = substr($response, $cursor);
            $characterCandidate = mb_substr($remainder, 0, $characterLength, 'UTF-8');
            $characterEnd = $cursor + strlen($characterCandidate);
            if (mb_strlen($characterCandidate, 'UTF-8') === $characterLength
                && isset($response[$characterEnd])
                && $response[$characterEnd] === '|') {
                $cursor = $characterEnd + 1;
                return $characterCandidate;
            }
        }

        throw new RuntimeException('Resposta ASP.NET inválida: tamanho de segmento inconsistente.');
    }

    private function innerHtml(DOMNode $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument?->saveHTML($child) ?? '';
        }
        return $html;
    }
}
