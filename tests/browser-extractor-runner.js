(() => {
  'use strict';

  window.addEventListener('load', () => {
    const extractor = globalThis.AtlanticaExtractor;
    const preparer = globalThis.AtlanticaArtPreparer;
    const fixture = (id) => document.getElementById(id).contentDocument;
    const normal = extractor.extractArt(fixture('normal'));
    const withoutCompany = extractor.extractArt(fixture('without-company'));
    const emptyFields = extractor.extractArt(fixture('empty-fields'));
    const searchDocument = fixture('without-result');
    const searchInput = searchDocument.getElementById(preparer.searchInputId);
    const editEvents = [];
    searchInput.addEventListener('input', () => editEvents.push('input'));
    searchInput.addEventListener('change', () => editEvents.push('change'));
    const prepared = preparer.prepareArtInput(searchDocument, '28027230230943447');
    const withoutResult = extractor.extractArt(searchDocument);
    const modal = extractor.extractWorkDetails(fixture('modal'));
    const assertions = {
      art_normal: normal?.art.numero === '28027230230943447',
      duas_atividades: normal?.atividades.length === 2,
      quantidade_textual: normal?.atividades[0]?.quantidade === '12,50',
      uma_obra_sem_pager: normal?.obras.length === 1,
      aviso_paginacao: normal?.avisos.length === 1,
      titulos: normal?.responsavel_tecnico.titulos.length === 2,
      sem_empresa: withoutCompany?.empresa.razao_social === '',
      campos_vazios: emptyFields?.art.modelo === '',
      campo_art_preenchido: prepared.ok && searchInput.value === '28027230230943447',
      eventos_edicao: editEvents.join(',') === 'input,change',
      campo_pesquisa_nao_e_resultado: withoutResult === null,
      modal_detalhes: modal.cidade === 'São Paulo' && modal.proprietario === 'Proprietário de Teste'
    };
    const passed = Object.values(assertions).every(Boolean);
    document.body.dataset.testStatus = passed ? 'passed' : 'failed';
    document.getElementById('test-output').textContent = JSON.stringify(assertions);
  });
})();
