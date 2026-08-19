(() => {
  'use strict';

  const searchInputId = 'MainContent_Main_NumeroART_NumeroARTTxt';

  function prepareArtInput(root, art) {
    const normalized = String(art || '').trim();
    if (!/^\d{5,30}$/.test(normalized)) return { ok: false, message: 'Número da ART inválido.' };
    const input = root.getElementById(searchInputId);
    if (!input || input.tagName !== 'INPUT') return { ok: false, message: 'Campo da ART ainda não disponível.' };
    const view = input.ownerDocument?.defaultView || globalThis;
    const valueSetter = Object.getOwnPropertyDescriptor(view.HTMLInputElement.prototype, 'value')?.set;
    if (valueSetter) valueSetter.call(input, normalized);
    else input.value = normalized;
    input.dispatchEvent(new view.Event('input', { bubbles: true }));
    input.dispatchEvent(new view.Event('change', { bubbles: true }));
    input.focus({ preventScroll: true });
    return { ok: true, art: normalized };
  }

  globalThis.AtlanticaArtPreparer = Object.freeze({ searchInputId, prepareArtInput });
})();
