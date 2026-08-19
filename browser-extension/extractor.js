(() => {
  'use strict';

  const prefix = 'MainContent_Main_UCARTRes1025ObraServico_';
  const ids = {
    art: {
      numero: prefix + 'SpanNumeroART',
      modelo: prefix + 'SpanModeloART',
      tipo: prefix + 'SpanTipoART',
      situacao: prefix + 'SpanSituacaoART',
      data_baixa: prefix + 'SpanDataBaixa',
      motivo_baixa: prefix + 'SpanMotivoBaixa',
      art_vinculada_contrato: prefix + 'SpanNumeroARTVinculadaContrato'
    },
    empresa: {
      razao_social: prefix + 'SpanRazaoSocialEmpresaContratada',
      registro: prefix + 'SpanRegistroEmpresaContratada',
      cnpj: prefix + 'SpanCNPJEmpresaContratada'
    },
    responsavel_tecnico: {
      nome: prefix + 'SpanNomeResponsavelTecnico',
      registro: prefix + 'SpanRegistroProfissional',
      rnp: prefix + 'SpanRNPProfissional',
      participacao: prefix + 'SpanParticipacaoTecnica',
      forma_registro: prefix + 'SpanFormaRegistro',
      art_vinculada: prefix + 'SpanNumeroARTVinculadaFormaRegistro'
    },
    contratante: {
      nome: prefix + 'BoxTitleDadosContrato_SpanNomeRazaoSocialContratante',
      tipo: prefix + 'BoxTitleDadosContrato_SpanTipoContratante'
    },
    titulos: prefix + 'ListaTitulosProfissional',
    obras: prefix + 'BoxTitleDadosContrato_GrdDadosObraServico',
    atividades: prefix + 'BoxTitleAtividadesTecnicas_GrdAtividadesTecnicas',
    observacoes: prefix + 'SpanObservacoes',
    entidade_classe: prefix + 'SpanEntidadeClasse',
    modal: 'modal-detalhes-obra-servico',
    detalhes: {
      cep: prefix + 'SpanDetalhesCEP',
      tipo_logradouro: prefix + 'SpanDetalhesTipoLogradouro',
      logradouro: prefix + 'SpanDetalhesLogradouro',
      numero: prefix + 'SpanDetalhesNumero',
      complemento: prefix + 'SpanDetalhesComplemento',
      bairro: prefix + 'SpanDetalhesBairro',
      cidade: prefix + 'SpanDetalhesCidade',
      uf: prefix + 'SpanDetalhesUF',
      pais: prefix + 'SpanDetalhesPais',
      coordenadas: prefix + 'SpanDetalhesCoordenadasGeograficas',
      data_inicio: prefix + 'SpanDetalhesDataInicio',
      previsao_termino: prefix + 'SpanDetalhesPrevisaoTermino',
      finalidade: prefix + 'SpanDetalhesFinalidade',
      codigo_obra_publica: prefix + 'SpanDetalhesCodigoObraPublica',
      proprietario: prefix + 'SpanDetalhesProprietario'
    }
  };

  function normalizeText(value) {
    return String(value ?? '').replace(/\s+/g, ' ').trim();
  }

  function normalizeHeader(value) {
    return normalizeText(value)
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .toLowerCase();
  }

  function getTextById(id, root = document) {
    return normalizeText(root.getElementById(id)?.textContent ?? '');
  }

  function getListById(id, root = document) {
    const container = root.getElementById(id);
    if (!container) return [];
    const items = Array.from(container.querySelectorAll('li'))
      .map((item) => normalizeText(item.textContent))
      .filter(Boolean);
    if (items.length) return items;
    const text = normalizeText(container.textContent);
    return text ? [text] : [];
  }

  function getDataRows(table) {
    if (!table) return [];
    return Array.from(table.querySelectorAll('tr')).filter((row) => {
      const className = String(row.className || '').toLowerCase();
      if (className.includes('pager') || className.includes('pagination')) return false;
      const cells = Array.from(row.children).filter((cell) => cell.tagName === 'TD');
      if (!cells.length) return false;
      if (cells.length === 1 && (cells[0].hasAttribute('colspan') || cells[0].querySelector('select, .pagination, .pager'))) return false;
      return true;
    });
  }

  function getTableRows(id, root = document) {
    const table = root.getElementById(id);
    if (!table) return { headers: [], rows: [], pagination_warning: null };
    const headerRow = Array.from(table.querySelectorAll('tr')).find((row) => row.querySelector('th'));
    const headers = headerRow
      ? Array.from(headerRow.querySelectorAll('th')).map((cell) => normalizeHeader(cell.textContent))
      : [];
    const rows = getDataRows(table).map((row) =>
      Array.from(row.children)
        .filter((cell) => cell.tagName === 'TD')
        .map((cell) => normalizeText(cell.textContent))
    );
    const contextText = normalizeText(table.parentElement?.textContent ?? table.textContent);
    const pagination = contextText.match(/\b\d+\s*-\s*\d+\s+de\s+\d+\b/i);
    return {
      headers,
      rows,
      pagination_warning: pagination
        ? 'Há mais registros no CreaOne que não estão visíveis nesta página.'
        : null
    };
  }

  function columnIndex(headers, aliases, fallback) {
    for (const alias of aliases) {
      const index = headers.findIndex((header) => header === alias || header.includes(alias));
      if (index >= 0) return index;
    }
    return fallback;
  }

  function extractWorks(root = document) {
    const table = getTableRows(ids.obras, root);
    const indexes = {
      cep: columnIndex(table.headers, ['cep'], 0),
      endereco: columnIndex(table.headers, ['endereco', 'logradouro'], 1),
      data_inicio: columnIndex(table.headers, ['data de inicio', 'data inicio', 'inicio'], 2),
      previsao_termino: columnIndex(table.headers, ['previsao de termino', 'previsao termino', 'termino'], 3)
    };
    return {
      items: table.rows.map((cells) => ({
        cep: cells[indexes.cep] || '',
        endereco: cells[indexes.endereco] || '',
        data_inicio: cells[indexes.data_inicio] || '',
        previsao_termino: cells[indexes.previsao_termino] || '',
        detalhes: null
      })),
      warning: table.pagination_warning
    };
  }

  function extractActivities(root = document) {
    const table = getTableRows(ids.atividades, root);
    const indexes = {
      nivel_atuacao: columnIndex(table.headers, ['nivel de atuacao'], 0),
      atividade: columnIndex(table.headers, ['atividade'], 1),
      obra_servico: columnIndex(table.headers, ['obra/servico', 'obra servico'], 2),
      complemento: columnIndex(table.headers, ['complemento'], 3),
      quantidade: columnIndex(table.headers, ['quantidade'], 4),
      unidade: columnIndex(table.headers, ['unidade'], 5)
    };
    return {
      items: table.rows.map((cells) => ({
        nivel_atuacao: cells[indexes.nivel_atuacao] || '',
        atividade: cells[indexes.atividade] || '',
        obra_servico: cells[indexes.obra_servico] || '',
        complemento: cells[indexes.complemento] || '',
        quantidade: cells[indexes.quantidade] || '',
        unidade: cells[indexes.unidade] || ''
      })),
      warning: table.pagination_warning
    };
  }

  function mapIds(definition, root = document) {
    return Object.fromEntries(Object.entries(definition).map(([key, id]) => [key, getTextById(id, root)]));
  }

  function extractArt(root = document) {
    const numero = getTextById(ids.art.numero, root);
    if (!numero) return null;
    const works = extractWorks(root);
    const activities = extractActivities(root);
    const warnings = [works.warning, activities.warning].filter(Boolean);
    return {
      source: 'creaone_public_browser',
      captured_at: new Date().toISOString(),
      art: mapIds(ids.art, root),
      empresa: mapIds(ids.empresa, root),
      responsavel_tecnico: {
        ...mapIds(ids.responsavel_tecnico, root),
        titulos: getListById(ids.titulos, root)
      },
      contratante: mapIds(ids.contratante, root),
      obras: works.items,
      atividades: activities.items,
      observacoes: getTextById(ids.observacoes, root),
      entidade_classe: getTextById(ids.entidade_classe, root),
      avisos: warnings
    };
  }

  function extractWorkDetails(root = document) {
    return mapIds(ids.detalhes, root);
  }

  globalThis.AtlanticaExtractor = Object.freeze({
    ids,
    normalizeText,
    getTextById,
    getListById,
    getTableRows,
    getDataRows,
    extractArt,
    extractWorkDetails
  });
})();

