<?php

namespace Plugins\AiMember\Services;

use App\Models\MemberSection;
use App\Models\Product;
use Illuminate\Support\Str;
use Plugins\AiMember\Models\AiMemberDocument;

class ProductContextBuilder
{
    public function build(Product $product): string
    {
        $lines = [
            '=== CONTEXTO DO CURSO (sempre válido nesta conversa) ===',
            "Nome do curso: {$product->name}",
            'O aluno está logado na área de membros DESTE curso/produto.',
            'Quando o aluno mencionar "este curso", "o curso", "aqui" ou termos similares, refere-se SEMPRE a este produto.',
            'Nunca peça qual curso o aluno quer saber — você já sabe que é este.',
        ];

        $description = trim((string) ($product->description ?? ''));
        if ($description !== '') {
            $lines[] = "Descrição do curso: {$description}";
        }

        $heroContext = $this->heroContext($product);
        if ($heroContext !== '') {
            $lines[] = $heroContext;
        }

        $outline = $this->buildOutline($product);
        if ($outline !== '') {
            $lines[] = "Estrutura do curso:\n{$outline}";
        }

        $faqs = AiMemberDocument::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->limit(8)
            ->get(['title', 'content']);

        if ($faqs->isNotEmpty()) {
            $lines[] = 'Informações adicionais configuradas pelo produtor:';
            foreach ($faqs as $doc) {
                $snippet = Str::limit(trim(strip_tags((string) $doc->content)), 400);
                $lines[] = "- {$doc->title}".($snippet !== '' ? ": {$snippet}" : '');
            }
        }

        return implode("\n", $lines);
    }

    private function heroContext(Product $product): string
    {
        $config = $product->member_area_config ?? [];
        $slides = is_array($config['hero']['slides'] ?? null) ? $config['hero']['slides'] : [];
        $first = $slides[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        $parts = array_filter([
            trim((string) ($first['title'] ?? '')) !== '' ? 'Título da área: '.$first['title'] : null,
            trim((string) ($first['subtitle'] ?? '')) !== '' ? 'Subtítulo: '.$first['subtitle'] : null,
        ]);

        return $parts === [] ? '' : implode("\n", $parts);
    }

    private function buildOutline(Product $product): string
    {
        $sections = MemberSection::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->with([
                'modules' => fn ($q) => $q->orderBy('position')->withCount('lessons'),
            ])
            ->limit(12)
            ->get();

        if ($sections->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($sections as $section) {
            $lines[] = "• {$section->title}";
            foreach ($section->modules->take(8) as $module) {
                $count = (int) ($module->lessons_count ?? 0);
                $suffix = $count > 0 ? " ({$count} aula".($count === 1 ? '' : 's').')' : '';
                $lines[] = "  - {$module->title}{$suffix}";
            }
        }

        return implode("\n", $lines);
    }
}
