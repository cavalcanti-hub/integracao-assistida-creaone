<?php

declare(strict_types=1);

final class BrowserImportValidator
{
    private const FORBIDDEN_KEYS = [
        'cookie', 'cookies', 'viewstate', 'turnstile', 'cfturnstileresponse',
        'authorization', 'aspxauth', 'aspnetsessionid',
    ];

    public function validate(array $payload): array
    {
        $this->rejectForbiddenKeys($payload);
        if (($payload['source'] ?? null) !== 'creaone_public_browser') {
            throw new InvalidArgumentException('Origem da captura inválida.');
        }

        $capturedAt = $this->text($payload['captured_at'] ?? null, 40, 'captured_at');
        try {
            new DateTimeImmutable($capturedAt);
        } catch (Throwable) {
            throw new InvalidArgumentException('Data da captura inválida.');
        }

        $art = $this->map($payload['art'] ?? null, [
            'numero' => 30,
            'modelo' => 200,
            'tipo' => 200,
            'situacao' => 200,
            'data_baixa' => 100,
            'motivo_baixa' => 1000,
            'art_vinculada_contrato' => 100,
        ], 'art');
        if (!preg_match('/^\d{5,30}$/', $art['numero'])) {
            throw new InvalidArgumentException('Número da ART inválido.');
        }

        $empresa = $this->map($payload['empresa'] ?? null, [
            'razao_social' => 500,
            'registro' => 100,
            'cnpj' => 30,
        ], 'empresa');
        $responsavel = $this->map($payload['responsavel_tecnico'] ?? null, [
            'nome' => 500,
            'registro' => 100,
            'rnp' => 100,
            'participacao' => 300,
            'forma_registro' => 300,
            'art_vinculada' => 100,
        ], 'responsavel_tecnico');
        $responsavel['titulos'] = $this->stringList(
            is_array($payload['responsavel_tecnico'] ?? null) ? ($payload['responsavel_tecnico']['titulos'] ?? []) : [],
            50,
            500,
            'responsavel_tecnico.titulos'
        );
        $contratante = $this->map($payload['contratante'] ?? null, [
            'nome' => 500,
            'tipo' => 200,
        ], 'contratante');

        $obrasInput = $this->arrayValue($payload['obras'] ?? null, 100, 'obras');
        $obras = [];
        foreach ($obrasInput as $index => $work) {
            $item = $this->map($work, [
                'cep' => 30,
                'endereco' => 1000,
                'data_inicio' => 100,
                'previsao_termino' => 100,
            ], 'obras.' . $index);
            $details = is_array($work) ? ($work['detalhes'] ?? null) : null;
            $item['detalhes'] = $details === null ? null : $this->map($details, [
                'cep' => 30,
                'tipo_logradouro' => 200,
                'logradouro' => 1000,
                'numero' => 100,
                'complemento' => 500,
                'bairro' => 500,
                'cidade' => 300,
                'uf' => 20,
                'pais' => 200,
                'coordenadas' => 300,
                'data_inicio' => 100,
                'previsao_termino' => 100,
                'finalidade' => 500,
                'codigo_obra_publica' => 200,
                'proprietario' => 500,
            ], 'obras.' . $index . '.detalhes');
            $obras[] = $item;
        }

        $activitiesInput = $this->arrayValue($payload['atividades'] ?? null, 200, 'atividades');
        $activities = [];
        foreach ($activitiesInput as $index => $activity) {
            $activities[] = $this->map($activity, [
                'nivel_atuacao' => 500,
                'atividade' => 1000,
                'obra_servico' => 1000,
                'complemento' => 1000,
                'quantidade' => 100,
                'unidade' => 100,
            ], 'atividades.' . $index);
        }

        return [
            'source' => 'creaone_public_browser',
            'captured_at' => $capturedAt,
            'art' => $art,
            'empresa' => $empresa,
            'responsavel_tecnico' => $responsavel,
            'contratante' => $contratante,
            'obras' => $obras,
            'atividades' => $activities,
            'observacoes' => $this->text($payload['observacoes'] ?? '', 10_000, 'observacoes'),
            'entidade_classe' => $this->text($payload['entidade_classe'] ?? '', 1000, 'entidade_classe'),
            'avisos' => $this->stringList($payload['avisos'] ?? [], 20, 500, 'avisos'),
        ];
    }

    private function map(mixed $value, array $fields, string $path): array
    {
        if (!is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Estrutura inválida em ' . $path . '.');
        }
        $result = [];
        foreach ($fields as $field => $limit) {
            $result[$field] = $this->text($value[$field] ?? '', $limit, $path . '.' . $field);
        }
        return $result;
    }

    private function arrayValue(mixed $value, int $limit, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InvalidArgumentException('Lista inválida em ' . $path . '.');
        }
        if (count($value) > $limit) {
            throw new InvalidArgumentException('Quantidade de itens excedida em ' . $path . '.');
        }
        return $value;
    }

    private function stringList(mixed $value, int $countLimit, int $textLimit, string $path): array
    {
        $items = $this->arrayValue($value, $countLimit, $path);
        $result = [];
        foreach ($items as $index => $item) {
            $result[] = $this->text($item, $textLimit, $path . '.' . $index);
        }
        return $result;
    }

    private function text(mixed $value, int $limit, string $path): string
    {
        if ($value === null) {
            return '';
        }
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('Texto inválido em ' . $path . '.');
        }
        $clean = trim(strip_tags($value));
        if (mb_strlen($clean, 'UTF-8') > $limit) {
            throw new InvalidArgumentException('Campo excede o limite em ' . $path . '.');
        }
        return $clean;
    }

    private function rejectForbiddenKeys(array $value): void
    {
        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
                if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
                    throw new InvalidArgumentException('Dado de sessão não permitido.');
                }
            }
            if (is_array($item)) {
                $this->rejectForbiddenKeys($item);
            }
        }
    }
}

