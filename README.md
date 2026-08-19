# Integração Assistida CreaOne

Protótipo técnico local em PHP e uma extensão Chrome Manifest V3 para importar no Atlântica os dados públicos de uma ART que o CreaOne já renderizou no navegador.

O usuário informa a ART no Atlântica. A extensão abre ou ativa a consulta pública e preenche somente esse campo. A verificação do site e o clique em **Buscar** continuam manuais. Depois, mediante outro clique manual em **Enviar para Atlântica**, a extensão envia o JSON estruturado ao PHP local.

A extensão não consulta o CREA, não automatiza cliques, não resolve CAPTCHA/Turnstile e não lê cookies, VIEWSTATE, `.ASPXAUTH`, `ASP.NET_SessionId` ou tokens. O endpoint rejeita qualquer payload que contenha esses campos.

## Arquitetura

```text
sistema_atlantica/
├── browser-extension/       extensão local Chrome, sem frameworks
├── public/                  interface Atlântica
├── assets/                  CSS e JavaScript da interface
├── api/                     ponte local e ferramentas de diagnóstico
├── config/
│   ├── browser_bridge.php   código local (ignorado pelo Git)
│   └── browser_bridge.example.php
├── src/
│   ├── BrowserImportValidator.php
│   ├── BrowserBridgeRequest.php
│   ├── BrowserBridgeStore.php
│   ├── BrowserCommandValidator.php
│   ├── CreaOneClient.php
│   ├── CurlImporter.php
│   ├── AspNetDeltaParser.php
│   ├── ArtParser.php
│   └── ReplayService.php
├── storage/imports/         última captura local
└── tests/                   suíte e fixtures HTML, sem chamadas ao CREA
```

O Replay Exato, o importador de cURL, o cliente e os parsers anteriores foram preservados em **Ferramentas de diagnóstico avançado**, identificados como **Experimental / diagnóstico**. Eles não são mais o fluxo principal.

## Requisitos e execução

- Windows com XAMPP e Apache ativo;
- PHP 8.1 ou mais recente;
- extensões PHP `curl`, `dom`, `libxml`, `mbstring` e `openssl` habilitadas;
- Google Chrome com Modo do desenvolvedor disponível.

Mantenha o projeto em `C:\xampp\htdocs\sistema_atlantica` e abra:

```text
http://localhost/sistema_atlantica/public/
```

Na tela **Captura CreaOne**, copie o código de conexão local para configurar a extensão. Esse código autentica somente a ponte Extensão ↔ Atlântica.

## Instalar a extensão

1. Abra `chrome://extensions/` no Chrome.
2. Ative **Modo do desenvolvedor**.
3. Clique em **Carregar sem compactação**.
4. Selecione `C:\xampp\htdocs\sistema_atlantica\browser-extension`.
5. Abra o popup **Atlântica — Captura CreaOne**.
6. Em **Configuração da conexão**, use a URL `http://localhost/sistema_atlantica`.
7. Informe o código exibido na tela **Captura CreaOne** do Atlântica e salve.

Ao atualizar uma instalação já carregada, clique em **Recarregar** no cartão da extensão e recarregue a página do Atlântica.

O guia específico também está em `browser-extension/README.md`.

## Fluxo de uso

1. Abra `http://localhost/sistema_atlantica/public/#captura`.
2. Informe o número da ART e clique em **Abrir consulta no CreaOne**.
3. Aguarde a extensão abrir ou ativar a consulta e preencher o número.
4. Complete a verificação do site e clique manualmente em **Buscar**.
5. Aguarde o resultado público aparecer.
6. Opcionalmente, abra manualmente o olho de uma obra e clique em **Capturar detalhes desta obra**.
7. Clique em **Enviar para Atlântica** no painel ou popup da extensão.
8. Clique em **Abrir no Atlântica** ou volte ao sistema e abra **ARTs Capturadas**.

Se uma tabela indicar paginação, a versão atual captura somente as linhas visíveis e mostra um aviso. A extensão não avança páginas automaticamente.

## Ponte local e segurança

`api/creaone_open_art.php` registra o comando local `open_art`; `api/browser_bridge_command.php` o entrega uma única vez à extensão. O primeiro aceita somente a interface local e o segundo somente uma origem de extensão válida. Ambos exigem o código no cabeçalho `X-Atlantica-Bridge-Token`.

O endpoint `api/creaone_browser_import.php` continua responsável pelo retorno dos dados públicos. Não há `Access-Control-Allow-Origin: *`.

A extensão envia heartbeat a cada 10 segundos enquanto a tela local ou o CreaOne está aberto. Um alarme do Manifest V3 mantém um ciclo de recuperação em segundo plano. O sistema exibe **Conectada** somente quando o último heartbeat tem no máximo 30 segundos.

O servidor valida origem lógica, tipos, UTF-8, número numérico da ART, limites de campos e de arrays, tamanho total e remove tags HTML. Campos de sessão ou autenticação são rejeitados com HTTP 400 e a mensagem **Dado de sessão não permitido.**

Os logs de importação contêm somente data/hora, ART, quantidade de obras, quantidade de atividades e resultado. O armazenamento local guarda apenas o JSON público validado.

Para preparar outra instalação, copie `config/browser_bridge.example.php` para `config/browser_bridge.php` e substitua o exemplo por um código aleatório no formato `XXXX-XXXX-XXXX`. Não reutilize credenciais do CREA.

## Testes

Execute, na pasta do projeto:

```powershell
php tests/run.php
node tests/preparer.test.js
```

As fixtures cobrem ART normal, ausência de empresa, campos vazios, duas atividades, obra, paginação, modal de detalhes e HTML sem resultado. A suíte também testa o comando de abertura, consumo único, heartbeat recente/vencido, preenchimento do input, eventos de edição, ausência de busca automática, validação do JSON e permissões mínimas.

Todos os testes são locais e sintéticos. Eles não enviam nenhuma requisição ao CREA.
