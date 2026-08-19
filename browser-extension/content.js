(() => {
  'use strict';

  const extractor = globalThis.AtlanticaExtractor;
  const artPreparer = globalThis.AtlanticaArtPreparer;
  const state = {
    preparedArt: '',
    artNumber: '',
    workDetails: new Map(),
    activeWorkIndex: null,
    duplicatePending: false,
    statusMessage: '',
    statusType: 'neutral',
    refreshQueued: false
  };
  let host = null;
  let shadow = null;

  function isModalVisible() {
    const modal = document.getElementById(extractor.ids.modal);
    if (!modal) return false;
    const style = getComputedStyle(modal);
    return !modal.hidden && style.display !== 'none' && style.visibility !== 'hidden';
  }

  function currentCapture() {
    const capture = extractor.extractArt(document);
    if (!capture) return null;
    if (capture.art.numero !== state.artNumber) {
      state.artNumber = capture.art.numero;
      state.workDetails.clear();
      state.activeWorkIndex = null;
      state.duplicatePending = false;
      state.statusMessage = '';
      state.statusType = 'neutral';
    }
    capture.obras = capture.obras.map((work, index) => ({
      ...work,
      detalhes: state.workDetails.get(index) || null
    }));
    capture.captured_at = new Date().toISOString();
    return capture;
  }

  async function fingerprint(capture) {
    const basis = [
      capture.art.numero,
      capture.art.situacao,
      capture.empresa.razao_social,
      capture.responsavel_tecnico.nome,
      capture.obras.length,
      capture.atividades.length
    ].join('|');
    const bytes = new TextEncoder().encode(basis);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return Array.from(new Uint8Array(digest)).map((byte) => byte.toString(16).padStart(2, '0')).join('');
  }

  function ensurePanel() {
    if (host?.isConnected) return;
    host = document.createElement('div');
    host.id = 'atlantica-creaone-bridge';
    shadow = host.attachShadow({ mode: 'closed' });
    shadow.innerHTML = `
      <style>
        :host{all:initial}.panel{position:fixed;right:18px;bottom:18px;z-index:2147483647;width:300px;max-width:calc(100vw - 36px);padding:17px;border:1px solid #cbd9df;border-radius:13px;background:#fff;box-shadow:0 18px 55px rgba(7,27,43,.22);font-family:Arial,sans-serif;color:#163044}.eyebrow{margin:0 0 5px;color:#168777;font-size:10px;font-weight:800;letter-spacing:.12em}.title{margin:0;font-size:17px}.art{margin:10px 0 4px;font-size:13px;font-weight:700}.guidance,.meta,.status,.warning{margin:5px 0;color:#617582;font-size:11px;line-height:1.45}.works{display:grid;gap:4px;margin:10px 0}.work{padding:5px 7px;border-radius:6px;background:#f3f6f7;color:#617582;font-size:10px}.work.done{background:#e7f5f1;color:#126b5f}.actions{display:grid;gap:8px;margin-top:14px}.button{min-height:39px;padding:9px 12px;border:0;border-radius:8px;background:#0b2539;color:#fff;cursor:pointer;font-size:11px;font-weight:800;text-transform:uppercase}.button.secondary{border:1px solid #cbd9df;background:#fff;color:#0b2539}.button:disabled{cursor:wait;opacity:.6}.status.success{color:#126b5f}.status.error{color:#b33a45}.warning{padding:8px;border-radius:7px;background:#fff5df;color:#765824}
      </style>
      <section class="panel" aria-label="Captura Atlântica">
        <p class="eyebrow">ATLÂNTICA</p>
        <h2 class="title" data-title>ART identificada</h2>
        <p class="art" data-art></p>
        <p class="guidance" data-guidance hidden></p>
        <p class="meta" data-meta></p>
        <div class="works" data-works></div>
        <p class="warning" data-warning hidden></p>
        <div class="actions">
          <button class="button secondary" type="button" data-capture-details hidden>Capturar detalhes desta obra</button>
          <button class="button" type="button" data-send>Enviar para Atlântica</button>
          <button class="button secondary" type="button" data-open-atlantica hidden>Abrir no Atlântica</button>
        </div>
        <p class="status" data-status></p>
      </section>`;
    shadow.querySelector('[data-send]').addEventListener('click', () => sendCurrent(state.duplicatePending));
    shadow.querySelector('[data-capture-details]').addEventListener('click', captureOpenModal);
    shadow.querySelector('[data-open-atlantica]').addEventListener('click', () => {
      chrome.runtime.sendMessage({ type: 'OPEN_ATLANTICA' }).catch(() => {});
    });
    document.documentElement.append(host);
  }

  function removePanel() {
    host?.remove();
    host = null;
    shadow = null;
  }

  function render() {
    const capture = currentCapture();
    if (!capture) {
      state.artNumber = '';
      state.workDetails.clear();
      const input = document.getElementById('MainContent_Main_NumeroART_NumeroARTTxt');
      if (!state.preparedArt || !input || input.value !== state.preparedArt) {
        removePanel();
        return;
      }
      ensurePanel();
      shadow.querySelector('[data-title]').textContent = 'ART preparada';
      shadow.querySelector('[data-art]').textContent = state.preparedArt;
      const guidance = shadow.querySelector('[data-guidance]');
      guidance.hidden = false;
      guidance.textContent = 'Agora conclua a verificação do site e clique em Buscar.';
      shadow.querySelector('[data-meta]').hidden = true;
      shadow.querySelector('[data-works]').hidden = true;
      shadow.querySelector('[data-warning]').hidden = true;
      shadow.querySelector('[data-capture-details]').hidden = true;
      shadow.querySelector('[data-send]').hidden = true;
      shadow.querySelector('[data-open-atlantica]').hidden = true;
      shadow.querySelector('[data-status]').textContent = '';
      return;
    }
    ensurePanel();
    shadow.querySelector('[data-title]').textContent = 'ART ENCONTRADA';
    shadow.querySelector('[data-art]').textContent = capture.art.numero;
    shadow.querySelector('[data-guidance]').hidden = true;
    const meta = shadow.querySelector('[data-meta]');
    meta.hidden = false;
    meta.textContent = `${capture.obras.length} obra(s) · ${capture.atividades.length} atividade(s)`;
    const works = shadow.querySelector('[data-works]');
    works.hidden = false;
    works.replaceChildren(...capture.obras.map((_work, index) => {
      const captured = state.workDetails.has(index);
      const item = document.createElement('span');
      item.className = `work${captured ? ' done' : ''}`;
      item.textContent = `Obra ${index + 1} ${captured ? '✓ detalhes capturados' : '— detalhes pendentes'}`;
      return item;
    }));
    const warning = shadow.querySelector('[data-warning]');
    warning.hidden = capture.avisos.length === 0;
    warning.textContent = capture.avisos.join(' ');
    const detailsButton = shadow.querySelector('[data-capture-details]');
    detailsButton.hidden = !isModalVisible();
    const sendButton = shadow.querySelector('[data-send]');
    sendButton.hidden = false;
    sendButton.textContent = state.duplicatePending ? 'Enviar novamente' : 'Enviar para Atlântica';
    shadow.querySelector('[data-open-atlantica]').hidden = state.statusType !== 'success';
    const status = shadow.querySelector('[data-status]');
    status.textContent = state.statusMessage;
    status.className = `status ${state.statusType}`;
  }

  function captureOpenModal() {
    if (!isModalVisible()) return;
    const capture = currentCapture();
    if (!capture?.obras.length) return;
    let index = state.activeWorkIndex;
    const details = extractor.extractWorkDetails(document);
    if (!Number.isInteger(index) || index < 0 || index >= capture.obras.length) {
      index = capture.obras.findIndex((work) => work.cep && work.cep === details.cep);
    }
    if (index < 0 && capture.obras.length === 1) index = 0;
    if (index < 0) {
      state.statusMessage = 'Não foi possível associar o modal a uma obra.';
      state.statusType = 'error';
      render();
      return;
    }
    state.workDetails.set(index, details);
    state.statusMessage = `Obra ${index + 1} ✓ detalhes capturados`;
    state.statusType = 'success';
    render();
  }

  async function sendCurrent(force = false) {
    const capture = currentCapture();
    if (!capture || !shadow) return { ok: false, message: 'Nenhuma ART identificada.' };
    const button = shadow.querySelector('[data-send]');
    button.disabled = true;
    state.statusMessage = 'Enviando…';
    state.statusType = 'neutral';
    render();
    try {
      const result = await chrome.runtime.sendMessage({
        type: 'SEND_CAPTURE',
        payload: capture,
        fingerprint: await fingerprint(capture),
        force
      });
      if (result?.duplicate) {
        state.duplicatePending = true;
        state.statusMessage = 'Esta ART já foi enviada.';
        state.statusType = 'error';
      } else if (result?.ok) {
        state.duplicatePending = false;
        state.statusMessage = '✓ ART enviada com sucesso';
        state.statusType = 'success';
      } else {
        state.statusMessage = result?.message || 'Não foi possível enviar para Atlântica.';
        state.statusType = 'error';
      }
      render();
      return result;
    } catch (error) {
      state.statusMessage = 'Não foi possível enviar para Atlântica.';
      state.statusType = 'error';
      render();
      return { ok: false, message: error.message };
    } finally {
      if (shadow) shadow.querySelector('[data-send]').disabled = false;
    }
  }

  function queueRender(mutations = []) {
    if (host && mutations.length && mutations.every((mutation) => host.contains(mutation.target))) return;
    if (state.refreshQueued) return;
    state.refreshQueued = true;
    setTimeout(() => {
      state.refreshQueued = false;
      render();
    }, 100);
  }

  function prepareArt(art) {
    const result = artPreparer.prepareArtInput(document, art);
    if (!result.ok) return result;
    state.preparedArt = result.art;
    state.artNumber = '';
    state.workDetails.clear();
    state.activeWorkIndex = null;
    state.duplicatePending = false;
    state.statusMessage = '';
    state.statusType = 'neutral';
    render();
    return result;
  }

  document.addEventListener('click', (event) => {
    const table = document.getElementById(extractor.ids.obras);
    const target = event.target instanceof Element ? event.target.closest('a,button,input') : null;
    const row = target?.closest('tr');
    if (!table || !row || !table.contains(row)) return;
    state.activeWorkIndex = extractor.getDataRows(table).indexOf(row);
  }, true);

  chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
    if (message?.type === 'GET_STATUS') {
      const capture = currentCapture();
      sendResponse({
        detected: Boolean(capture),
        capture,
        detailsCaptured: state.workDetails.size,
        statusMessage: state.statusMessage
      });
      return false;
    }
    if (message?.type === 'SEND_CURRENT') {
      sendCurrent(Boolean(message.force)).then(sendResponse);
      return true;
    }
    if (message?.type === 'PREPARE_ART') {
      sendResponse(prepareArt(message.art));
      return false;
    }
    return false;
  });

  const observer = new MutationObserver(queueRender);
  observer.observe(document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true });
  queueRender();
  chrome.runtime.sendMessage({ type: 'PING_BRIDGE' }).catch(() => {});
  setInterval(() => chrome.runtime.sendMessage({ type: 'PING_BRIDGE' }).catch(() => {}), 10000);
})();
