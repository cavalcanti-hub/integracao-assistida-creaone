'use strict';

const DEFAULT_BASE_URL = 'http://localhost/sistema_atlantica';
const CREA_URL = 'https://creanet1.creasp.org.br/_UI/Pages/ConsultaPublica/PesquisaART/PesquisaART.aspx';
const CREA_MATCH = 'https://creanet1.creasp.org.br/_UI/Pages/ConsultaPublica/PesquisaART/*';
const BRIDGE_ALARM = 'atlantica-bridge-cycle';
let commandPoll = null;

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
    credentials: 'omit',
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

async function acknowledgeCommand(commandId) {
  if (!commandId) return null;
  return bridgeRequest('browser_bridge_command.php', { ack: commandId });
}

async function activateTab(tab, targetUrl = null) {
  const update = { active: true };
  if (targetUrl) update.url = targetUrl;
  const active = await chrome.tabs.update(tab.id, update);
  if (Number.isInteger(tab.windowId)) {
    await chrome.windows.update(tab.windowId, { focused: true }).catch(() => {});
  }
  return active;
}

async function deliverArtNumber(tabId, art, attempts = 40) {
  for (let attempt = 0; attempt < attempts; attempt += 1) {
    try {
      const response = await chrome.tabs.sendMessage(tabId, { type: 'PREPARE_ART', art });
      if (response?.ok) return response;
    } catch {
      // A aba recém-criada ainda pode estar carregando o content script.
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error('O CreaOne abriu, mas o campo da ART ainda não ficou disponível.');
}

async function openCreaOneArt(art) {
  if (!/^\d{5,30}$/.test(String(art || ''))) {
    throw new Error('Número da ART inválido.');
  }
  const tabs = await chrome.tabs.query({ url: CREA_MATCH });
  let tab = tabs.find((candidate) => candidate.id) || null;
  const existingTab = Boolean(tab);
  if (tab) {
    tab = await activateTab(tab);
  } else {
    tab = await chrome.tabs.create({ url: CREA_URL, active: true });
  }
  if (!tab?.id) throw new Error('Não foi possível abrir a consulta pública do CreaOne.');
  if (existingTab) {
    try {
      await deliverArtNumber(tab.id, art, 4);
    } catch {
      await chrome.tabs.reload(tab.id);
      await deliverArtNumber(tab.id, art, 120);
    }
  } else {
    await deliverArtNumber(tab.id, art, 120);
  }
  return { ok: true, art };
}

async function processOpenArtCommand(command) {
  const art = String(command?.art || '');
  const commandId = String(command?.command_id || '');
  const result = await openCreaOneArt(art);
  await acknowledgeCommand(commandId);
  return { ...result, commandId };
}

async function openAtlantica() {
  const settings = await getSettings();
  const targetUrl = settings.baseUrl + '/public/#arts';
  const tabs = await chrome.tabs.query({ url: settings.baseUrl + '/public/*' });
  const tab = tabs.find((candidate) => candidate.id) || null;
  if (tab) {
    await activateTab(tab, targetUrl);
  } else {
    await chrome.tabs.create({ url: targetUrl, active: true });
  }
  return { ok: true };
}

async function sendCapture(message) {
  const fingerprint = String(message.fingerprint || '');
  if (!fingerprint) throw new Error('Não foi possível identificar a captura atual.');
  const sessionData = await chrome.storage.session.get('sentFingerprints');
  const sent = sessionData.sentFingerprints || {};
  if (sent[fingerprint] && !message.force) {
    return { ok: false, duplicate: true, message: 'Esta ART já foi enviada.' };
  }
  const result = await bridgeRequest('creaone_browser_import.php', message.payload);
  sent[fingerprint] = new Date().toISOString();
  await chrome.storage.session.set({ sentFingerprints: sent });
  return result;
}

async function pollCommand() {
  if (commandPoll) return commandPoll;
  commandPoll = (async () => {
    const result = await bridgeRequest('browser_bridge_command.php', { poll: true });
    if (!result.has_command || !result.command) return { ok: true, hasCommand: false };
    if (result.command.action !== 'open_art') throw new Error('Comando local não reconhecido.');
    await processOpenArtCommand(result.command);
    return { ok: true, hasCommand: true, commandId: result.command.command_id };
  })();
  try {
    return await commandPoll;
  } finally {
    commandPoll = null;
  }
}

async function bridgeCycle() {
  return Promise.allSettled([
    bridgeRequest('creaone_browser_ping.php')
  ]);
}

function configureAlarm() {
  chrome.alarms.create(BRIDGE_ALARM, { periodInMinutes: 0.5 });
}

chrome.runtime.onInstalled.addListener(() => {
  configureAlarm();
  bridgeCycle();
});

chrome.runtime.onStartup.addListener(() => {
  configureAlarm();
  bridgeCycle();
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === BRIDGE_ALARM) bridgeCycle();
});

chrome.runtime.onMessage.addListener((message, _sender, sendResponse) => {
  if (message?.type === 'SEND_CAPTURE') {
    sendCapture(message).then(sendResponse).catch((error) => sendResponse({ ok: false, message: error.message }));
    return true;
  }
  if (message?.type === 'PING_BRIDGE') {
    bridgeCycle()
      .then((results) => sendResponse({ ok: true, cycle: results }))
      .catch((error) => sendResponse({ ok: false, message: error.message }));
    return true;
  }
  if (message?.type === 'POLL_COMMAND') {
    pollCommand().then(sendResponse).catch((error) => sendResponse({ ok: false, message: error.message }));
    return true;
  }
  if (message?.type === 'OPEN_CREAONE_ART') {
    processOpenArtCommand(message.command || { art: message.art, command_id: message.commandId })
      .then(sendResponse)
      .catch((error) => sendResponse({ ok: false, message: error.message }));
    return true;
  }
  if (message?.type === 'OPEN_ATLANTICA') {
    openAtlantica().then(sendResponse).catch((error) => sendResponse({ ok: false, message: error.message }));
    return true;
  }
  return false;
});

configureAlarm();
bridgeCycle();
