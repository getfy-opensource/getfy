# Changelog


## [1.0.2] - 18-03-2026

### Added

- Adiciona suporte a PIX automático no Checkout Pro da API de Pagamentos.

### Changed

- Melhora a detecção de métodos disponíveis no Checkout Pro da API (considera gateways conectados e fallback).

### Fixed

- Corrige disparo do webhook de confirmação (order.completed) na API quando o pedido não possui produto.
- Corrige exibição de métodos no Checkout Pro da API (PIX, cartão e boleto) para não ficar apenas em PIX.
- Corrige busca de CEP no checkout (ViaCEP) removendo headers que causavam bloqueio por CORS e evitando loader travado.

## [1.0.1] - 15-03-2026

### Added

- Adiciona URL de retorno padrão por aplicação na API de Pagamentos.
- Adiciona página de obrigado exclusiva do Checkout Pro (API) com redirecionamento.
- Adiciona personalização do rodapé do checkout por produto (logo, e-mail e texto).
- Adiciona suporte a múltiplos PDFs em aulas do tipo material na área de membros.
- Adiciona liberação programada de módulos e aulas por dias ou data.
- Adiciona busca e filtros avançados na página de vendas (/vendas).
- Adiciona busca de alunos por nome/e-mail na página de alunos.
- Adiciona script update.sh para atualização manual em VPS (Docker).

### Fixed

- Corrige erro na migration de UUID em products ao dropar PRIMARY KEY em tabelas pivot com FKs.
- Corrige redirecionamento pós-pagamento do Checkout Pro (API) para não cair no obrigado de produtos.
- Corrige botão na página de obrigado para produto "Somente link de pagamento".
- Corrige venda no cartão (Mercado Pago) ficando como pendente após aprovação.
- Corrige instabilidade/timeouts ao gerar PIX no Spacepag em alguns servidores (retry/IPv4 e timeouts configuráveis).
- Reduz tempo do retry do Spacepag em timeouts para não travar o checkout.
- Força HTTP/1.1 no Spacepag/Sapcepag e sanitiza base_url para evitar timeouts por HTTP/2 e URLs mal coladas.
- Corrige atualização automática em VPS/Docker quando o Git bloqueia o repositório por "dubious ownership".
- Corrige atualização automática no Docker quando vendor/bin/composer não existe.
- Corrige erro 500 em produção quando QUEUE_CONNECTION está inválido (ex.: file).
- Corrige instalador Docker quando há alterações locais no repositório (stash obrigatório antes do checkout).
- Corrige editor de checkout resetando gateways selecionados por método de pagamento ao salvar.
- Bloqueia acesso ao /docker-setup após a configuração inicial do domínio.
- Evita erro 500 no checkout quando falha ao gerar pagamento e a ordem não pode ser deletada.
- Reduz lentidão do checkout em falhas de gateways com timeouts menores e limite total por tentativa.
- Corrige dropdown de ações na listagem de vendas (/vendas) para abrir sobre a tabela.
- Corrige preview do editor de checkout para respeitar oferta/plano ao renderizar.
- Corrige player de vídeo da área de membros (CSP do YouTube, fullscreen e fallback).
- Força orientação landscape no fullscreen do player no mobile (quando suportado).

## [1.0.0] - 09-03-2026

### Added

Lançamento inicial.
