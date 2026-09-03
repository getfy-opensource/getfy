<?php

namespace Plugins\AiMember\Services;

class RagRetriever
{
    public const SIMILARITY_THRESHOLD = 0.72;

    public const TOP_K = 5;

    /**
     * @param  array<int, float>  $queryEmbedding
     * @param  iterable<\Plugins\AiMember\Models\AiMemberKnowledgeChunk>  $chunks
     * @return array<int, array{chunk: \Plugins\AiMember\Models\AiMemberKnowledgeChunk, score: float}>
     */
    public function retrieve(iterable $chunks, array $queryEmbedding, int $topK = self::TOP_K): array
    {
        $scored = [];
        foreach ($chunks as $chunk) {
            $embedding = $chunk->embedding;
            if (! is_array($embedding) || $embedding === []) {
                continue;
            }
            $score = $this->cosineSimilarity($queryEmbedding, $embedding);
            if ($score >= self::SIMILARITY_THRESHOLD) {
                $scored[] = ['chunk' => $chunk, 'score' => $score];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $topK);
    }

    /**
     * @param  array<int, float>  $a
     * @param  array<int, float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $len = min(count($a), count($b));
        if ($len === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;
        for ($i = 0; $i < $len; $i++) {
            $dot += $a[$i] * $b[$i];
            $normA += $a[$i] * $a[$i];
            $normB += $b[$i] * $b[$i];
        }

        if ($normA <= 0.0 || $normB <= 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }

    /**
     * @param  array<int, array{chunk: \Plugins\AiMember\Models\AiMemberKnowledgeChunk, score: float}>  $results
     */
    public function formatContext(array $results): string
    {
        if ($results === []) {
            return '';
        }

        $lines = ["Contexto do curso (use para responder):"];
        foreach ($results as $item) {
            $chunk = $item['chunk'];
            $title = trim((string) ($chunk->title ?? 'Conteúdo'));
            $content = trim((string) $chunk->content);
            $lines[] = "- [{$title}] {$content}";
        }

        return implode("\n", $lines);
    }
}
