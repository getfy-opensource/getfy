# Changelog

## [1.0.10] - 17-04-2026

### Novidades

- **Área de membros — aulas em PDF**: novo tipo de aula **“Apresentação (PDF)”** para o aluno **ler o PDF na própria tela** (estilo apresentação): botões anterior/próxima, **setas do teclado**, **tela cheia**, tentativa de **paisagem** em telemóvel quando faz sentido, e **clique ou toque na metade esquerda** (página anterior) ou **direita** (próxima) da zona da apresentação.
- **Área de membros — material em PDF**: o tipo **“Material”** mantém-se como antes: o aluno **descarrega** o ficheiro.
- **Construtor da área de membros**: ao criar ou editar aulas, pode escolher **Material** ou **Apresentação (PDF)** (mesma ideia de ficheiros e links), com **ícone distinto** na lista de aulas.
- **Conclusão da aula**: nas apresentações em PDF a aula **não fica concluída sozinha** ao abrir; o aluno confirma com **“Marcar como concluído”** (comportamento mais justo para conteúdo longo).
- **Outros produtos dentro da mesma área de membros**: quando o hub mostra **outros produtos** (cursos ligados) e o aluno **já tem permissão** (comprou, está num **combo** / pacote com vários produtos, módulo gratuito, etc.), as **aulas desse outro curso podem abrir no mesmo sítio** — mesma área de membros, mesma barra e navegação — **sem sair** para o endereço antigo da área desse produto. Quem ainda não tem acesso continua a ser encaminhado para o **checkout** quando for o caso.
- **Combos e vários produtos**: quem tem acesso a mais do que um produto (por exemplo por **combo**) beneficia desta experiência **única e contínua** no hub, em vez de saltar entre áreas diferentes só para ver aulas de cada produto.

### Melhorias

- **Atualização do projeto**: após atualizar o código, execute **`npm install`** uma vez — foi adicionada uma biblioteca necessária só para **mostrar PDFs na tela** na área de membros.
- **Área de membros**: pequenos ajustes internos na forma como o sistema **reconhece aulas de produtos incorporados** e o progresso do aluno.

### Correções

- **Testes automatizados**: corrigido um teste da área de membros sobre **módulos incorporados** (não altera o dia a dia do utilizador final).

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
