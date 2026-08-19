# Atlântica — Captura CreaOne

Extensão Chrome Manifest V3 que lê somente os dados públicos de uma ART já renderizada na página de consulta pública do CreaOne. Não há framework, build ou dependências.

## Instalação no Chrome

1. Inicie o Apache no XAMPP.
2. Abra `http://localhost/sistema_atlantica/public/#captura` e mantenha visível o código de conexão.
3. No Chrome, abra `chrome://extensions/`.
4. Ative **Modo do desenvolvedor** no canto superior direito.
5. Clique em **Carregar sem compactação**.
6. Selecione a pasta `C:\xampp\htdocs\sistema_atlantica\browser-extension`.
7. Fixe a extensão **Atlântica — Captura CreaOne** na barra do Chrome, se desejar.
8. Abra o popup da extensão e expanda **Configuração da conexão**.
9. Informe:
   - URL: `http://localhost/sistema_atlantica`
   - Código de conexão: o código mostrado pelo Atlântica
10. Clique em **Salvar configuração**. O popup confirma quando a ponte local estiver acessível.

Ao atualizar esta extensão, volte a `chrome://extensions/`, clique em **Recarregar** no cartão da extensão e recarregue a página do Atlântica.

## Uso

1. Abra `http://localhost/sistema_atlantica/public/#captura`.
2. Informe a ART e clique em **Abrir consulta no CreaOne**.
3. A extensão abre ou ativa a consulta pública e preenche o campo da ART.
4. Conclua a verificação do site e clique em **Buscar** por conta própria.
5. Quando o resultado aparecer, o painel **ATLÂNTICA — ART ENCONTRADA** será exibido.
6. Para detalhes de uma obra, clique manualmente no olho da linha. Com o modal aberto, clique em **Capturar detalhes desta obra**.
7. Clique em **Enviar para Atlântica**. Nada é enviado antes desse clique.
8. Use **Abrir no Atlântica** para voltar diretamente a **ARTs Capturadas**.

Se a mesma captura já tiver sido enviada na sessão atual do navegador, a extensão avisa e muda o botão para **Enviar novamente**. O reenvio exige outro clique manual.

## Escopo e privacidade

As permissões estão restritas à página pública `PesquisaART/*` e à ponte `http://localhost/sistema_atlantica/*`. A extensão não pede `<all_urls>`, acesso a cookies ou histórico.

O preparador altera apenas `MainContent_Main_NumeroART_NumeroARTTxt` e dispara os eventos normais `input` e `change`. O `MutationObserver` é usado somente para atualizar o painel quando o UpdatePanel altera o DOM ou quando um modal é aberto. A extensão não clica em **Buscar**, não envia o formulário, não percorre paginação, não repete POSTs do CREA e não lê nem transmite Turnstile, cookies, VIEWSTATE ou credenciais.

Enquanto a página local está visível, `local-bridge.js` consulta comandos no `localhost` a cada segundo e envia heartbeat a cada 10 segundos. Quando um comando `open_art` surge, o bridge da página o entrega ao background, que abre o CreaOne e confirma o recebimento. O estado local percorre `pending -> delivered -> acknowledged`, e a tela Captura CreaOne mostra essa progressão.

Quando houver paginação, somente as linhas atualmente renderizadas são capturadas e um aviso acompanha o JSON.

## Arquivos

- `manifest.json`: permissões e scripts Manifest V3;
- `preparer.js`: preenchimento isolado do campo da ART;
- `extractor.js`: funções de leitura do DOM por IDs conhecidos;
- `content.js`: painel, detecção visual e ações manuais;
- `local-bridge.js`: polling e heartbeat enquanto o Atlântica está aberto;
- `background.js`: comunicação autenticada exclusivamente com o PHP local;
- `popup.html`, `popup.js`, `popup.css`: status, resumo e configuração.
