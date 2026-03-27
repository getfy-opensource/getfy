# Changelog

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
