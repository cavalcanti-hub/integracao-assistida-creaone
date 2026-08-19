<?php

declare(strict_types=1);

final class ArtParser
{
    public function parse(string $html): array
    {
        [$document, $xpath] = $this->load($html);

        return [
            'art' => [
                'numero' => $this->field($xpath, ['SpanNumeroART', 'LblNumeroART', 'NumeroARTResultado'], ['Número da ART']),
                'modelo' => $this->field($xpath, ['SpanModeloART', 'LblModeloART', 'SpanModelo'], ['Modelo da ART']),
                'tipo' => $this->field($xpath, ['SpanTipoART', 'LblTipoART'], ['Tipo da ART']),
                'situacao' => $this->field($xpath, ['SpanSituacaoART', 'LblSituacaoART', 'SpanSituacao'], ['Situação da ART']),
                'data_baixa' => $this->field($xpath, ['SpanDataBaixa', 'LblDataBaixa'], ['Data de baixa']),
                'motivo_baixa' => $this->field($xpath, ['SpanMotivoBaixa', 'LblMotivoBaixa'], ['Motivo de baixa']),
            ],
            'empresa' => [
                'razao_social' => $this->field($xpath, ['SpanEmpresaContratada', 'LblEmpresaContratada', 'SpanRazaoSocialEmpresa'], ['Empresa contratada', 'Razão social']),
                'registro' => $this->field($xpath, ['SpanRegistroEmpresa', 'LblRegistroEmpresa'], ['Registro da empresa', 'Registro CREA']),
                'cnpj' => $this->field($xpath, ['SpanCNPJEmpresa', 'LblCNPJEmpresa', 'SpanCNPJ'], ['CNPJ da empresa', 'CNPJ']),
            ],
            'responsavel_tecnico' => [
                'nome' => $this->field($xpath, ['SpanResponsavelTecnico', 'LblResponsavelTecnico'], ['Responsável técnico']),
                'registro' => $this->field($xpath, ['SpanRegistroProfissional', 'LblRegistroProfissional'], ['Registro profissional']),
                'rnp' => $this->field($xpath, ['SpanRNP', 'LblRNP'], ['RNP']),
                'participacao' => $this->field($xpath, ['SpanParticipacaoTecnica', 'LblParticipacaoTecnica'], ['Participação técnica']),
                'forma_registro' => $this->field($xpath, ['SpanFormaRegistro', 'LblFormaRegistro'], ['Forma de registro']),
                'art_vinculada' => $this->field($xpath, ['SpanARTVinculada', 'LblARTVinculada'], ['ART vinculada']),
                'titulos' => $this->listField($xpath, ['TitulosProfissionais', 'TituloProfissional'], ['Títulos profissionais']),
            ],
            'contratante' => [
                'nome' => $this->field($xpath, ['SpanContratante', 'LblContratante', 'SpanNomeContratante'], ['Contratante']),
                'tipo' => $this->field($xpath, ['SpanTipoContratante', 'LblTipoContratante'], ['Tipo de contratante']),
            ],
            'obras' => $this->extractWorks($xpath),
            'atividades' => $this->extractActivitiesFromXpath($xpath),
            'observacoes' => $this->field($xpath, ['SpanObservacoes', 'LblObservacoes', 'ObservacaoART'], ['Observações']),
            'entidade_classe' => $this->field($xpath, ['SpanEntidadeClasse', 'LblEntidadeClasse'], ['Entidade de classe']),
        ];
    }

    public function hasArtResult(string $html): bool
    {
        [, $xpath] = $this->load($html);
        if (($xpath->query('//*[contains(@id, "UCARTRes")]')?->length ?? 0) > 0) {
            return true;
        }

        foreach (['Situação da ART', 'Responsável técnico', 'Dados da Obra/Serviço', 'Atividades técnicas'] as $label) {
            if ($this->findLabelNode($xpath, $label) instanceof DOMNode) {
                return true;
            }
        }
        return false;
    }

    public function parseWorkDetails(string $html): array
    {
        [, $xpath] = $this->load($html);
        $modalFound = ($xpath->query('//*[@id="modal-detalhes-obra-servico" or contains(@id, "modal-detalhes-obra-servico")]')?->length ?? 0) > 0;

        return [
            'modal_found' => $modalFound,
            'details' => [
                'cep' => $this->field($xpath, ['SpanDetalhesCEP'], ['CEP']),
                'tipo_logradouro' => $this->field($xpath, ['SpanDetalhesTipoLogradouro'], ['Tipo de Logradouro']),
                'logradouro' => $this->field($xpath, ['SpanDetalhesLogradouro'], ['Logradouro']),
                'numero' => $this->field($xpath, ['SpanDetalhesNumero'], ['Número']),
                'complemento' => $this->field($xpath, ['SpanDetalhesComplemento'], ['Complemento']),
                'bairro' => $this->field($xpath, ['SpanDetalhesBairro'], ['Bairro']),
                'cidade' => $this->field($xpath, ['SpanDetalhesCidade'], ['Cidade']),
                'uf' => $this->field($xpath, ['SpanDetalhesUF'], ['UF']),
                'pais' => $this->field($xpath, ['SpanDetalhesPais'], ['País']),
                'coordenadas' => $this->field($xpath, ['SpanDetalhesCoordenadasGeograficas', 'SpanDetalhesCoordenadas'], ['Coordenadas Geográficas']),
                'data_inicio' => $this->field($xpath, ['SpanDetalhesDataInicio'], ['Data de Início']),
                'previsao_termino' => $this->field($xpath, ['SpanDetalhesPrevisaoTermino'], ['Previsão de Término']),
                'finalidade' => $this->field($xpath, ['SpanDetalhesFinalidade'], ['Finalidade']),
                'codigo_obra_publica' => $this->field($xpath, ['SpanDetalhesCodigoObraPublica'], ['Código de Obra Pública']),
                'proprietario' => $this->field($xpath, ['SpanDetalhesProprietario'], ['Proprietário']),
            ],
            'atividades' => $this->extractActivitiesFromXpath($xpath),
        ];
    }

    private function extractWorks(DOMXPath $xpath): array
    {
        $tables = $xpath->query('//table[contains(@id, "GrdDadosObraServico")]');
        if (!$tables || $tables->length === 0) {
            $tables = $xpath->query('//table[.//*[contains(@name, "BtnVisualizar") or contains(@href, "BtnVisualizar") or contains(@onclick, "BtnVisualizar")]]');
        }
        if (!$tables || $tables->length === 0) {
            return [];
        }

        $table = $tables->item(0);
        if (!$table instanceof DOMElement) {
            return [];
        }
        $headers = $this->tableHeaders($xpath, $table);
        $works = [];
        foreach ($xpath->query('.//tr[td]', $table) ?: [] as $row) {
            $cells = $xpath->query('./td', $row);
            if (!$cells || $cells->length === 0 || $this->isPagerRow($row)) {
                continue;
            }
            $raw = [];
            foreach ($cells as $index => $cell) {
                $header = $headers[$index] ?? 'coluna_' . ($index + 1);
                $raw[$header] = $this->nodeText($cell);
            }
            $target = $this->eventTarget($xpath, $row);
            if ($target === null && implode('', $raw) === '') {
                continue;
            }
            $works[] = [
                'cep' => $this->column($raw, ['cep']),
                'endereco' => $this->column($raw, ['endereco', 'logradouro', 'obra servico', 'obra/servico']),
                'data_inicio' => $this->column($raw, ['data inicio', 'inicio']),
                'previsao_termino' => $this->column($raw, ['previsao termino', 'termino']),
                'event_target' => $target,
                'campos' => $raw,
            ];
        }
        return $works;
    }

    private function extractActivitiesFromXpath(DOMXPath $xpath): array
    {
        $tables = $xpath->query('//table[contains(@id, "GrdAtividadesTecnicas")]');
        if (!$tables || $tables->length === 0) {
            foreach ($xpath->query('//table') ?: [] as $candidate) {
                $text = $this->ascii($this->nodeText($candidate));
                if (str_contains($text, 'nivel de atuacao') && str_contains($text, 'quantidade') && str_contains($text, 'unidade')) {
                    $tables = [$candidate];
                    break;
                }
            }
        }
        if (!$tables || count($tables) === 0) {
            return [];
        }

        $table = $tables instanceof DOMNodeList ? $tables->item(0) : $tables[0];
        if (!$table instanceof DOMElement) {
            return [];
        }
        $headers = $this->tableHeaders($xpath, $table);
        $activities = [];
        foreach ($xpath->query('.//tr[td]', $table) ?: [] as $row) {
            if ($this->isPagerRow($row)) {
                continue;
            }
            $cells = $xpath->query('./td', $row);
            if (!$cells || $cells->length < 2) {
                continue;
            }
            $raw = [];
            foreach ($cells as $index => $cell) {
                $raw[$headers[$index] ?? 'coluna_' . ($index + 1)] = $this->nodeText($cell);
            }
            $activity = [
                'nivel_atuacao' => $this->column($raw, ['nivel de atuacao']),
                'atividade' => $this->column($raw, ['atividade']),
                'obra_servico' => $this->column($raw, ['obra servico', 'obra/servico']),
                'complemento' => $this->column($raw, ['complemento']),
                'quantidade' => $this->column($raw, ['quantidade']),
                'unidade' => $this->column($raw, ['unidade']),
            ];
            if (implode('', $activity) !== '') {
                $activities[] = $activity;
            }
        }
        return $activities;
    }

    private function field(DOMXPath $xpath, array $idFragments, array $labels): ?string
    {
        foreach ($xpath->query('//*[@id]') ?: [] as $node) {
            $id = $node instanceof DOMElement ? $node->getAttribute('id') : '';
            foreach ($idFragments as $fragment) {
                if ($fragment !== '' && str_ends_with(strtolower($id), strtolower($fragment))) {
                    $value = $this->nodeText($node);
                    if ($value !== '') {
                        return $value;
                    }
                }
            }
        }

        foreach ($labels as $label) {
            $labelNode = $this->findLabelNode($xpath, $label);
            if (!$labelNode instanceof DOMNode) {
                continue;
            }
            $value = $this->valueNearLabel($labelNode, $label);
            if ($value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function listField(DOMXPath $xpath, array $idFragments, array $labels): array
    {
        foreach ($xpath->query('//*[@id]') ?: [] as $node) {
            $id = $node instanceof DOMElement ? $node->getAttribute('id') : '';
            foreach ($idFragments as $fragment) {
                if (!str_contains(strtolower($id), strtolower($fragment))) {
                    continue;
                }
                $items = [];
                foreach ($xpath->query('.//li', $node) ?: [] as $item) {
                    $text = $this->nodeText($item);
                    if ($text !== '') {
                        $items[] = $text;
                    }
                }
                if ($items !== []) {
                    return array_values(array_unique($items));
                }
                $text = $this->nodeText($node);
                if ($text !== '') {
                    return [$text];
                }
            }
        }

        $single = $this->field($xpath, [], $labels);
        return $single === null ? [] : [$single];
    }

    private function findLabelNode(DOMXPath $xpath, string $label): ?DOMNode
    {
        $wanted = $this->ascii($label);
        foreach ($xpath->query('//label|//span|//strong|//b|//dt|//th|//div|//p') ?: [] as $node) {
            $text = rtrim($this->ascii($this->nodeText($node)), ':');
            if ($text === $wanted) {
                return $node;
            }
        }
        return null;
    }

    private function valueNearLabel(DOMNode $labelNode, string $label): string
    {
        for ($sibling = $labelNode->nextSibling; $sibling; $sibling = $sibling->nextSibling) {
            $value = $this->nodeText($sibling);
            if ($value !== '' && $this->ascii($value) !== $this->ascii($label)) {
                return $value;
            }
        }

        $parent = $labelNode->parentNode;
        if ($parent instanceof DOMNode) {
            for ($sibling = $parent->nextSibling; $sibling; $sibling = $sibling->nextSibling) {
                $value = $this->nodeText($sibling);
                if ($value !== '' && $this->ascii($value) !== $this->ascii($label)) {
                    return $value;
                }
            }
            $whole = $this->nodeText($parent);
            $pattern = '/^' . preg_quote($label, '/') . '\s*:?\s*/iu';
            $withoutLabel = trim((string) preg_replace($pattern, '', $whole, 1));
            if ($withoutLabel !== '' && $withoutLabel !== $whole) {
                return $withoutLabel;
            }
        }
        return '';
    }

    private function tableHeaders(DOMXPath $xpath, DOMElement $table): array
    {
        $headers = [];
        foreach ($xpath->query('.//tr[th][1]/th', $table) ?: [] as $index => $header) {
            $headers[$index] = $this->ascii($this->nodeText($header));
        }
        return $headers;
    }

    private function column(array $raw, array $aliases): ?string
    {
        foreach ($raw as $header => $value) {
            $normalized = $this->ascii((string) $header);
            foreach ($aliases as $alias) {
                if ($normalized === $alias || str_contains($normalized, $alias)) {
                    return $value === '' ? null : $value;
                }
            }
        }
        return null;
    }

    private function eventTarget(DOMXPath $xpath, DOMNode $row): ?string
    {
        foreach ($xpath->query('.//*[@name or @href or @onclick]', $row) ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $name = $node->getAttribute('name');
            if (str_ends_with($name, '$BtnVisualizar')) {
                return $name;
            }
            $script = $node->getAttribute('href') . ' ' . $node->getAttribute('onclick');
            if (preg_match('/__doPostBack\(\s*([\'\"])([^\'\"]+\$BtnVisualizar)\1/', $script, $match)) {
                return html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return null;
    }

    private function isPagerRow(DOMNode $row): bool
    {
        return $row instanceof DOMElement
            && (str_contains(strtolower($row->getAttribute('class')), 'pager')
                || str_contains(strtolower($row->getAttribute('class')), 'pagination'));
    }

    private function nodeText(?DOMNode $node): string
    {
        if (!$node instanceof DOMNode) {
            return '';
        }
        if ($node instanceof DOMElement) {
            if (strtolower($node->tagName) === 'input') {
                return $this->clean($node->getAttribute('value'));
            }
            if (strtolower($node->tagName) === 'select') {
                foreach ($node->getElementsByTagName('option') as $option) {
                    if ($option->hasAttribute('selected')) {
                        return $this->clean($option->textContent);
                    }
                }
            }
        }
        return $this->clean($node->textContent ?? '');
    }

    private function clean(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function ascii(string $value): string
    {
        $value = mb_strtolower($this->clean($value), 'UTF-8');
        $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        return $converted === false ? $value : strtolower($converted);
    }

    private function load(string $html): array
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><!doctype html><html><body><div id="__art_root">' . $html . '</div></body></html>',
            LIBXML_NOWARNING | LIBXML_NOERROR
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return [$document, new DOMXPath($document)];
    }
}
