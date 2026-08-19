'use strict';

const $ = (selector) => document.querySelector(selector);
let duplicatePending = false;

function setFeedback(selector, message, success = false) {
  const node = $(selector);
  node.textContent = message || '';
  node.classList.toggle('success', success);
}

async function activeTab() {
  const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
  return tab || null;
}

async function sendToPage(message) {
  const tab = await activeTab();
  if (!tab?.id) throw new Error('Abra a consulta pública do CreaOne.');
  return chrome.tabs.sendMessage(tab.id, message);
}

function renderStatus(result) {
  const capture = result?.capture;
  $('[data-summary]').hidden = !capture;
  $('[data-dot]').classList.toggle('connected', Boolean(capture));
  $('[data-status]').textContent = capture ? 'CreaOne detectado' : 'CreaOne não detectado';
  if (!capture) return;
  $('[data-art]').textContent = capture.art.numero;
  $('[data-company]').textContent = capture.empresa.razao_social || 'Não informado';
  $('[data-professional]').textContent = capture.responsavel_tecnico.nome || 'Não informado';
  $('[data-activities]').textContent = String(capture.atividades.length);
  $('[data-works]').textContent = String(capture.obras.length);
  if (result.statusMessage) {
    const sent = result.statusMessage.includes('✓');
    setFeedback('[data-feedback]', result.statusMessage, sent);
    $('[data-open-atlantica]').hidden = !sent;
  }
}

async function refresh() {
  try {
    renderStatus(await sendToPage({ type: 'GET_STATUS' }));
  } catch {
    renderStatus(null);
  }
}

$('[data-settings]').addEventListener('submit', async (event) => {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  const baseUrl = String(form.get('baseUrl') || '').replace(/\/+$/, '');
  const connectionCode = String(form.get('connectionCode') || '').trim().toUpperCase();
  if (baseUrl !== 'http://localhost/sistema_atlantica') {
    setFeedback('[data-settings-feedback]', 'Use a URL local indicada.');
    return;
  }
  if (!/^[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}$/.test(connectionCode)) {
    setFeedback('[data-settings-feedback]', 'Código de conexão inválido.');
    return;
  }
  await chrome.storage.local.set({ baseUrl, connectionCode });
  const ping = await chrome.runtime.sendMessage({ type: 'PING_BRIDGE' });
  setFeedback('[data-settings-feedback]', ping?.ok ? 'Configuração salva e conexão confirmada.' : (ping?.message || 'Configuração salva.'), Boolean(ping?.ok));
});

$('[data-send]').addEventListener('click', async (event) => {
  const button = event.currentTarget;
  button.disabled = true;
  setFeedback('[data-feedback]', 'Enviando…');
  try {
    const result = await sendToPage({ type: 'SEND_CURRENT', force: duplicatePending });
    if (result?.duplicate) {
      duplicatePending = true;
      button.textContent = 'Enviar novamente';
      setFeedback('[data-feedback]', 'Esta ART já foi enviada.');
    } else if (result?.ok) {
      duplicatePending = false;
      button.textContent = 'Enviar para Atlântica';
      setFeedback('[data-feedback]', '✓ Enviado com sucesso', true);
      $('[data-open-atlantica]').hidden = false;
    } else {
      setFeedback('[data-feedback]', result?.message || 'Não foi possível enviar para Atlântica.');
    }
  } catch (error) {
    setFeedback('[data-feedback]', error.message || 'Não foi possível enviar para Atlântica.');
  } finally {
    button.disabled = false;
  }
});

$('[data-open-atlantica]').addEventListener('click', () => {
  chrome.runtime.sendMessage({ type: 'OPEN_ATLANTICA' });
});

chrome.storage.local.get(['baseUrl', 'connectionCode']).then((stored) => {
  if (stored.baseUrl) $('[name="baseUrl"]').value = stored.baseUrl;
  if (stored.connectionCode) $('[name="connectionCode"]').value = stored.connectionCode;
});
refresh();
