<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/bootstrap.php';
require_once __DIR__ . '/BrowserFixtureReader.php';

$passed = 0;
$failed = 0;

function check(bool $condition, string $message): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[OK] {$message}" . PHP_EOL;
        return;
    }
    $failed++;
    echo "[FALHA] {$message}" . PHP_EOL;
}

function segment(string $type, string $id, string $content): string
{
    return mb_strlen($content, 'UTF-8') . '|' . $type . '|' . $id . '|' . $content . '|';
}

$curl = <<<'CURL'
curl 'https://creanet1.creasp.org.br/_UI/Pages/ConsultaPublica/PesquisaART/PesquisaART.aspx' \
  -H 'accept: */*' \
  -H 'x-microsoftajax: Delta=true' \
  -H 'x-requested-with: XMLHttpRequest' \
  -H 'cookie: ASP.NET_SessionId=session-value; HWWAFSESID=waf-value' \
  --data-raw 'ctl00%24ctl00%24ScriptManager1=ctl00%24ctl00%24MainContent%24UpdatePanelPrincipal%7Cctl00%24ctl00%24MainContent%24Main%24BtnBuscar&__VIEWSTATE=abc%2Bdef&__VIEWSTATEGENERATOR=CA0B0334&ctl00%24ctl00%24MainContent%24Main%24NumeroART%24NumeroARTTxt=2620251600118&cf-turnstile-response=legitimate-user-token&__ASYNCPOST=true'
CURL;

$importer = new CurlImporter();
$request = $importer->parse($curl);
check($request['method'] === 'POST', 'CurlImporter identifica POST.');
check($request['form']['__VIEWSTATE'] === 'abc+def', 'CurlImporter decodifica campos URL encoded.');
check(isset($request['cookies']['ASP.NET_SessionId']), 'CurlImporter separa cookies sem executá-los.');
check($request['form']['ctl00$ctl00$MainContent$Main$NumeroART$NumeroARTTxt'] === '2620251600118', 'CurlImporter identifica o número capturado.');

try {
    $importer->parse(str_replace('creanet1.creasp.org.br', 'example.org', $curl));
    check(false, 'CurlImporter bloqueia host externo.');
} catch (InvalidArgumentException) {
    check(true, 'CurlImporter bloqueia host externo.');
}

$html = <<<'HTML'
<div id="MainContent_Main_UCARTRes1025">
  <span id="MainContent_Main_SpanNumeroART">2620251600118</span>
  <span id="MainContent_Main_SpanSituacaoART">Baixada</span>
  <span id="MainContent_Main_SpanResponsavelTecnico">Profissional de Teste</span>
  <table id="MainContent_Main_UCARTRes1025ObraServico_BoxTitleDadosContrato_GrdDadosObraServico">
    <tr><th>CEP</th><th>Endereço</th><th>Data Início</th><th>Previsão Término</th><th>Ação</th></tr>
    <tr><td>01000-000</td><td>Rua de Teste, 10</td><td>01/01/2025</td><td>01/02/2025</td><td><a href="javascript:__doPostBack('ctl00$ctl00$MainContent$Main$UCARTRes1025ObraServico$BoxTitleDadosContrato$GrdDadosObraServico$ctl02$BtnVisualizar','')">Olho</a></td></tr>
  </table>
  <table id="MainContent_Main_UCARTRes1025ObraServico_BoxTitleAtividadesTecnicas_GrdAtividadesTecnicas">
    <tr><th>Nível de Atuação</th><th>Atividade</th><th>Obra/Serviço</th><th>Complemento</th><th>Quantidade</th><th>Unidade</th></tr>
    <tr><td>Execução</td><td>Direção</td><td>Edificação</td><td>Residencial</td><td>12,50</td><td>m²</td></tr>
  </table>
</div>
HTML;

$delta = segment('#', '', '4')
    . segment('updatePanel', AspNetDeltaParser::PRIMARY_PANEL, $html)
    . segment('hiddenField', '__VIEWSTATE', 'novo+estado')
    . segment('hiddenField', '__VIEWSTATEGENERATOR', 'CA0B0334');
$parsedDelta = (new AspNetDeltaParser())->parse($delta);
check($parsedDelta['primary_html'] === $html, 'AspNetDeltaParser extrai UpdatePanel com conteúdo Unicode.');
check($parsedDelta['hidden_fields']['__VIEWSTATE'] === 'novo+estado', 'AspNetDeltaParser extrai hiddenFields atualizados.');

$artParser = new ArtParser();
$art = $artParser->parse($parsedDelta['primary_html']);
check($artParser->hasArtResult($html), 'ArtParser reconhece um resultado de ART.');
check($art['art']['situacao'] === 'Baixada', 'ArtParser extrai campo por ID.');
check($art['atividades'][0]['quantidade'] === '12,50', 'ArtParser preserva quantidade original em coluna separada.');
check(str_ends_with((string) $art['obras'][0]['event_target'], '$BtnVisualizar'), 'ArtParser extrai EVENTTARGET do botão de detalhes.');

$detailHtml = <<<'HTML'
<div id="modal-detalhes-obra-servico">
  <span id="MainContent_Main_UCARTRes1025ObraServico_SpanDetalhesCEP">01000-000</span>
  <span id="MainContent_Main_UCARTRes1025ObraServico_SpanDetalhesTipoLogradouro">Rua</span>
  <span id="MainContent_Main_UCARTRes1025ObraServico_SpanDetalhesLogradouro">Exemplo</span>
  <span id="MainContent_Main_UCARTRes1025ObraServico_SpanDetalhesProprietario">Proprietário de Teste</span>
</div>
HTML;
$details = $artParser->parseWorkDetails($detailHtml);
check($details['modal_found'] === true, 'ArtParser identifica o modal de detalhes.');
check($details['details']['tipo_logradouro'] === 'Rua', 'ArtParser extrai detalhes da obra pelos IDs observados.');

$session = new CreaOneSession();
$session->clear();
$session->import($request);
$controlled = $session->buildControlledRequest('28027230230943447');
check($controlled['form']['ctl00$ctl00$MainContent$Main$NumeroART$NumeroARTTxt'] === '28027230230943447', 'Consulta controlada altera somente para um número válido.');
check($request['form']['ctl00$ctl00$MainContent$Main$NumeroART$NumeroARTTxt'] === '2620251600118', 'Requisição importada original permanece imutável.');
$session->clear();

$debugDirectory = sys_get_temp_dir() . '/creaone-replay-debug-' . bin2hex(random_bytes(5));
$diagnosticStore = new ReplayDiagnosticStore($debugDirectory);
$unexpectedHtml = '<div id="MainContent_UpdatePanelPrincipal"><p>Conteúdo público não reconhecido.</p></div>';
$unexpectedDiagnostic = $diagnosticStore->analyze(
    $unexpectedHtml,
    '28027230230943447',
    200,
    167509,
    true,
    true,
    false,
);
check($unexpectedDiagnostic['classification'] === 'RESPOSTA_INESPERADA', 'Parser ausente gera classificação RESPOSTA_INESPERADA.');

$source = file_get_contents(__DIR__ . '/../src/ReplayService.php');
check(
    is_string($source)
    && str_contains($source, 'Resposta recebida, mas o resultado da ART')
    && !str_contains($source, "logContext(\$diagnostic, 'art_not_found')"),
    'hasArtResult=false não gera mais a mensagem local ART não encontrada.'
);

$saved = $diagnosticStore->persist($unexpectedHtml, $unexpectedDiagnostic);
$savedHtml = file_get_contents($debugDirectory . '/last-replay-updatepanel.html');
$savedJson = file_get_contents($debugDirectory . '/last-replay-diagnostic.json');
check($saved['html_saved'] && $savedHtml === $unexpectedHtml, 'HTML exato do UpdatePanel é salvo no debug.');
check(is_string($savedJson) && str_contains($savedJson, 'RESPOSTA_INESPERADA'), 'JSON de diagnóstico é salvo com a classificação.');
check(
    !str_contains((string) $savedHtml, '__VIEWSTATE')
    && !str_contains((string) $savedJson, '__VIEWSTATE')
    && !str_contains((string) $savedHtml, 'session-value')
    && !str_contains((string) $savedJson, 'session-value')
    && !str_contains((string) $savedHtml, 'legitimate-user-token')
    && !str_contains((string) $savedJson, 'legitimate-user-token'),
    'Debug não persiste VIEWSTATE, cookies ou token Turnstile.'
);

$sensitiveHtml = '<input name="__VIEWSTATE" value="secret-viewstate"><input name="cf-turnstile-response" value="secret-turnstile"><span>ASP.NET_SessionId=secret-cookie</span>';
$diagnosticStore->persist($sensitiveHtml, $unexpectedDiagnostic);
$guardedHtml = file_get_contents($debugDirectory . '/last-replay-updatepanel.html');
$guardedJson = file_get_contents($debugDirectory . '/last-replay-diagnostic.json');
check(
    $guardedHtml === ''
    && !str_contains((string) $guardedJson, '__VIEWSTATE')
    && !str_contains((string) $guardedJson, 'cf-turnstile-response')
    && !str_contains((string) $guardedJson, 'ASP.NET_SessionId')
    && !str_contains((string) $guardedJson, 'secret-viewstate')
    && !str_contains((string) $guardedJson, 'secret-turnstile')
    && !str_contains((string) $guardedJson, 'secret-cookie'),
    'Persistência bloqueia HTML que contenha marcadores sensíveis.'
);

$presentHtml = '<div><span id="MainContent_Main_SpanNumeroART">28027230230943447</span></div>';
$presentDiagnostic = $diagnosticStore->analyze($presentHtml, '28027230230943447', 200, 800, true, true, false);
check($presentDiagnostic['classification'] === 'ART_PRESENTE', 'Classificação ART_PRESENTE funciona com SpanNumeroART.');
check($presentDiagnostic['art_number_found_in_html'] === true, 'Número solicitado é localizado literalmente no UpdatePanel.');

@unlink($debugDirectory . '/last-replay-updatepanel.html');
@unlink($debugDirectory . '/last-replay-diagnostic.json');
@rmdir($debugDirectory);

$fixtureRoot = __DIR__ . '/fixtures/creaone';
$normalFixture = new BrowserFixtureReader($fixtureRoot . '/art-normal.html');
$artId = 'MainContent_Main_UCARTRes1025ObraServico_SpanNumeroART';
$worksId = 'MainContent_Main_UCARTRes1025ObraServico_BoxTitleDadosContrato_GrdDadosObraServico';
$activitiesId = 'MainContent_Main_UCARTRes1025ObraServico_BoxTitleAtividadesTecnicas_GrdAtividadesTecnicas';
check($normalFixture->text($artId) === '28027230230943447', 'Fixture do navegador identifica a ART pelo indicador de resultado exato.');
check(count($normalFixture->rows($worksId)) === 1, 'Fixture do navegador ignora cabeçalho e pager da tabela de obras.');
check(count($normalFixture->rows($activitiesId)) === 2, 'Fixture do navegador contém duas atividades técnicas renderizadas.');
check($normalFixture->rows($activitiesId)[0][4] === '12,50', 'Fixture do navegador preserva quantidade como texto original.');
check($normalFixture->list('MainContent_Main_UCARTRes1025ObraServico_ListaTitulosProfissional') === ['Engenheiro Civil', 'Engenheiro de Segurança do Trabalho'], 'Fixture do navegador extrai todos os títulos profissionais.');
check($normalFixture->hasPagination($worksId), 'Fixture do navegador detecta aviso de paginação 1-1 de 2.');

$withoutCompany = new BrowserFixtureReader($fixtureRoot . '/art-sem-empresa.html');
check($withoutCompany->text($artId) === '2620251600118' && $withoutCompany->text('MainContent_Main_UCARTRes1025ObraServico_SpanRazaoSocialEmpresaContratada') === '', 'Fixture aceita ART sem empresa contratada.');
$emptyFields = new BrowserFixtureReader($fixtureRoot . '/art-campos-vazios.html');
check($emptyFields->text('MainContent_Main_UCARTRes1025ObraServico_SpanModeloART') === '', 'Fixture normaliza campo disponível, porém vazio.');
$withoutResult = new BrowserFixtureReader($fixtureRoot . '/sem-resultado.html');
check($withoutResult->text($artId) === '' && $withoutResult->attribute('MainContent_Main_NumeroART_NumeroARTTxt', 'value') === '28027230230943447', 'Campo de pesquisa preenchido não é confundido com resultado de ART.');
$modalFixture = new BrowserFixtureReader($fixtureRoot . '/modal-detalhes.html');
check($modalFixture->text('MainContent_Main_UCARTRes1025ObraServico_SpanDetalhesProprietario') === 'Proprietário de Teste', 'Fixture do modal expõe os detalhes somente após abertura manual.');

$browserPayload = [
    'source' => 'creaone_public_browser',
    'captured_at' => '2026-08-18T12:00:00-03:00',
    'art' => ['numero' => '28027230230943447', 'modelo' => '<b>Obra ou Serviço</b>', 'tipo' => 'Substituição', 'situacao' => 'BAIXADA'],
    'empresa' => ['razao_social' => 'Empresa Técnica de Teste Ltda.', 'registro' => '1234567-SP', 'cnpj' => '12.345.678/0001-90'],
    'responsavel_tecnico' => ['nome' => 'Profissional de Teste', 'registro' => 'CREA-SP 123456', 'rnp' => '2600000000', 'titulos' => ['Engenheiro Civil']],
    'contratante' => ['nome' => 'Contratante de Teste', 'tipo' => 'Pessoa Jurídica'],
    'obras' => [['cep' => '01000-000', 'endereco' => 'Rua de Teste, 10', 'data_inicio' => '01/02/2023', 'previsao_termino' => '30/11/2023', 'detalhes' => ['cidade' => 'São Paulo', 'uf' => 'SP']]],
    'atividades' => [['nivel_atuacao' => 'Execução', 'atividade' => 'Direção', 'obra_servico' => 'Edificação', 'complemento' => 'Residencial', 'quantidade' => '12,50', 'unidade' => 'm²']],
    'observacoes' => '<script>não executar</script>Conteúdo público.',
    'entidade_classe' => 'Entidade de Classe de Teste',
    'avisos' => ['Há mais registros no CreaOne que não estão visíveis nesta página.'],
];
$validator = new BrowserImportValidator();
$validated = $validator->validate($browserPayload);
check($validated['art']['modelo'] === 'Obra ou Serviço', 'Endpoint remove tags HTML dos campos recebidos.');
check(!str_contains($validated['observacoes'], '<script>'), 'Endpoint não mantém tags executáveis no conteúdo recebido.');
check($validated['atividades'][0]['quantidade'] === '12,50', 'Endpoint mantém quantidade original como string.');

$forbiddenPayload = $browserPayload;
$forbiddenPayload['metadados'] = ['__VIEWSTATE' => 'segredo'];
try {
    $validator->validate($forbiddenPayload);
    check(false, 'Endpoint rejeita dado de sessão em qualquer nível do JSON.');
} catch (InvalidArgumentException $exception) {
    check($exception->getMessage() === 'Dado de sessão não permitido.', 'Endpoint rejeita dado de sessão em qualquer nível do JSON.');
}

$allForbiddenRejected = true;
foreach (['cookie', 'cookies', 'viewstate', '__VIEWSTATE', 'turnstile', 'cf-turnstile-response', 'authorization', '.ASPXAUTH', 'ASP.NET_SessionId'] as $forbiddenKey) {
    $candidate = $browserPayload;
    $candidate['metadados'] = [$forbiddenKey => 'segredo'];
    try {
        $validator->validate($candidate);
        $allForbiddenRejected = false;
    } catch (InvalidArgumentException $exception) {
        $allForbiddenRejected = $allForbiddenRejected && $exception->getMessage() === 'Dado de sessão não permitido.';
    }
}
check($allForbiddenRejected, 'Endpoint rejeita toda a lista de campos de sessão e autenticação.');

$invalidArtPayload = $browserPayload;
$invalidArtPayload['art']['numero'] = 'ART-123';
try {
    $validator->validate($invalidArtPayload);
    check(false, 'Endpoint rejeita número de ART não numérico.');
} catch (InvalidArgumentException) {
    check(true, 'Endpoint rejeita número de ART não numérico.');
}

$invalidTypePayload = $browserPayload;
$invalidTypePayload['empresa'] = 'estrutura inválida';
try {
    $validator->validate($invalidTypePayload);
    check(false, 'Endpoint rejeita tipos incompatíveis no JSON.');
} catch (InvalidArgumentException) {
    check(true, 'Endpoint rejeita tipos incompatíveis no JSON.');
}

$oversizedPayload = $browserPayload;
$oversizedPayload['atividades'] = array_fill(0, 201, $browserPayload['atividades'][0]);
try {
    $validator->validate($oversizedPayload);
    check(false, 'Endpoint limita a quantidade de itens dos arrays.');
} catch (InvalidArgumentException) {
    check(true, 'Endpoint limita a quantidade de itens dos arrays.');
}

$bridgeDirectory = sys_get_temp_dir() . '/creaone-browser-bridge-' . bin2hex(random_bytes(5));
$bridgeStore = new BrowserBridgeStore($bridgeDirectory);
$savedImport = $bridgeStore->saveImport($validated);
$loadedImport = $bridgeStore->lastImport();
check(is_array($loadedImport) && $loadedImport['import_id'] === $savedImport['import_id'], 'Armazenamento local recupera a última importação do navegador.');
$heartbeatNow = new DateTimeImmutable('2026-08-18T18:00:00-03:00');
$bridgeStore->touchBridge($heartbeatNow->modify('-10 seconds'));
check($bridgeStore->bridgeStatus($heartbeatNow)['connected'] === true, 'Heartbeat recebido há 10 segundos mantém a extensão conectada.');
$bridgeStore->touchBridge($heartbeatNow->modify('-31 seconds'));
check($bridgeStore->bridgeStatus($heartbeatNow)['connected'] === false, 'Heartbeat com mais de 30 segundos marca a extensão como não conectada.');
check(BrowserBridgeRequest::isLocalOrigin('http://localhost') === true, 'Origin http://localhost é tratado como local.');
check(BrowserBridgeRequest::isLocalOrigin('http://localhost:80') === true, 'Origin http://localhost:80 também é tratado como local.');
check(BrowserBridgeRequest::isLocalOrigin('http://localhost/sistema_atlantica') === false, 'Path não faz parte da comparação de Origin.');
check(BrowserBridgeRequest::isExtensionOrigin('chrome-extension://abcdefghijklmnopabcdefghijklmnop') === true, 'Origin chrome-extension exato é reconhecido.');
check(BrowserBridgeRequest::isExtensionOrigin('chrome-extension://zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz') === false, 'Origin chrome-extension diferente é rejeitado.');
$bridgeStore->rememberExtensionOrigin('chrome-extension://abcdefghijklmnopabcdefghijklmnop');
$originPinned = $bridgeStore->bridgeStatus($heartbeatNow);
check(($originPinned['extension_origin'] ?? null) === 'chrome-extension://abcdefghijklmnopabcdefghijklmnop', 'Origem da extensão fica pinada no estado local.');
$bridgeStore->touchBridge($heartbeatNow->modify('-5 seconds'), 'http://localhost');
check(($bridgeStore->bridgeStatus($heartbeatNow)['extension_origin'] ?? null) === 'chrome-extension://abcdefghijklmnopabcdefghijklmnop', 'Touch local não substitui a origem pinada da extensão.');

$commandValidator = new BrowserCommandValidator();
check($commandValidator->openArt(['art' => '28027230230943447']) === '28027230230943447', 'Comando open_art aceita uma ART numérica plausível.');
try {
    $commandValidator->openArt(['art' => 'ART-28027230230943447']);
    check(false, 'Comando open_art rejeita ART não numérica.');
} catch (InvalidArgumentException) {
    check(true, 'Comando open_art rejeita ART não numérica.');
}
$createdCommand = $bridgeStore->saveCommand('28027230230943447');
check($createdCommand['action'] === 'open_art' && $createdCommand['art'] === '28027230230943447' && ($createdCommand['status'] ?? null) === 'pending', 'Comando open_art começa pendente no armazenamento local.');
$storedCommand = $bridgeStore->commandStatus($createdCommand['command_id']);
check(is_array($storedCommand) && ($storedCommand['status'] ?? null) === 'pending', 'Status do comando pode ser lido antes da entrega.');
$consumedCommand = $bridgeStore->consumeCommand();
check(is_array($consumedCommand) && $consumedCommand['command_id'] === $createdCommand['command_id'] && ($consumedCommand['status'] ?? null) === 'delivered', 'Extensão pode receber o comando open_art pendente.');
check($bridgeStore->consumeCommand() === null, 'Comando entregue não é reenviado imediatamente.');
$storedDeliveredCommand = json_decode((string) file_get_contents($bridgeDirectory . '/bridge-command.json'), true);
$storedDeliveredCommand['delivered_at'] = '2026-08-18T17:00:00-03:00';
file_put_contents($bridgeDirectory . '/bridge-command.json', json_encode($storedDeliveredCommand, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
check($bridgeStore->consumeCommand() === null, 'Comando entregue não é reenviado depois de expirar.');
$deliveredStatus = $bridgeStore->commandStatus($createdCommand['command_id']);
check(is_array($deliveredStatus) && ($deliveredStatus['status'] ?? null) === 'delivered' && ($deliveredStatus['delivered_at'] ?? null) !== null, 'Status do comando muda para delivered após a captura da extensão.');
$ackedCommand = $bridgeStore->acknowledgeCommand($createdCommand['command_id']);
check(is_array($ackedCommand) && $ackedCommand['command_id'] === $createdCommand['command_id'] && ($ackedCommand['status'] ?? null) === 'acknowledged' && ($ackedCommand['consumed_at'] ?? null) !== null, 'Extensão confirma o comando somente depois de abrir o CreaOne.');
check(($bridgeStore->commandStatus($createdCommand['command_id'])['status'] ?? null) === 'acknowledged', 'Status final do comando fica acknowledged.');
@unlink($bridgeDirectory . '/last-import.json');
@unlink($bridgeDirectory . '/bridge-status.json');
@unlink($bridgeDirectory . '/bridge-command.json');
@rmdir($bridgeDirectory);

$safeLogDirectory = sys_get_temp_dir() . '/creaone-safe-log-' . bin2hex(random_bytes(5));
(new SafeLogger($safeLogDirectory))->write('browser_import', [
    'art_number' => '28027230230943447',
    'works_count' => 1,
    'activities_count' => 2,
    'cookie' => 'cookie-secreto',
    'viewstate' => 'viewstate-secreto',
]);
$safeLogPath = $safeLogDirectory . '/creaone-' . date('Y-m-d') . '.log';
$safeLog = file_get_contents($safeLogPath);
check(
    is_string($safeLog)
    && str_contains($safeLog, '28027230230943447')
    && !str_contains($safeLog, 'cookie-secreto')
    && !str_contains($safeLog, 'viewstate-secreto'),
    'Log da importação registra somente metadados permitidos.'
);
@unlink($safeLogPath);
@rmdir($safeLogDirectory);

$manifest = json_decode((string) file_get_contents(__DIR__ . '/../browser-extension/manifest.json'), true);
$extensionSource = implode("\n", [
    (string) file_get_contents(__DIR__ . '/../browser-extension/preparer.js'),
    (string) file_get_contents(__DIR__ . '/../browser-extension/extractor.js'),
    (string) file_get_contents(__DIR__ . '/../browser-extension/content.js'),
    (string) file_get_contents(__DIR__ . '/../browser-extension/background.js'),
    (string) file_get_contents(__DIR__ . '/../browser-extension/local-bridge.js'),
]);
check(($manifest['manifest_version'] ?? null) === 3, 'Extensão usa Manifest V3.');
check(!in_array('<all_urls>', $manifest['host_permissions'] ?? [], true) && !in_array('cookies', $manifest['permissions'] ?? [], true), 'Extensão não pede acesso global nem permissão de cookies.');
check(($manifest['host_permissions'][0] ?? '') === 'https://creanet1.creasp.org.br/_UI/Pages/ConsultaPublica/PesquisaART/*', 'Permissão do CreaOne está limitada à página pública de pesquisa ART.');
check(str_contains($extensionSource, 'MutationObserver') && !str_contains($extensionSource, 'BtnBuscar'), 'Extensão observa alterações visuais sem disparar o botão Buscar.');
check(!str_contains((string) file_get_contents(__DIR__ . '/../browser-extension/content.js'), 'fetch('), 'Script da página não executa requisições HTTP.');
check(str_contains($extensionSource, 'browser_bridge_command.php') && str_contains($extensionSource, "action !== 'open_art'"), 'Extensão consulta e reconhece somente o comando open_art.');
check(str_contains((string) file_get_contents(__DIR__ . '/../browser-extension/local-bridge.js'), 'browser_bridge_command.php') && str_contains((string) file_get_contents(__DIR__ . '/../browser-extension/local-bridge.js'), "type: 'OPEN_CREAONE_ART'"), 'Bridge local da página consulta o comando diretamente e dispara a abertura da ART.');
check(str_contains($extensionSource, "chrome.tabs.create({ url: CREA_URL") && str_contains($extensionSource, "type: 'PREPARE_ART'"), 'Extensão abre ou ativa o CreaOne antes de preparar a ART.');
check(str_contains($extensionSource, 'MainContent_Main_NumeroART_NumeroARTTxt') && str_contains($extensionSource, "new view.Event('input'") && str_contains($extensionSource, "new view.Event('change'"), 'Preparador preenche o campo da ART e dispara somente eventos normais de edição.');
check(preg_match('/\.(?:submit|requestSubmit)\s*\(/i', $extensionSource) !== 1, 'Extensão não dispara envio automático de formulário.');
check(stripos($extensionSource, 'turnstile') === false, 'Extensão não contém leitura ou manipulação de Turnstile.');
check(str_contains($extensionSource, "type: 'SEND_CAPTURE'") && str_contains($extensionSource, 'creaone_browser_import.php'), 'Envio manual da ART para o Atlântica permanece disponível.');
check(in_array('alarms', $manifest['permissions'] ?? [], true) && str_contains($extensionSource, 'setInterval(heartbeat, 10000)'), 'Heartbeat usa ciclo de 10 segundos e alarme de recuperação do Manifest V3.');

echo PHP_EOL . "Resultado: {$passed} aprovados, {$failed} falhos." . PHP_EOL;
exit($failed === 0 ? 0 : 1);
