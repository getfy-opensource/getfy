<?php

namespace Plugins\AiMember\Services;

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use Illuminate\Support\Str;
use Plugins\AiMember\Models\AiMemberAgent;
use Plugins\AiMember\Models\AiMemberDocument;
use Plugins\AiMember\Models\AiMemberKnowledgeChunk;

class KnowledgeIndexer
{
    private const EMBEDDING_MODEL = 'openai/text-embedding-3-small';

    public function __construct(
        private readonly OpenRouterClient $client,
    ) {}

    public function indexProduct(string $productId): int
    {
        $product = Product::query()->find($productId);
        if (! $product || $product->type !== Product::TYPE_AREA_MEMBROS) {
            return 0;
        }

        $agent = AiMemberAgent::query()->firstOrCreate(
            ['product_id' => $productId],
            ['tenant_id' => $product->tenant_id, 'enabled' => false, 'name' => 'Assistente'],
        );

        $sources = $this->collectSources($product);

        AiMemberKnowledgeChunk::query()->where('product_id', $productId)->delete();

        $count = 0;

        foreach ($sources as $source) {
            $hash = hash('sha256', $source['content']);
            $embedding = $this->embedText($source['content'], $product->tenant_id);

            AiMemberKnowledgeChunk::query()->create([
                'product_id' => $productId,
                'tenant_id' => $product->tenant_id,
                'source_type' => $source['source_type'],
                'source_id' => $source['source_id'],
                'title' => $source['title'],
                'content' => $source['content'],
                'embedding' => $embedding,
                'content_hash' => $hash,
                'indexed_at' => now(),
            ]);
            $count++;
        }

        $agent->knowledge_indexed_at = now();
        $agent->knowledge_chunks_count = AiMemberKnowledgeChunk::query()->where('product_id', $productId)->count();
        $agent->save();

        return $count;
    }

    public function embedQuery(string $text, int $tenantId): array
    {
        return $this->embedText($text, $tenantId, 'search_query');
    }

    /**
     * @return array<int, array{source_type: string, source_id: string, title: string, content: string}>
     */
    private function collectSources(Product $product): array
    {
        $sources = [];

        $productContent = trim(implode("\n", array_filter([
            "Produto: {$product->name}",
            $product->description ? "Descrição: {$product->description}" : null,
        ])));
        if ($productContent !== '') {
            $sources[] = [
                'source_type' => 'product',
                'source_id' => (string) $product->id,
                'title' => $product->name,
                'content' => $productContent,
            ];
        }

        $sections = MemberSection::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->with(['modules.lessons'])
            ->get();

        foreach ($sections as $section) {
            $sectionContent = trim("Seção: {$section->title}");
            if ($sectionContent !== '') {
                $sources[] = [
                    'source_type' => 'section',
                    'source_id' => (string) $section->id,
                    'title' => $section->title,
                    'content' => $sectionContent,
                ];
            }

            foreach ($section->modules as $module) {
                $sources = array_merge($sources, $this->moduleSources($module, $section->title));
            }
        }

        $documents = AiMemberDocument::query()
            ->where('product_id', $product->id)
            ->orderBy('position')
            ->get();

        foreach ($documents as $doc) {
            $content = trim("Documento: {$doc->title}\n{$doc->content}");
            if ($content === '') {
                continue;
            }
            $sources[] = [
                'source_type' => 'document',
                'source_id' => (string) $doc->id,
                'title' => $doc->title,
                'content' => $content,
            ];
        }

        return $sources;
    }

    /**
     * @return array<int, array{source_type: string, source_id: string, title: string, content: string}>
     */
    private function moduleSources(MemberModule $module, string $sectionTitle): array
    {
        $sources = [];
        $moduleHeader = trim("Módulo: {$module->title} (Seção: {$sectionTitle})");
        if ($moduleHeader !== '') {
            $sources[] = [
                'source_type' => 'module',
                'source_id' => (string) $module->id,
                'title' => $module->title,
                'content' => $moduleHeader,
            ];
        }

        foreach ($module->lessons as $lesson) {
            $lessonContent = $this->lessonContent($lesson, $module->title, $sectionTitle);
            if ($lessonContent === '') {
                continue;
            }
            $sources[] = [
                'source_type' => 'lesson',
                'source_id' => (string) $lesson->id,
                'title' => $lesson->title,
                'content' => $lessonContent,
            ];
        }

        return $sources;
    }

    private function lessonContent(MemberLesson $lesson, string $moduleTitle, string $sectionTitle): string
    {
        $parts = [
            "Aula: {$lesson->title}",
            "Módulo: {$moduleTitle}",
            "Seção: {$sectionTitle}",
            "Tipo: {$lesson->type}",
        ];

        if ($lesson->duration_seconds) {
            $parts[] = 'Duração: '.gmdate('H:i:s', (int) $lesson->duration_seconds);
        }

        if ($lesson->content_text) {
            $parts[] = 'Conteúdo: '.Str::limit(strip_tags($lesson->content_text), 2000);
        }

        if ($lesson->link_title) {
            $parts[] = "Link: {$lesson->link_title}";
        }

        if (is_array($lesson->useful_links) && $lesson->useful_links !== []) {
            $parts[] = 'Links úteis: '.json_encode($lesson->useful_links, JSON_UNESCAPED_UNICODE);
        }

        return trim(implode("\n", $parts));
    }

    /**
     * @return array<int, float>
     */
    private function embedText(string $text, int $tenantId, string $inputType = 'search_document'): array
    {
        $client = $this->client->forTenant($tenantId);
        $response = $client->embeddings([
            'model' => self::EMBEDDING_MODEL,
            'input' => mb_substr($text, 0, 8000),
            'input_type' => $inputType,
        ]);

        $embedding = $response['data'][0]['embedding'] ?? null;

        return is_array($embedding) ? array_map('floatval', $embedding) : [];
    }
}
