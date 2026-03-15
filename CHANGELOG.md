# Changelog


## [1.0.1] - 15-03-2026

### Added

- Adiciona URL de retorno padrão por aplicação na API de Pagamentos.
- Adiciona página de obrigado exclusiva do Checkout Pro (API) com redirecionamento.
- Adiciona personalização do rodapé do checkout por produto (logo, e-mail e texto).
- Adiciona suporte a múltiplos PDFs em aulas do tipo material na área de membros.
- Adiciona liberação programada de módulos e aulas por dias ou data.
- Adiciona busca e filtros avançados na página de vendas (/vendas).
- Adiciona busca de alunos por nome/e-mail na página de alunos.

### Fixed

- Corrige erro na migration de UUID em products ao dropar PRIMARY KEY em tabelas pivot com FKs.
- Corrige redirecionamento pós-pagamento do Checkout Pro (API) para não cair no obrigado de produtos.
- Corrige botão na página de obrigado para produto "Somente link de pagamento".
- Corrige venda no cartão (Mercado Pago) ficando como pendente após aprovação.
- Corrige instabilidade/timeouts ao gerar PIX no Spacepag em alguns servidores (retry/IPv4 e timeouts configuráveis).
- Corrige dropdown de ações na listagem de vendas (/vendas) para abrir sobre a tabela.
- Corrige preview do editor de checkout para respeitar oferta/plano ao renderizar.
- Corrige player de vídeo da área de membros (CSP do YouTube, fullscreen e fallback).
- Força orientação landscape no fullscreen do player no mobile (quando suportado).

## [1.0.0] - 09-03-2026

### Added

Lançamento inicial.
