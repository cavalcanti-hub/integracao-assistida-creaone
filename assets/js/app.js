(() => {
    'use strict';

    const apiRoot = '../api/';
    const state = {
        csrf: document.querySelector('meta[name="csrf-token"]')?.content || '',
        session: null,
        art: null,
        artSource: null,
        browserImport: null,
        browserImportId: null,
        bridgeCode: '',
        openArtTracker: null,
        openArtCommandId: '',
        openArtTrackerStartedAt: 0,
        busy: false,
    };

    const $ = (selector, root = document) => root.querySelector(selector);
    const $$ = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function element(tag, className = '', text = null) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== null && text !== undefined) node.textContent = String(text);
        return node;
    }

    function value(input) {
        if (input === null || input === undefined || input === '') return 'Não informado';
        if (Array.isArray(input)) return input.length ? input.join(' · ') : 'Não informado';
        return String(input);
    }

    function formatBytes(bytes) {
        const number = Number(bytes || 0);
        if (number < 1024) return `${number} B`;
        if (number < 1024 * 1024) return `${(number / 1024).toFixed(number > 10240 ? 0 : 1)} KB`;
        return `${(number / (1024 * 1024)).toFixed(1)} MB`;
    }

    function formatDate(input) {
        if (!input) return 'Não informado';
        const date = new Date(input);
        return Number.isNaN(date.getTime()) ? String(input) : date.toLocaleString('pt-BR');
    }

    async function request(endpoint, form = null) {
        const options = { credentials: 'same-origin', headers: { Accept: 'application/json' } };
        if (form) {
            options.method = 'POST';
            options.body = form;
            options.headers['X-CSRF-Token'] = state.csrf;
        }
        const response = await fetch(apiRoot + endpoint, options);
        let data;
        try {
            data = await response.json();
        } catch {
            throw new Error('O servidor local retornou uma resposta inválida.');
        }
        if (!response.ok) throw new Error(data.message || 'Não foi possível concluir a operação.');
        return data;
    }

    async function sendBridgeCommand(payload) {
        if (!state.bridgeCode) throw new Error('A ponte local ainda não foi carregada. Aguarde um instante.');
        const response = await fetch(apiRoot + 'creaone_open_art.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Atlantica-Bridge-Token': state.bridgeCode,
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.message || 'Não foi possível enviar a consulta para a extensão.');
        }
        return data;
    }

    async function queryBridgeCommand(payload) {
        if (!state.bridgeCode) throw new Error('A ponte local ainda não foi carregada. Aguarde um instante.');
        const response = await fetch(apiRoot + 'browser_bridge_command.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Atlantica-Bridge-Token': state.bridgeCode,
            },
            body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => null);
        if (!response.ok || !data?.ok) {
            throw new Error(data?.message || 'Não foi possível consultar o estado do comando local.');
        }
        return data;
    }

    function setOpenArtFeedback(message, kind = 'neutral') {
        const feedback = $('#open-art-feedback');
        feedback.className = 'form-feedback';
        feedback.textContent = message || '';
        if (kind === 'success') feedback.classList.add('is-success');
        if (kind === 'error') feedback.classList.add('is-error');
    }

    function stopOpenArtTracker() {
        if (state.openArtTracker !== null) {
            window.clearInterval(state.openArtTracker);
            state.openArtTracker = null;
        }
        state.openArtCommandId = '';
        state.openArtTrackerStartedAt = 0;
    }

    function startOpenArtTracker(commandId) {
        stopOpenArtTracker();
        state.openArtCommandId = commandId;
        state.openArtTrackerStartedAt = Date.now();

        const tick = async () => {
            if (!state.openArtCommandId) return;
            try {
                const result = await queryBridgeCommand({ status: state.openArtCommandId });
                const command = result.command;
                if (!command) {
                    if (Date.now() - state.openArtTrackerStartedAt > 5000) {
                        setOpenArtFeedback('A extensão não confirmou o recebimento do comando.', 'error');
                    }
                    return;
                }

                if (command.status === 'pending') {
                    if (Date.now() - state.openArtTrackerStartedAt > 5000) {
                        setOpenArtFeedback('A extensão não confirmou o recebimento do comando.', 'error');
                        return;
                    }
                    setOpenArtFeedback('Comando pendente...');
                    return;
                }

                if (command.status === 'delivered') {
                    setOpenArtFeedback('Extensão recebeu o comando. Abrindo CreaOne...');
                    return;
                }

                if (command.status === 'acknowledged') {
                    setOpenArtFeedback('Extensão confirmou o comando.', 'success');
                    stopOpenArtTracker();
                }
            } catch (error) {
                if (Date.now() - state.openArtTrackerStartedAt > 5000) {
                    setOpenArtFeedback(error.message || 'A extensão não confirmou o recebimento do comando.', 'error');
                }
            }
        };

        tick();
        state.openArtTracker = window.setInterval(tick, 1000);
    }

    async function withBusy(button, operation) {
        if (state.busy) return;
        state.busy = true;
        const original = button.textContent;
        button.disabled = true;
        button.classList.add('is-busy');
        button.textContent = button.dataset.busyLabel || 'Aguarde…';
        try {
            return await operation();
        } finally {
            button.disabled = false;
            button.classList.remove('is-busy');
            button.textContent = original;
            state.busy = false;
        }
    }

    function toast(message, type = 'success') {
        const region = $('#toast-region');
        const item = element('div', `toast${type === 'error' ? ' toast--error' : ''}`, message);
        region.append(item);
        window.setTimeout(() => item.remove(), 5500);
    }

    function route() {
        const raw = window.location.hash.replace('#', '') || 'geral';
        const aliases = { resultado: 'arts', consulta: 'arts', sessao: 'captura' };
        const current = aliases[raw] || raw;
        const valid = ['geral', 'captura', 'arts', 'diagnostico'].includes(current) ? current : 'geral';
        $$('[data-page]').forEach((page) => { page.hidden = page.dataset.page !== valid; });
        $$('[data-route]').forEach((link) => link.classList.toggle('is-active', link.dataset.route === valid));
        document.title = `${$(`[data-page="${valid}"] h1`)?.textContent || 'Laboratório CreaOne'} | Atlântica`;
        window.scrollTo({ top: 0, behavior: 'instant' });
        if (raw === 'resultado' && state.art) window.setTimeout(() => $('#art-result')?.scrollIntoView(), 50);
    }

    function renderSession(session) {
        state.session = session;
        const summary = $('#import-summary');
        summary.hidden = !session.configured;
        if (session.configured && session.import) renderImportSummary(session.import);
        if (session.current_art) $('#art-number').value = session.current_art;
        if (session.last_request) renderDiagnostic(session.last_request);
    }

    function renderImportSummary(info) {
        const body = $('#import-summary-body');
        body.replaceChildren();
        const rows = [
            ['URL', info.url],
            ['Método', info.method],
            ['Host', info.host],
            ['Cookies detectados', info.cookie_names || []],
            ['VIEWSTATE detectado', info.viewstate_detected ? `Sim · ${formatBytes(info.viewstate_bytes)}` : 'Não'],
            ['Turnstile detectado', info.turnstile_detected ? 'Sim · valor protegido' : 'Não'],
            ['ART capturada', value(info.art_captured)],
        ];
        rows.forEach(([label, content]) => {
            const row = document.createElement('tr');
            row.append(element('td', '', label));
            const cell = element('td');
            if (Array.isArray(content)) {
                const list = element('div', 'cookie-list');
                if (content.length) content.forEach((name) => list.append(element('span', 'cookie-tag', name)));
                else list.textContent = 'Nenhum cookie detectado';
                cell.append(list);
            } else {
                cell.textContent = String(content);
            }
            row.append(cell);
            body.append(row);
        });
    }

    function renderReplay(result) {
        const section = $('#replay-result');
        const content = $('#replay-result-content');
        section.hidden = false;
        content.replaceChildren();
        const banner = element('div', `response-banner ${result.ok ? 'response-banner--success' : 'response-banner--error'}`, result.message);
        content.append(banner);
        const diagnostic = result.diagnostic;
        if (diagnostic) {
            const metrics = element('div', 'replay-metrics');
            [
                ['HTTP status', diagnostic.http_status || '—'],
                ['Tempo total', `${diagnostic.duration_ms || 0} ms`],
                ['Resposta', formatBytes(diagnostic.response_bytes)],
                ['Delta válido', diagnostic.delta_valid ? 'SIM' : 'NÃO'],
                ['UpdatePanel', (diagnostic.update_panel_found ?? diagnostic.update_panel) ? 'Identificado' : 'Não identificado'],
                ['Tamanho do UpdatePanel', formatBytes(diagnostic.update_panel_bytes)],
                ['Número da ART no HTML', diagnostic.art_number_found_in_html ? 'SIM' : 'NÃO'],
                ['UCARTRes encontrado', diagnostic.ucartres_found ? 'SIM' : 'NÃO'],
                ['SpanNumeroART encontrado', diagnostic.span_numero_art_found ? 'SIM' : 'NÃO'],
                ['Classificação', diagnostic.classification || 'Não classificada'],
            ].forEach(([label, metric]) => {
                const item = element('div');
                item.append(element('span', '', label), element('strong', '', metric));
                metrics.append(item);
            });
            content.append(metrics);

            if (Array.isArray(diagnostic.error_texts_found) && diagnostic.error_texts_found.length) {
                const serverMessage = element('div', 'response-banner response-banner--server');
                serverMessage.append(element('strong', '', 'Mensagem recebida do CREA:'));
                diagnostic.error_texts_found.slice(0, 3).forEach((message) => {
                    serverMessage.append(element('p', '', `“${message}”`));
                });
                content.append(serverMessage);
            }
        }
        if (result.ok && result.art) {
            const actions = element('div', 'action-row');
            actions.append(element('p', 'privacy-note', 'Os dados estruturados estão disponíveis na tela ARTs Capturadas.'));
            const link = element('a', 'button button--primary', 'Abrir resultado');
            link.href = '#resultado';
            actions.append(link);
            content.append(actions);
        }
    }

    function detailCard(title, fields) {
        const card = element('article', 'detail-card');
        card.append(element('h3', '', title));
        const list = element('div', 'detail-list');
        fields.forEach(([label, content]) => {
            const item = element('div');
            item.append(element('span', '', label), element('strong', '', value(content)));
            list.append(item);
        });
        card.append(list);
        return card;
    }

    function renderArt(art, source = 'browser') {
        if (!art) return;
        state.art = art;
        state.artSource = source;
        $('#captured-art-empty').hidden = true;
        $('#art-source-label').textContent = source === 'browser' ? 'Dados recebidos pelo navegador' : 'Resultado experimental / diagnóstico';
        const container = $('#art-cards');
        container.replaceChildren(
            detailCard('ART', [
                ['Número', art.art?.numero], ['Situação', art.art?.situacao], ['Modelo', art.art?.modelo],
                ['Tipo', art.art?.tipo], ['Data de baixa', art.art?.data_baixa], ['Motivo', art.art?.motivo_baixa],
            ]),
            detailCard('Empresa contratada', [
                ['Razão social', art.empresa?.razao_social], ['CNPJ', art.empresa?.cnpj], ['Registro CREA', art.empresa?.registro],
            ]),
            detailCard('Responsável técnico', [
                ['Nome', art.responsavel_tecnico?.nome], ['Registro profissional', art.responsavel_tecnico?.registro],
                ['RNP', art.responsavel_tecnico?.rnp], ['Participação', art.responsavel_tecnico?.participacao],
                ['Forma de registro', art.responsavel_tecnico?.forma_registro], ['ART vinculada', art.responsavel_tecnico?.art_vinculada],
                ['Títulos', art.responsavel_tecnico?.titulos],
            ]),
            detailCard('Contratante', [
                ['Nome', art.contratante?.nome], ['Tipo', art.contratante?.tipo],
            ]),
        );
        renderWorks(art.obras || []);
        renderActivities(art.atividades || []);
        $('#art-observations').textContent = value(art.observacoes);
        $('#art-entity').textContent = value(art.entidade_classe);
        const warnings = $('#capture-warnings');
        const items = Array.isArray(art.avisos) ? art.avisos.filter(Boolean) : [];
        warnings.hidden = items.length === 0;
        warnings.replaceChildren(...items.map((warning) => element('p', '', warning)));
        $('#art-result').hidden = false;
    }

    function createTable(headers, rows) {
        const table = document.createElement('table');
        const head = document.createElement('thead');
        const headerRow = document.createElement('tr');
        headers.forEach((header) => headerRow.append(element('th', '', header)));
        head.append(headerRow);
        const body = document.createElement('tbody');
        rows.forEach((cells) => {
            const row = document.createElement('tr');
            cells.forEach((cellContent) => {
                const cell = document.createElement('td');
                if (cellContent instanceof Node) cell.append(cellContent);
                else cell.textContent = value(cellContent);
                row.append(cell);
            });
            body.append(row);
        });
        table.append(head, body);
        return table;
    }

    function renderWorks(works) {
        const container = $('#works-container');
        container.replaceChildren();
        if (!works.length) {
            container.append(element('p', 'empty-inline', 'Nenhuma obra foi identificada na resposta.'));
            return;
        }
        const rows = works.map((work, index) => {
            let action = 'Indisponível';
            if (work.detalhes || (state.artSource !== 'browser' && (work.event_target || index === 0))) {
                const button = element('button', 'table-action', '◉ Ver detalhes');
                button.type = 'button';
                button.dataset.workIndex = String(index);
                action = button;
            }
            if (state.artSource === 'browser' && !work.detalhes) action = 'Detalhes pendentes';
            return [work.cep, work.endereco, work.data_inicio, work.previsao_termino, action];
        });
        container.append(createTable(['CEP', 'Endereço', 'Data início', 'Previsão término', 'Ação'], rows));
    }

    function renderActivities(activities) {
        const container = $('#activities-container');
        container.replaceChildren();
        if (!activities.length) {
            container.append(element('p', 'empty-inline', 'Nenhuma atividade técnica foi identificada na resposta.'));
            return;
        }
        const rows = activities.map((item) => [
            item.nivel_atuacao, item.atividade, item.obra_servico,
            item.complemento, item.quantidade, item.unidade,
        ]);
        container.append(createTable(['Nível de atuação', 'Atividade', 'Obra/Serviço', 'Complemento', 'Quantidade', 'Unidade'], rows));
    }

    function renderDiagnostic(diagnostic) {
        if (!diagnostic) return;
        $('#diagnostic-empty').hidden = true;
        const grid = $('#diagnostic-grid');
        grid.hidden = false;
        grid.replaceChildren();
        const yesNo = (flag) => {
            const node = element('span', `boolean ${flag ? 'boolean--yes' : 'boolean--no'}`, flag ? 'SIM' : 'NÃO');
            return node;
        };
        const fields = [
            ['URL chamada', diagnostic.url], ['Método', diagnostic.method],
            ['HTTP status', diagnostic.http_status || '—'], ['Content-Type', diagnostic.content_type || 'Não informado'],
            ['Tempo total', `${diagnostic.duration_ms || 0} ms`], ['Tamanho da resposta', formatBytes(diagnostic.response_bytes)],
            ['UpdatePanel identificado', yesNo(diagnostic.update_panel)], ['VIEWSTATE atualizado', yesNo(diagnostic.viewstate_updated)],
            ['ART encontrada', yesNo(diagnostic.art_found)], ['Modal encontrado', yesNo(diagnostic.modal_found)],
            ['Turnstile enviado', yesNo(diagnostic.turnstile_sent)], ['Horário', formatDate(diagnostic.timestamp)],
        ];
        fields.forEach(([label, content]) => {
            const wrapper = document.createElement('div');
            wrapper.append(element('dt', '', label));
            const data = document.createElement('dd');
            if (content instanceof Node) data.append(content);
            else data.textContent = value(content);
            wrapper.append(data);
            grid.append(wrapper);
        });
    }

    function renderBridgeStatus(status) {
        const connected = Boolean(status.connected);
        state.bridgeCode = status.connection_code || '';
        const statusNode = $('#bridge-status');
        statusNode.classList.toggle('is-connected', connected);
        $('#bridge-status-text').textContent = connected ? 'Conectada' : 'Não conectada';
        $('#capture-extension-status').textContent = connected ? 'Conectada' : 'Não conectada';
        const lastContact = status.last_seen
            ? `Último contato: ${formatDate(status.last_seen)}`
            : 'Aguardando o primeiro heartbeat da extensão.';
        $('#capture-extension-last').textContent = lastContact;
        $('#overview-extension').textContent = connected ? 'Conectada' : 'Não conectada';
        $('#overview-extension-meta').textContent = lastContact;
        $('#bridge-code').textContent = status.connection_code || 'Não configurado';
        $('#bridge-base-url').textContent = status.base_url || 'http://localhost/sistema_atlantica';
    }

    function renderBrowserImport(record) {
        if (!record) return;
        state.browserImport = record;
        state.browserImportId = record.import_id || null;
        $('#last-capture-panel').hidden = false;
        $('#last-capture-title').textContent = 'ART recebida com sucesso';
        $('#last-capture-pill').hidden = false;
        $('#last-capture-action').hidden = false;
        $('#last-capture-art').textContent = value(record.art?.numero);
        $('#last-capture-time').textContent = formatDate(record.received_at || record.captured_at);
        $('#last-capture-company').textContent = value(record.empresa?.razao_social);
        $('#last-capture-professional').textContent = value(record.responsavel_tecnico?.nome);
        $('#overview-art').textContent = value(record.art?.numero);
        $('#overview-captured').textContent = `Recebida em ${formatDate(record.received_at || record.captured_at)}`;
        $('#overview-works').textContent = String(record.obras?.length || 0);
        $('#overview-activities').textContent = String(record.atividades?.length || 0);
        renderArt(record, 'browser');
    }

    async function refreshBrowserBridge() {
        const [statusResult, importResult] = await Promise.all([
            request('browser_bridge_status.php'),
            request('creaone_last_import.php'),
        ]);
        renderBridgeStatus(statusResult);
        if (importResult.has_import && importResult.import) {
            const importId = importResult.import.import_id || null;
            if (importId !== state.browserImportId || !state.art) {
                renderBrowserImport(importResult.import);
            }
        }
    }

    function openDetails(details) {
        const fields = [
            ['CEP', details.cep], ['Tipo de logradouro', details.tipo_logradouro], ['Logradouro', details.logradouro],
            ['Número', details.numero], ['Complemento', details.complemento], ['Bairro', details.bairro],
            ['Cidade', details.cidade], ['UF', details.uf], ['País', details.pais],
            ['Coordenadas', details.coordenadas], ['Data de início', details.data_inicio],
            ['Previsão de término', details.previsao_termino], ['Finalidade', details.finalidade],
            ['Código de obra pública', details.codigo_obra_publica], ['Proprietário', details.proprietario],
        ];
        const list = $('#modal-details');
        list.replaceChildren();
        fields.forEach(([label, content]) => {
            const item = element('div');
            item.append(element('span', '', label), element('strong', '', value(content)));
            list.append(item);
        });
        const modal = $('#work-modal');
        if (typeof modal.showModal === 'function') modal.showModal();
        else modal.setAttribute('open', '');
    }

    $('#open-art-number').addEventListener('input', (event) => {
        event.currentTarget.value = event.currentTarget.value.replace(/\D/g, '').slice(0, 30);
    });

    $('#open-art-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const art = String(new FormData(form).get('art') || '').trim();
        if (!/^\d{5,30}$/.test(art)) {
            stopOpenArtTracker();
            setOpenArtFeedback('Digite um número de ART válido, usando somente números.', 'error');
            return;
        }
        await withBusy(event.submitter || $('#open-art-button'), async () => {
            try {
                stopOpenArtTracker();
                setOpenArtFeedback('Enviando para a extensão...');
                const result = await sendBridgeCommand({ art });
                setOpenArtFeedback('Comando pendente...');
                startOpenArtTracker(result.command_id);
                toast(result.message);
            } catch (error) {
                stopOpenArtTracker();
                setOpenArtFeedback(error.message, 'error');
                toast(error.message, 'error');
            }
        });
    });

    $('#import-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const formElement = event.currentTarget;
        const button = event.submitter;
        await withBusy(button, async () => {
            try {
                const form = new FormData(formElement);
                const result = await request('importar_curl.php', form);
                renderSession(result.session);
                renderImportSummary(result.import);
                toast(result.message);
            } catch (error) {
                toast(error.message, 'error');
            }
        });
    });

    $('#replay-button').addEventListener('click', async (event) => {
        await withBusy(event.currentTarget, async () => {
            try {
                const result = await request('testar_replay.php', new FormData());
                renderReplay(result);
                if (result.session) renderSession(result.session);
                if (result.diagnostic) renderDiagnostic(result.diagnostic);
                if (result.art) renderArt(result.art, 'diagnostic');
                toast(result.message, result.ok ? 'success' : 'error');
            } catch (error) {
                toast(error.message, 'error');
            }
        });
    });

    $('#query-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const button = event.submitter;
        await withBusy(button, async () => {
            try {
                const form = new FormData(event.currentTarget);
                const result = await request('consultar.php', form);
                if (result.session) renderSession(result.session);
                if (result.diagnostic) renderDiagnostic(result.diagnostic);
                if (!result.ok) {
                    toast(result.message, 'error');
                    return;
                }
                renderArt(result.art, 'diagnostic');
                $('#art-result').scrollIntoView({ behavior: 'smooth', block: 'start' });
                toast(result.message);
            } catch (error) {
                toast(error.message, 'error');
            }
        });
    });

    $('#works-container').addEventListener('click', async (event) => {
        const button = event.target.closest('[data-work-index]');
        if (!button) return;
        const index = Number(button.dataset.workIndex);
        if (state.artSource === 'browser') {
            const details = state.art?.obras?.[index]?.detalhes;
            if (details) openDetails(details);
            return;
        }
        await withBusy(button, async () => {
            try {
                const form = new FormData();
                form.set('index', button.dataset.workIndex);
                const result = await request('detalhes_obra.php', form);
                if (result.session) renderSession(result.session);
                if (result.diagnostic) renderDiagnostic(result.diagnostic);
                if (!result.ok) {
                    toast(result.message, 'error');
                    return;
                }
                openDetails(result.details);
                if ((!state.art?.atividades?.length) && result.atividades?.length) renderActivities(result.atividades);
            } catch (error) {
                toast(error.message, 'error');
            }
        });
    });

    $('#clear-session').addEventListener('click', async () => {
        if (!window.confirm('Remover do PHP local a captura importada e todos os estados associados?')) return;
        try {
            const result = await request('limpar_sessao.php', new FormData());
            const fresh = await request('status.php');
            if (state.artSource !== 'browser') {
                state.art = null;
                state.artSource = null;
                $('#art-result').hidden = true;
                $('#captured-art-empty').hidden = false;
            }
            $('#import-summary').hidden = true;
            $('#replay-result').hidden = true;
            $('#diagnostic-grid').hidden = true;
            $('#diagnostic-empty').hidden = false;
            renderSession(fresh.session);
            toast(result.message);
        } catch (error) {
            toast(error.message, 'error');
        }
    });

    $$('[data-close-modal]').forEach((button) => button.addEventListener('click', () => $('#work-modal').close()));
    $('#work-modal').addEventListener('click', (event) => {
        if (event.target === event.currentTarget) event.currentTarget.close();
    });
    window.addEventListener('hashchange', route);

    async function initialize() {
        route();
        const [legacy, bridge] = await Promise.allSettled([
            request('status.php'),
            refreshBrowserBridge(),
        ]);
        if (legacy.status === 'fulfilled') {
            state.csrf = legacy.value.csrf || state.csrf;
            renderSession(legacy.value.session);
        }
        if (bridge.status === 'rejected') {
            toast(`Falha ao verificar a extensão: ${bridge.reason.message}`, 'error');
        }
        window.setInterval(() => refreshBrowserBridge().catch(() => {}), 5000);
    }

    initialize();
})();
