<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
sendSecurityHeaders();
$csrf = htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $csrf ?>">
    <meta name="description" content="Integração assistida para importar dados públicos de ART já renderizados no CreaOne.">
    <title>Captura CreaOne | Atlântica Gestão Técnica</title>
    <link rel="stylesheet" href="../assets/css/app.css">
</head>
<body>
    <header class="topbar">
        <div class="topbar__inner">
            <a class="brand" href="#geral" aria-label="Atlântica Gestão Técnica — início">
                <span class="brand__mark" aria-hidden="true">A</span>
                <span>
                    <strong>ATLÂNTICA GESTÃO TÉCNICA</strong>
                    <small>Integração Assistida CreaOne</small>
                </span>
            </a>
            <nav class="main-nav" aria-label="Navegação principal">
                <a href="#geral" data-route="geral">Visão Geral</a>
                <a href="#captura" data-route="captura">Captura CreaOne</a>
                <a href="#arts" data-route="arts">ARTs Capturadas</a>
                <a href="#diagnostico" data-route="diagnostico">Diagnóstico</a>
            </nav>
        </div>
    </header>

    <main class="shell">
        <section class="page" data-page="geral">
            <div class="page-heading page-heading--split">
                <div>
                    <p class="eyebrow">Captura assistida pelo navegador</p>
                    <h1>Visão geral da integração</h1>
                    <p>Receba no Atlântica os dados públicos que o CreaOne já exibiu no seu navegador.</p>
                </div>
                <div class="environment-badge"><span></span> Sem automação em massa</div>
            </div>

            <div class="status-grid" aria-label="Resumo da captura assistida">
                <article class="status-card">
                    <div class="status-card__icon status-card__icon--navy" aria-hidden="true">E</div>
                    <div><span>Extensão</span><strong id="overview-extension">Não conectada</strong><small id="overview-extension-meta">Aguardando comunicação local</small></div>
                </article>
                <article class="status-card">
                    <div class="status-card__icon status-card__icon--teal" aria-hidden="true">A</div>
                    <div><span>Última ART</span><strong id="overview-art">Nenhuma</strong><small id="overview-captured">Aguardando captura</small></div>
                </article>
                <article class="status-card">
                    <div class="status-card__icon status-card__icon--blue" aria-hidden="true">O</div>
                    <div><span>Obras</span><strong id="overview-works">0</strong><small>Linhas visíveis capturadas</small></div>
                </article>
                <article class="status-card">
                    <div class="status-card__icon status-card__icon--amber" aria-hidden="true">T</div>
                    <div><span>Atividades técnicas</span><strong id="overview-activities">0</strong><small>Linhas visíveis capturadas</small></div>
                </article>
            </div>

            <div class="overview-layout">
                <article class="panel panel--accent">
                    <div class="panel__header">
                        <div><p class="eyebrow">Fluxo principal</p><h2>Captura em quatro passos</h2></div>
                    </div>
                    <ol class="steps">
                        <li><span>1</span><div><strong>Informe a ART no Atlântica</strong><p>Use a tela Captura CreaOne para iniciar o fluxo.</p></div></li>
                        <li><span>2</span><div><strong>Abra a consulta preparada</strong><p>A extensão abre o CreaOne e preenche somente o número da ART.</p></div></li>
                        <li><span>3</span><div><strong>Conclua a consulta manualmente</strong><p>Faça a verificação do site e clique em Buscar.</p></div></li>
                        <li><span>4</span><div><strong>Visualize no sistema</strong><p>Volte ao Atlântica para conferir os dados estruturados recebidos.</p></div></li>
                    </ol>
                    <a class="button button--primary" href="#captura">Consultar ART</a>
                </article>

                <aside class="panel compliance-card">
                    <div class="compliance-card__seal" aria-hidden="true">✓</div>
                    <div>
                        <p class="eyebrow">Limites do laboratório</p>
                        <h2>Limites preservados</h2>
                    </div>
                    <ul class="check-list">
                        <li>A consulta e a verificação continuam manuais</li>
                        <li>Somente o DOM público já renderizado é lido</li>
                        <li>Nenhuma requisição é feita ao CREA pela extensão</li>
                        <li>Cookies, tokens e VIEWSTATE nunca são coletados</li>
                    </ul>
                </aside>
            </div>
        </section>

        <section class="page" data-page="captura" hidden>
            <div class="page-heading">
                <p class="eyebrow">Captura CreaOne</p>
                <h1>Consultar ART</h1>
                <p>Informe uma ART para abrir a consulta pública do CreaOne.</p>
            </div>

            <article class="panel panel--accent open-art-panel">
                <div class="panel__header"><div><p class="eyebrow">Consultar ART no CreaOne</p><h2>Preparar consulta no navegador</h2></div></div>
                <form id="open-art-form">
                    <label class="field-label" for="open-art-number">Número da ART</label>
                    <div class="query-control">
                        <input id="open-art-number" name="art" type="text" inputmode="numeric" pattern="[0-9]{5,30}" maxlength="30" placeholder="Digite o número da ART" autocomplete="off" required>
                        <button class="button button--primary" id="open-art-button" type="submit" data-busy-label="Enviando…">Abrir consulta no CreaOne</button>
                    </div>
                    <p class="form-feedback" id="open-art-feedback" aria-live="polite"></p>
                </form>
            </article>

            <div class="capture-layout">
                <article class="panel extension-status-card">
                    <div class="panel__header panel__header--split">
                        <div><p class="eyebrow">Status da extensão</p><h2 id="capture-extension-status">Não conectada</h2></div>
                        <span class="connection-status" id="bridge-status"><span></span><b id="bridge-status-text">Não conectada</b></span>
                    </div>
                    <p id="capture-extension-last">Aguardando o primeiro heartbeat da extensão.</p>
                </article>

                <article class="panel">
                    <div class="panel__header"><div><p class="eyebrow">Configuração da extensão</p><h2>Conexão local</h2></div></div>
                    <div class="bridge-config">
                        <div>
                            <span>URL da Atlântica</span>
                            <code id="bridge-base-url">http://localhost/sistema_atlantica</code>
                        </div>
                        <div>
                            <span>Código de conexão</span>
                            <code id="bridge-code">Carregando…</code>
                        </div>
                    </div>
                    <p class="privacy-note">Este código autentica apenas a comunicação local entre a extensão e o Atlântica. Ele não pertence ao CREA.</p>
                </article>
            </div>

            <section class="panel last-capture" id="last-capture-panel" aria-live="polite">
                <div class="panel__header panel__header--split">
                    <div><p class="eyebrow">Última ART recebida</p><h2 id="last-capture-title">Nenhuma ART recebida</h2></div>
                    <span class="pill pill--success" id="last-capture-pill" hidden>Recebida</span>
                </div>
                <dl class="capture-summary">
                    <div><dt>ART</dt><dd id="last-capture-art">—</dd></div>
                    <div><dt>Horário</dt><dd id="last-capture-time">—</dd></div>
                    <div><dt>Empresa</dt><dd id="last-capture-company">—</dd></div>
                    <div><dt>Responsável</dt><dd id="last-capture-professional">—</dd></div>
                </dl>
                <div class="action-row action-row--end" id="last-capture-action" hidden>
                    <a class="button button--primary" href="#arts">Visualizar ART</a>
                </div>
            </section>

            <article class="panel how-it-works">
                <div class="panel__header"><div><p class="eyebrow">Como funciona</p><h2>Da consulta à captura</h2></div></div>
                <ol class="steps steps--compact">
                    <li><span>1</span><div><strong>Informe a ART.</strong></div></li>
                    <li><span>2</span><div><strong>O CreaOne será aberto com o número preenchido.</strong></div></li>
                    <li><span>3</span><div><strong>Faça a verificação e clique em Buscar.</strong></div></li>
                    <li><span>4</span><div><strong>Clique em Enviar para Atlântica.</strong></div></li>
                </ol>
            </article>

            <details class="advanced-tools">
                <summary>
                    <span><strong>Ferramentas de diagnóstico avançado</strong><small>Importação de cURL e Replay Exato</small></span>
                    <span class="pill pill--warning">Experimental / diagnóstico</span>
                </summary>
                <div class="advanced-tools__body">
                    <div class="panel">
                        <div class="panel__header"><div><p class="eyebrow">Captura legada</p><h2>Importar requisição cURL</h2></div></div>
                        <form id="import-form">
                            <label class="field-label" for="curl-input">Requisição do Chrome</label>
                            <textarea id="curl-input" name="curl" rows="13" spellcheck="false" autocomplete="off" placeholder="curl 'https://creanet1.creasp.org.br/.../PesquisaART.aspx' \
  -H 'x-microsoftajax: Delta=true' \
  --data-raw '__VIEWSTATE=...'" required></textarea>
                            <div class="form-footer">
                                <p class="privacy-note"><span aria-hidden="true">◆</span> Ferramenta mantida para diagnóstico técnico. O cURL não é o fluxo principal.</p>
                                <button class="button button--primary" type="submit" data-busy-label="Importando…">Importar cURL</button>
                            </div>
                        </form>
                    </div>

                    <section class="panel import-summary" id="import-summary" hidden aria-live="polite">
                        <div class="panel__header panel__header--split">
                            <div><p class="eyebrow">Captura analisada</p><h2>Dados detectados</h2></div>
                            <span class="pill pill--success">Importação concluída</span>
                        </div>
                        <div class="table-wrap">
                            <table class="key-table"><tbody id="import-summary-body"></tbody></table>
                        </div>
                        <div class="action-row">
                            <button class="button button--secondary" id="clear-session" type="button">Limpar sessão</button>
                            <button class="button button--primary" id="replay-button" type="button" data-busy-label="Testando…">Testar Replay Exato</button>
                        </div>
                    </section>

                    <section class="panel replay-result" id="replay-result" hidden aria-live="polite">
                        <div class="panel__header panel__header--split">
                            <div><p class="eyebrow">Modo A</p><h2>Resultado do Replay Exato</h2></div>
                            <span class="pill pill--warning">Experimental / diagnóstico</span>
                        </div>
                        <div id="replay-result-content"></div>
                    </section>
                </div>
            </details>
        </section>

        <section class="page" data-page="arts" hidden>
            <div class="page-heading">
                <p class="eyebrow">Dados recebidos</p>
                <h1>ARTs Capturadas</h1>
                <p>Visualize a última ART enviada manualmente pela extensão do navegador.</p>
            </div>

            <div class="panel empty-state" id="captured-art-empty">
                <div aria-hidden="true">A</div>
                <h2>Nenhuma ART recebida</h2>
                <p>Consulte uma ART no CreaOne e use o botão “Enviar para Atlântica” da extensão.</p>
            </div>

            <details class="advanced-tools advanced-tools--query">
                <summary>
                    <span><strong>Consulta controlada</strong><small>Fluxo legado mantido para diagnóstico</small></span>
                    <span class="pill pill--warning">Experimental / diagnóstico</span>
                </summary>
                <div class="advanced-tools__body">
                    <div class="query-layout">
                        <div class="panel query-panel">
                            <form id="query-form">
                                <label class="field-label" for="art-number">Número da ART</label>
                                <div class="query-control">
                                    <input id="art-number" name="art" type="text" inputmode="numeric" pattern="[0-9]{5,30}" maxlength="30" placeholder="Digite somente números" required>
                                    <button class="button button--primary" type="submit" data-busy-label="Consultando…">Consultar ART</button>
                                </div>
                            </form>
                        </div>
                        <aside class="notice">
                            <span class="notice__icon" aria-hidden="true">i</span>
                            <p>Esta ferramenta não resolve nem contorna o Cloudflare Turnstile. Ela permanece disponível somente para diagnóstico.</p>
                        </aside>
                    </div>
                </div>
            </details>

            <section id="art-result" class="result-section" hidden aria-live="polite">
                <div class="result-heading">
                    <div><p class="eyebrow">Resposta estruturada</p><h2>Resultado da ART</h2></div>
                    <span class="pill pill--success" id="art-source-label">Dados recebidos pelo navegador</span>
                </div>
                <div id="capture-warnings" class="capture-warnings" hidden></div>
                <div id="art-cards" class="detail-card-grid"></div>

                <article class="panel result-panel">
                    <div class="panel__header"><div><p class="eyebrow">Dados da obra/serviço</p><h2>Obras</h2></div></div>
                    <div class="table-wrap" id="works-container"></div>
                </article>

                <article class="panel result-panel">
                    <div class="panel__header"><div><p class="eyebrow">Escopo registrado</p><h2>Atividades técnicas</h2></div></div>
                    <div class="table-wrap" id="activities-container"></div>
                </article>

                <article class="panel result-panel">
                    <div class="panel__header"><div><p class="eyebrow">Texto original</p><h2>Observações da ART</h2></div></div>
                    <p class="preserved-text" id="art-observations">Não informado</p>
                </article>

                <article class="panel result-panel compact-result">
                    <div><span>Entidade de classe</span><strong id="art-entity">Não informado</strong></div>
                </article>
            </section>
        </section>

        <section class="page" data-page="diagnostico" hidden>
            <div class="page-heading">
                <p class="eyebrow">Transparência técnica</p>
                <h1>Diagnóstico da última chamada</h1>
                <p>Metadados suficientes para avaliar a integração, sem revelar cookies, token ou VIEWSTATE.</p>
            </div>
            <div class="panel diagnostic-panel">
                <div id="diagnostic-empty" class="empty-state">
                    <div aria-hidden="true">D</div>
                    <h2>Nenhuma requisição executada</h2>
                    <p>Importe uma captura e faça o Replay Exato para preencher este painel.</p>
                </div>
                <dl class="diagnostic-grid" id="diagnostic-grid" hidden></dl>
            </div>
        </section>
    </main>

    <dialog class="modal" id="work-modal" aria-labelledby="modal-title">
        <div class="modal__header">
            <div><p class="eyebrow">Dados recebidos do CREA</p><h2 id="modal-title">Detalhes da obra/serviço</h2></div>
            <button class="icon-button" type="button" data-close-modal aria-label="Fechar">×</button>
        </div>
        <div class="modal__body">
            <div class="detail-list" id="modal-details"></div>
        </div>
        <div class="modal__footer"><button class="button button--secondary" type="button" data-close-modal>Fechar</button></div>
    </dialog>

    <div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="true"></div>
    <script src="../assets/js/app.js" defer></script>
</body>
</html>
