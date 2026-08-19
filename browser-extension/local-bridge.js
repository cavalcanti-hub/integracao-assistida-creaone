(() => {
  'use strict';

  const DEFAULT_BASE_URL = 'http://localhost/sistema_atlantica';
  let polling = false;
  let currentCommandId = '';
  let currentCommandAt = 0;

  function normalizeBaseUrl(value) {
    const url = new URL(String(value || DEFAULT_BASE_URL));
    const validHost = url.hostname === 'localhost';
    const validPath = url.pathname.replace(/\/+$/, '') === '/sistema_atlantica';
    if (url.protocol !== 'http:' || !validHost || !validPath || url.search || url.hash || url.username || url.password) {
      throw new Error('Use http://localhost/sistema_atlantica como URL local.');
    }
    return url.origin + '/sistema_atlantica';
  }

  async function getSettings() {
    const stored = await chrome.storage.local.get(['baseUrl', 'connectionCode']);
    return {
      baseUrl: normalizeBaseUrl(stored.baseUrl || DEFAULT_BASE_URL),
      connectionCode: String(stored.connectionCode || '').trim().toUpperCase()
    };
  }

  async function bridgeRequest(endpoint, payload = null) {
    const settings = await getSettings();
    if (!settings.connectionCode) throw new Error('Configure o código de conexão da Atlântica.');
    const response = await fetch(settings.baseUrl + '/api/' + endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Atlantica-Bridge-Token': settings.connectionCode
      },
      body: JSON.stringify(payload || {})
    });
    const data = await response.json().catch(() => null);
    if (!response.ok || !data?.ok) {
      throw new Error(data?.message || `Falha HTTP ${response.status} ao comunicar com a Atlântica.`);
    }
    return data;
  }

  function setFeedback(message, type = 'neutral') {
    const feedback = document.getElementById('open-art-feedback');
    if (!feedback) return;
    feedback.textContent = message || '';
    feedback.className = 'form-feedback';
    if (type === 'success') feedback.classList.add('is-success');
    if (type === 'error') feedback.classList.add('is-error');
  }

  async function pollCommand() {
    if (polling || document.visibilityState === 'hidden') return;
    polling = true;
    try {
      const result = await bridgeRequest('browser_bridge_command.php', { poll: true });
      if (!result.has_command || !result.command) return;
      const commandId = String(result.command.command_id || '');
      if (!commandId || (commandId === currentCommandId && Date.now() - currentCommandAt < 4000)) return;
      currentCommandId = commandId;
      currentCommandAt = Date.now();
      setFeedback('Extensão recebeu o comando. Abrindo CreaOne…');
      const response = await chrome.runtime.sendMessage({ type: 'OPEN_CREAONE_ART', command: result.command });
      if (!response?.ok) {
        setFeedback(response?.message || 'A extensão não confirmou o recebimento do comando.', 'error');
        return;
      }
      setFeedback('CreaOne aberto. Confirmando comando…', 'success');
      await bridgeRequest('browser_bridge_command.php', { ack: commandId });
      setFeedback('Extensão confirmou o comando.', 'success');
      currentCommandId = '';
      currentCommandAt = 0;
    } catch {
      // O próximo ciclo tenta novamente; nenhum dado da página é lido.
    } finally {
      polling = false;
    }
  }

  function heartbeat() {
    chrome.runtime.sendMessage({ type: 'PING_BRIDGE' }).catch(() => {});
  }

  // Heartbeat apenas informa que a extensão está instalada.
  // Comandos de abertura não são consultados automaticamente: isso evita
  // redirecionamentos e criação repetida de abas do CreaOne.
  heartbeat();
  setInterval(heartbeat, 10000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      heartbeat();
    }
  });
})();
