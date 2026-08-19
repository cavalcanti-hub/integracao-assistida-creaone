<?php

declare(strict_types=1);

final class BrowserFixtureReader
{
    private DOMDocument $document;
    private DOMXPath $xpath;

    public function __construct(string $path)
    {
        $html = file_get_contents($path);
        if (!is_string($html)) {
            throw new RuntimeException('Fixture não encontrada: ' . $path);
        }
        $this->document = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $this->document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $this->xpath = new DOMXPath($this->document);
    }

    public function text(string $id): string
    {
        $node = $this->document->getElementById($id);
        return $node instanceof DOMNode
            ? trim((string) preg_replace('/\s+/u', ' ', $node->textContent))
            : '';
    }

    public function list(string $id): array
    {
        $container = $this->document->getElementById($id);
        if (!$container instanceof DOMElement) {
            return [];
        }
        $items = [];
        foreach ($this->xpath->query('.//li', $container) ?: [] as $item) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $item->textContent));
            if ($text !== '') {
                $items[] = $text;
            }
        }
        return $items;
    }

    public function attribute(string $id, string $name): string
    {
        $node = $this->document->getElementById($id);
        return $node instanceof DOMElement ? $node->getAttribute($name) : '';
    }

    public function rows(string $id): array
    {
        $table = $this->document->getElementById($id);
        if (!$table instanceof DOMElement) {
            return [];
        }
        $rows = [];
        foreach ($this->xpath->query('.//tr', $table) ?: [] as $row) {
            if (!$row instanceof DOMElement || str_contains(strtolower($row->getAttribute('class')), 'pager')) {
                continue;
            }
            $cells = $this->xpath->query('./td', $row);
            if ($cells === false || $cells->length === 0) {
                continue;
            }
            if ($cells->length === 1 && $cells->item(0) instanceof DOMElement && $cells->item(0)->hasAttribute('colspan')) {
                continue;
            }
            $values = [];
            foreach ($cells as $cell) {
                $values[] = trim((string) preg_replace('/\s+/u', ' ', $cell->textContent));
            }
            $rows[] = $values;
        }
        return $rows;
    }

    public function hasPagination(string $id): bool
    {
        $table = $this->document->getElementById($id);
        return $table instanceof DOMElement
            && preg_match('/\b\d+\s*-\s*\d+\s+de\s+\d+\b/ui', $table->parentNode?->textContent ?? $table->textContent) === 1;
    }
}
