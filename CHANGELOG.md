# Changelog

## [2.0.3] - 15-06-2026

### Novidades

- **PIX Parcelado (CajuPay)**: novo método de pagamento com checkout embed via SDK, adesão no gateway, regras por produto (parcelas, entrada fixa ou percentual), webhooks de parcelas e tela de PIX da entrada.

### Melhorias

- **Recuperação de carrinho / SMS (CajuPay)**: compatibilidade com fluxos de recuperação — telefone do comprador repassado à CajuPay (E.164) para SMS de Recovery/Acesso e links de retorno ao checkout com dados do cliente pré-preenchidos.
- **E-mail (SMTP genérico)**: configuração passa a aceitar **e-mail do remetente** separado do usuário SMTP (SendGrid e Hostinger mantêm o comportamento anterior).

### Correções

- **Plugins**: corrigido erro falso ao instalar plugin em ambientes onde `PHP_BINARY` aponta para **php-fpm** (validação de sintaxe do `bootstrap.php` passa a usar `token_get_all` em vez de `exec php -l`).

## [2.0.2] - 08-06-2026

### Correções

- **Instalador (wizard)**: corrigido erro **"There is no active transaction"** na migration `change_products_id_to_uuid` em **hospedagem compartilhada** (MySQL/MariaDB fazem commit implícito em `ALTER TABLE` dentro de `DB::transaction()`); instalações novas usam caminho rápido com banco vazio, sem mapear IDs inteiros antigos.
- **Member Builder**: corrigido **erro 500** ao criar, editar ou duplicar aulas quando colunas recentes de `member_lessons` ainda não existiam no banco (`support_files`, `useful_links`, `content_files`, liberação programada); gravação resiliente (`onlyExistingColumns`) e migration de reparo `ensure_member_lessons_optional_columns`.
- **Integrações (`/integracoes`)**: corrigido **erro 500** após atualização quando migrations de pixels centralizados, checkout externo ou pivot Utmify ainda não tinham sido aplicadas; a página abre mesmo com banco parcialmente desatualizado.

### Melhorias

- **Instalador / migrations**: mensagens de erro mais claras no wizard quando uma migration falha (incluindo orientação para limpar o banco e reinstalar após instalação interrompida).
- **Migrations `member_lessons`**: migrations de `release_after_days`, `support_files` e `useful_links` deixam de falhar quando `content_files` ainda não existe (comum em updates parciais).

## [2.0.1] - 08-06-2026

### Correções

- **Documentação da API de pagamentos**: restauradas as páginas Vue e componentes ausentes (`Docs/ApiPagamentos`, `Docs/ApiPagamentosTestar` e blocos em `components/docs/`); corrigida a **tela preta** ao abrir `/docs/api-pagamentos` (erro `Page not found: ./Pages/Docs/ApiPagamentos.vue`).
- **Painel do afiliado / co-produtor — Financeiro**: corrigido **403 (Acesso não autorizado)** ao abrir Financeiro/Faturamento quando o parceiro tinha vínculo ativo mas role ainda era `aluno` (menu apontava para `/financeiro` do produtor); menu e middleware passam a usar `/parceiro/financeiro`; aprovação de afiliado no painel do produtor atualiza o role para `afiliado`.
- **Utmify / moedas internacionais**: valores enviados à Utmify passam a ser convertidos para **BRL em qualquer moeda** (USD, EUR, MZN, JPY, etc.) quando o `settlement_amount_cents` do webhook CajuPay ainda não está disponível; pedidos internacionais passam a gravar `amount_brl` estimado no metadata na criação.

### Melhorias

- **Atualização (hospedagem compartilhada)**: guia `#ZIP-UPDATE/Corrigir Erro 500.md` com instruções para usar `APP_AUTO_MIGRATE=true` no `.env` quando migrations pendentes causarem erro 500 após update.

## [2.0.0] - 07-06-2026

### Novidades

- **Afiliados**: programa de afiliação integrado à plataforma.
- **Co-produtores**: gestão de parceiros, comissões e repasses.
- **Financeiro**: visão geral de saldos e opção de **realizar saque pela plataforma** (via CajuPay).
- **Meus produtos**: área para o aluno aceder a **todos os produtos comprados** num único lugar.
- **Extensibilidade de plugins**: arquitetura ampliada para integrações e extensões de terceiros.

### Melhorias

- **UI/UX geral**: melhorias de usabilidade e ajustes suaves em todo o painel.
- **Vendas**: botão de **WhatsApp** na listagem de vendas.
- **CajuPay / checkout**: idioma (`locale`) repassado ao **SDK** na renderização dos inputs de **cartão** e **wallets** (Apple Pay / Google Pay).
- **Webhooks**: payload enriquecido com **arrays mais detalhados** sobre produto, oferta e itens do pedido.
- **Assinaturas**: **dias de carência** e **dias de aviso** antes da cobrança/renovação configuráveis por plano.
- **Pixels de conversão**: configuração **global** (tenant) para facilitar operação **multi-produto**.
- **Tracking**: recursos mais avançados de monitoramento do funil de checkout.
- **Ofertas**: IDs públicos de ofertas passam a ser **aleatórios** (não sequenciais).
- **Checkout / moedas**: opção de **detecção global de moeda** ou **moeda única fixa** por produto.
- **Imagens**: melhorias no carregamento e entrega de assets no checkout e no painel.
- **Editor de checkout**: melhorias de usabilidade e preview.
- **Checkout Pro (API de pagamentos)**: melhorias no fluxo hospedado e na integração com gateways.
- **Webhooks (integrações)**: melhorias no registro, disparo e diagnóstico de eventos.
- **Leitor de PDF** (área de membros): experiência de leitura e navegação aprimorada.
- **Instalador**: wizard de instalação otimizado para **hospedagem compartilhada**.
- **Checkout (conversão)**: ajustes suaves de UI/UX com base em testes dos últimos meses.

### Correções

- **Vendas**: corrigida a **paginação** da página `/vendas`.
- **Notificações push**: deixavam de funcionar após **atualizar a plataforma**; corrigido o registro do service worker pós-update.
- **Checkout / order bump**: order bump **não era adicionado** quando o produto principal era **assinatura**.
- **Checkout (CSP)**: corrigidos erros de **Content Security Policy** em algumas instalações em **hospedagem compartilhada**.
- **Atualização**: melhorias no **update pelo painel** (estabilidade e feedback de progresso).
- **Google / GMT**: corrigido fuso e formatação **GMT** em integrações Google (Analytics / Ads).
- **Área de membros**: produtos extras comprados por vezes ficavam **inacessíveis** após a compra.
- **Comunidade**: corrigido erro ao **criar nova página** na comunidade.

## [1.0.15] - 24-05-2026

### Novidades

- **Reembolsos**:
  - Configuração por produto (**área de membros**) na **edição do produto** (aba **Reembolso**): habilitar solicitação, prazo em dias e modo **automático** ou **aprovação manual**.
  - **Área de membros**: o aluno pode solicitar reembolso pelo menu da conta (com validação de elegibilidade e prazo).
  - **Painel Reembolsos**: listagem, aprovação e rejeição de solicitações, com permissões de equipe (`reembolsos.view` / `reembolsos.manage`).
  - **Vendas**: ação **Reembolsar** no pedido, com confirmação e notas internas.
  - **CajuPay (PIX)**: estorno automático via API (`pix-refund`) quando o pagamento for PIX; confirmação pelo webhook `pix.payment.refunded`.
  - Ao concluir o reembolso, o **acesso do aluno ao produto é revogado** automaticamente.
- **Moedas / vendas globais**:
  - Campo **`currency`** gravado em cada pedido (moeda efetivamente cobrada no checkout; pedidos antigos ficam com **BRL** por defeito).
  - **Vendas**, **Dashboard** e **Relatórios** passam a exibir cada venda e os resumos **na moeda original do pedido** (USD, EUR, BRL, etc.) — sem converter tudo para real na interface.
  - Totais do período agrupados **por moeda** (`valor_por_moeda`), para acompanhar receita internacional com valores corretos em cada divisa.
  - Formatação monetária por moeda (`Intl`) na listagem, cards de estatísticas e exportações relacionadas.
- **Checkout internacional (190+ países)**:
  - Catálogo central de moedas alinhado a **CajuPay Global** e **Stripe** (`config/checkout_currencies.php`), com geo automático por país (ex.: `MX` → MXN).
  - Seletor de moeda no checkout com **BRL, USD e EUR em destaque** e **busca** nas demais moedas habilitadas.
  - **Configurações → Moedas**: botões **Importar moedas internacionais** e **Atualizar todas as taxas** (Frankfurter); comando `php artisan checkout:sync-currency-rates`.
  - Conversão de preços no backend via `rate_to_brl` genérico (sem hardcode só EUR/USD).
  - **Produto**: preços customizados por moeda para todas as moedas habilitadas do tenant (lista com scroll).
  - **Conversão automática**: tenants com só BRL/USD/EUR passam a receber o **catálogo completo** no checkout, com `rate_to_brl` pré-preenchido via **cache de câmbio** (open.er-api.com + Frankfurter, 24h) — sem precisar importar moedas em Configurações; moedas com taxa zerada nas Configurações são preenchidas ao salvar e no checkout.

### Melhorias

- **Meta Pixel / Meta Ads (Conversions API)**:
  - Envio de **Purchase** pela **API de conversões (CAPI)** em fila (`SendMetaPurchaseCapiJob`), com **retentativas** e **deduplicação** por `event_id` alinhado ao pixel do browser.
  - **Moeda e valor** do evento respeitam a moeda real do pedido e o total dos itens (incluindo opção de **excluir order bumps** do evento por pixel).
  - Suporte a **vários pixels Meta** por produto, cada um com token CAPI próprio.
  - **InitiateCheckout** e **Purchase** no checkout com `event_id` consistente para melhor atribuição na Meta.
- **CajuPay**: persistência de `payment_id` nos metadados do pedido via webhook, facilitando reembolsos e rastreio.
- **Checkout internacional**: preços personalizados por moeda estrangeira no produto; na cobrança o valor é convertido para BRL no gateway quando necessário, mas o painel mantém o registo e a visualização na **moeda em que o cliente pagou**.
- **Checkout — telefone**: bandeira e DDI (`+55`, `+1`, etc.) pré-selecionados pela **localização (geo)**; o país detectado aparece **primeiro** na lista do seletor. Se o cliente escolher outro país manualmente, a escolha é respeitada (como no seletor de idioma/moeda).

### Correções

- **Aprovação manual / webhooks**: corrigido `SendMetaPurchaseCapiOnOrderCompleted` (versão antiga com `ShouldQueue` + segundo argumento no `handle()` quebrava `OrderCompleted`, bloqueava webhooks e exibia erro falso ao aprovar venda); integrações não impedem mais a concessão de acesso.
- **Dashboard / vendas após meia-noite**: cache do dashboard não incluía a data no filtro “hoje” (podia mostrar totais zerados ou do dia anterior até expirar o cache); filtros de período passam a usar o fuso `APP_TIMEZONE` com limites corretos de meia-noite; cache de “hoje/ontem” renova em 60s e é invalidado a cada venda concluída.
- **CajuPay / Google Pay**: corrigido pagamento aprovado na CajuPay sem concluir o pedido no Getfy — o SDK não faz mais priming automático antes do `confirm-order`, a wallet só é exibida com dados do cliente válidos e o evento `completed` do SDK dispara materialização do pedido + polling.
- **CajuPay / moeda no Brasil**: cobrança em **BRL** quando o comprador está no BR e não escolheu moeda estrangeira manualmente (evita enviar USD à API com valor em reais).
- **Reembolsos (Vendas)**: corrigido erro ao reembolsar pedidos **sem `user_id`** (comprador resolvido pelo e-mail), falha quando o **`payment_id` CajuPay** não estava disponível e bloqueio após tentativa anterior falhada (nova tentativa permitida).
- **Meta Pixel**: correções no fluxo de **Purchase** após pagamento (browser + CAPI) para reduzir eventos duplicados ou perdidos.

## [1.0.14] - 10-05-2026

### Novidades

- **CajuPay**: integração com a **nova API de cartões e carteiras** (**Google Pay** e **Apple Pay**), alinhada ao fluxo atual do checkout com o SDK da CajuPay.
- **Checkout / Produto — forçar idioma e moeda**: nova opção na edição do produto (aba Geral) para **forçar o idioma** (`pt_BR`, `en` ou `es`) e a **moeda exibida** no checkout público, **sobrepondo a sugestão por país (geo)** até o visitante mudar manualmente no checkout; a moeda é validada contra as moedas configuradas do tenant.
- **Checkout / Produto — preço manual em outras moedas**: nova opção para **definir valores fixos por moeda estrangeira** (exceto BRL) no **preço base do produto**; na finalização o sistema **converte para BRL no servidor** com a taxa `rate_to_brl` configurada em **Configurações → Moedas** (não vale para ofertas/planos com preço próprio).
- **Relatórios**: botões para **baixar público comprador** e **público engajado**, pensados para **públicos personalizados da Meta Ads**.

### Melhorias

- **Spacepag (PIX)**: integração atualizada para a **nova API** (`https://api.spacepag.com/v1`): autenticação com **`X-API-Key`** (um campo **API Key** no painel — aceita `pk_…` ou `sk_…` conforme a documentação Spacepag), criação de cobrança em `POST /payments/transactions`, consulta de status em `GET /payments/transactions/{id}` e tratamento de **webhooks** no formato `WebhookPayload` (`pix.in.confirmation`, etc.), mantendo compatibilidade com o payload legado (`order.paid` / `transaction_id` no corpo). Removido o fluxo antigo JWT + `POST /cob` em `api.spacepag.com.br`. Campo opcional **secret do webhook** documentado no painel de credenciais.
- **Meta Pixel**: revisão do sistema de pixels para que as **vendas sejam registadas de forma consistente** com a **API de conversão** (Conversions API), reduzindo perdas de eventos em relação ao fluxo anterior.

### Correções

- **Checkout**: corrigida a **mudança automática de idioma e moeda** com base na geo/localização, que em alguns cenários **não era aplicada** ao visitante; o fluxo de sugestão e persistência volta a respeitar a geo até o utilizador alterar manualmente no checkout (e respeita a opção de forçar idioma/moeda por produto quando configurada).

## [1.0.13] - 29-04-2026

### Novidades

- **Comprovação (Dossiê) para gateways / MED / chargeback**:
  - Página por venda para **gerar dossiê** com dados do comprador, produto e evidências (progresso, aulas concluídas, logs, IP, UTMs).
  - **Exportação em PDF** por período com filtros (data, produto, forma de pagamento e status) para enviar ao gateway.
  - **Código de verificação** + página pública `/verify/{code}` com resumo mascarado para validar autenticidade.
  - Logs de atividade do aluno na área de membros (abertura, visualização e conclusão de aulas, magic link).

- **API de pagamentos (Checkout Pro)**:
  - Suporte a **pixels de conversão por aplicação** (configuráveis em Aplicações da API), com disparo de **Purchase** no fluxo hospedado.
  - Integração com **UTMfy**: captura/persistência de **UTMs/src/sck** no checkout hospedado e envio correto no payload da UTMfy.

- **UTMfy (Integrações)**:
  - Agora é possível **selecionar também as “Aplicações da API de pagamentos”** (além de produtos) para filtrar quais pedidos disparam eventos.

- **White Label**:
  - E-mails padrão do sistema (ex.: **recuperação de senha** e **confirmação de e-mail**) agora usam **nome e logo** configurados no White Label quando o plugin estiver ativo.

### Correções

- **Área de membros (Outros produtos)**:
  - Ao clicar em um “outro produto” do tipo **Área de membros**, as aulas passam a abrir **dentro da mesma área de membros (hub/host)**, preservando navegação (voltar, contexto etc.), em vez de redirecionar para `/m/{slug}` do outro produto.
  - Produtos do tipo **Link** agora abrem o **link de entrega** em nova aba, evitando **404**.

## [1.0.12] - 26-04-2026

### Correções

- Plugins novos não apareciam.


## [1.0.11] - 26-04-2026

### Novidades

- **Nova função de recuperação de carrinho**.
- **Mais hooks** para atender diversos tipos de plugins.

### Melhorias

- Melhorias e correções de bugs diversos.

### Correções

- Correções de eventos do **Meta Pixel**.
- Correções de **UTMs**.

## [1.0.10] - 17-04-2026

### Novidades

- **Área de membros — aulas em PDF**: novo tipo de aula **“Apresentação (PDF)”** para o aluno **ler o PDF na própria tela** (estilo apresentação): botões anterior/próxima, **setas do teclado**, **tela cheia**, tentativa de **paisagem** em telemóvel quando faz sentido, e **clique ou toque na metade esquerda** (página anterior) ou **direita** (próxima) da zona da apresentação.
- **Área de membros — material em PDF**: o tipo **“Material”** mantém-se como antes: o aluno **descarrega** o ficheiro.
- **Construtor da área de membros**: ao criar ou editar aulas, pode escolher **Material** ou **Apresentação (PDF)** (mesma ideia de ficheiros e links), com **ícone distinto** na lista de aulas.
- **Conclusão da aula**: nas apresentações em PDF a aula **não fica concluída sozinha** ao abrir; o aluno confirma com **“Marcar como concluído”** (comportamento mais justo para conteúdo longo).
- **Outros produtos dentro da mesma área de membros**: quando o hub mostra **outros produtos** (cursos ligados) e o aluno **já tem permissão** (comprou, está num **combo** / pacote com vários produtos, módulo gratuito, etc.), as **aulas desse outro curso podem abrir no mesmo sítio** — mesma área de membros, mesma barra e navegação — **sem sair** para o endereço antigo da área desse produto. Quem ainda não tem acesso continua a ser encaminhado para o **checkout** quando for o caso.
- **Combos e vários produtos**: quem tem acesso a mais do que um produto (por exemplo por **combo**) beneficia desta experiência **única e contínua** no hub, em vez de saltar entre áreas diferentes só para ver aulas de cada produto.

### Melhorias

- **Área de membros**: pequenos ajustes internos na forma como o sistema **reconhece aulas de produtos incorporados** e o progresso do aluno.


## [1.0.9] - 16-04-2026

### Novidades

- **Checkout / Pagar.me e Efí**: endereço de cobrança configurável no produto (modo cliente vs. endereço fixo da empresa), com validação e uso no checkout para cartão e boleto quando esses gateways estão ativos.

### Correções

- **Pixel (Meta Ads)**: evento **InitiateCheckout** voltou a disparar corretamente.
- **Pixel**: evento **Purchase** voltou a disparar corretamente.
- **Notificações push**: corrigido o funcionamento na instalação via **Docker**.
- **Área de membros**: em **iPhone**, vídeos passam a exibir o botão de **tela cheia** (fullscreen) como esperado.

### Melhorias

- Diversas melhorias em todo o código e correção de pequenos bugs não listados individualmente.

## [1.0.8] - 09-04-2026

### Novidades

- Integração com área de membros externa (**Cademí**).
- Sistema de **equipe** com permissões por cargos e membros.
- Integração com **Pagar.me** como gateway de pagamento.
- Editor de checkout: campos avançados para **CSS**, **HTML** (head e corpo) e **JavaScript** personalizados na página pública.

### Correções

- Checkout (mobile): corrigido o zoom indesejado ao focar campos de formulário (inputs).

## [1.0.7] - 04-04-2026

### Correções

- Utmify: envio de eventos (PIX gerado, venda aprovada e status) corrigido para considerar todos os produtos do pedido, incluindo order bumps.
- Utmify: pedidos agora são enviados corretamente mesmo em servidores sem processamento em fila.
- Utmify: falhas de envio agora são tratadas automaticamente com novas tentativas.
- Utmify: removida duplicidade de eventos de pagamento pendente.
- Checkout / Vendas: UTMs agora são capturados e salvos corretamente em todo o processo da compra.
- Vendas: valores, listagem e exportação agora consideram todos os itens do pedido, garantindo consistência com o detalhe da venda.
- Adiciona progresso do aluno no Member Builder
- Diversas correções solicitadas no GitHub

## [1.0.6] - 27-03-2026

### Correções

- Admin: produtos e vendas antigos voltaram a aparecer corretamente no painel após a correção de tenant.
- Vendas: pedidos com **order bump** agora são contabilizados corretamente no total e exibem os itens comprados.
- Vendas: o detalhe da venda voltou a mostrar os dados de **UTM** da compra (utm_source, utm_medium, utm_campaign).
- Integração **Utmify**: envios voltaram a funcionar mesmo em ambientes sem worker/fila ativa.

## [1.0.5] - 27-03-2026

### Correções

- Webhooks configurados pela conta **admin** agora disparam corretamente nos eventos reais (checkout e pagamentos).
- Checkout (boleto): botão de **buscar CEP** voltou a funcionar, permitindo seguir para o pagamento.


## [1.0.4] - 24-03-2026

### Segurança e confiabilidade

- Checkout: agora bloqueia finalizações indevidas e aceita apenas formas de pagamento válidas.
- Checkout: validações extras antes de enviar o pagamento, reduzindo erros.
- API de pagamentos: acesso ao produto é liberado apenas após pagamento confirmado.
- Área de membros: login sem senha não permite contas que acessam o painel.
- Checkout da API: valida o valor contra o preço do produto/oferta/plano quando aplicável.
- Webhooks: mais confiabilidade em eventos de cancelamento/recusa/reembolso, com confirmação extra no gateway.
- Pós-pagamento: melhorias internas na liberação de acesso do comprador (inclui itens adicionais do pedido).

### Novidades

- Novo gateway: **CajuPay**.
- Melhorias de qualidade e testes internos para aumentar a confiabilidade do checkout, webhooks e e-mails.

### Correções

- E-mail de acesso (área de membros): agora mostra com mais clareza o e-mail e a senha provisória.
- Stripe: melhoria no processamento de confirmação de pagamento, refletindo corretamente o pedido como pago.
- Integrações: melhoria na tela de webhooks para indicar quando há token salvo e facilitar edição com segurança.
- Melhorias de diagnósticos em pagamentos para facilitar suporte.

## [1.0.3] - 18-03-2026

### Correções e melhorias no painel

- Painel: melhorias de responsividade em vendas, assinaturas e alunos (mobile).
- Busca: evita preenchimento automático indevido e reduz buscas disparadas durante digitação.
- Cadastros: evita autofill indevido ao cadastrar aluno.
- Mensagens: textos de validação mais claros.
- Configurações: melhor navegação/rolagem no mobile e ajustes na área de armazenamento.
- E-mail de acesso: link corrigido para abrir a área de membros corretamente.
- Vendas: correção no reenvio do e-mail de acesso quando já havia sido enviado.
- Webhooks: melhorias no indicador de token e em disparos/registro de envios em eventos reais.
- E-mail: melhoria no teste de SMTP para funcionar melhor com diferentes configurações.

## [1.0.2] - 18-03-2026

### Novidades

- API de pagamentos: suporte a **PIX automático** no Checkout Pro.

### Melhorias

- API de pagamentos: detecção mais inteligente dos métodos disponíveis no Checkout Pro.

### Correções

- API de pagamentos: correções no disparo de confirmação em alguns cenários.
- API de pagamentos: correções na exibição de métodos (PIX, cartão e boleto).
- Checkout: correções na busca de CEP para evitar travamentos em alguns ambientes.

## [1.0.1] - 15-03-2026

### Novidades

- API de pagamentos: URL de retorno padrão por aplicação.
- API de pagamentos: página de “obrigado” exclusiva do Checkout Pro com redirecionamento.
- Checkout: personalização do rodapé por produto (logo, e-mail e texto).
- Área de membros: suporte a múltiplos PDFs em aulas do tipo material.
- Área de membros: liberação programada de módulos e aulas (por dias/data).
- Painel: busca e filtros avançados em vendas.
- Painel: busca de alunos por nome/e-mail.
- Atualização: script de update para VPS (Docker).

### Correções e estabilidade

- API de pagamentos: correções de redirecionamento pós-pagamento para cair no “obrigado” correto.
- Checkout: correções de botões e fluxos em alguns tipos de produto.
- Cartão (Mercado Pago): correção de status para não ficar pendente após aprovação.
- PIX (Spacepag): melhorias de estabilidade e redução de travamentos em caso de instabilidade.
- Atualização/instalação (Docker/VPS): correções para reduzir falhas e erros de ambiente.
- Painel: correções visuais e melhorias no editor/preview do checkout.
- Área de membros: correções no player de vídeo e no fullscreen no mobile.

## [1.0.0] - 09-03-2026

### Lançamento

Lançamento inicial.
