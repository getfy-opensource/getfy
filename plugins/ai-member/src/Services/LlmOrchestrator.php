<?php

namespace Plugins\AiMember\Services;

use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Plugins\AiMember\Models\AiMemberAgent;
use Plugins\AiMember\Models\AiMemberKnowledgeChunk;

class LlmOrchestrator
{
    public function __construct(
        private readonly OpenRouterClient $client,
        private readonly IntentClassifier $classifier,
        private readonly RagRetriever $retriever,
        private readonly KnowledgeIndexer $indexer,
        private readonly ProductContextBuilder $productContext,
    ) {}

    /**
     * @param  array<int, array{role: string, content: string|array}>  $history
     * @param  array{image_base64?: string, image_mime?: string, audio_base64?: string, audio_mime?: string}  $attachments
     * @return array{content: string, intent: string, model: string, tokens_in: int, tokens_out: int}
     */
    public function respond(
        AiMemberAgent $agent,
        Product $product,
        string $userMessage,
        array $history = [],
        array $attachments = [],
        ?string $studentName = null,
        bool $isFirstMessage = false,
    ): array {
        $tenantId = (int) $agent->tenant_id;
        $client = $this->client->forTenant($tenantId);

        $hasImage = ! empty($attachments['image_base64']) && $agent->allow_image;
        $hasAudio = ! empty($attachments['audio_base64']) && $agent->allow_audio;

        if ($hasAudio) {
            try {
                $transcribed = $client->transcribe(
                    $attachments['audio_base64'],
                    $attachments['audio_mime'] ?? 'audio/webm',
                );
                if ($transcribed !== '') {
                    $userMessage = trim($userMessage."\n\n[Áudio transcrito]: ".$transcribed);
                }
            } catch (\Throwable) {
                // segue com mensagem original
            }
        }

        $intent = $this->classifier->classify($userMessage, $hasImage, $hasAudio, $tenantId);

        if ($intent === IntentClassifier::INTENT_OFF_TOPIC) {
            return [
                'content' => 'Desculpe, só posso ajudar com dúvidas sobre este curso e sua área de membros. Posso esclarecer algo sobre as aulas ou o conteúdo?',
                'intent' => $intent,
                'model' => 'template',
                'tokens_in' => 0,
                'tokens_out' => 0,
            ];
        }

        $context = '';
        if ($this->classifier->usesRag($intent)) {
            $cacheKey = 'ai_member:embed:'.md5($userMessage.':'.$agent->product_id);
            $queryEmbedding = Cache::remember($cacheKey, 60, fn () => $this->indexer->embedQuery($userMessage, $tenantId));
            $chunks = AiMemberKnowledgeChunk::query()
                ->where('product_id', $agent->product_id)
                ->whereNotNull('embedding')
                ->get();
            $results = $this->retriever->retrieve($chunks, $queryEmbedding);
            $context = $this->retriever->formatContext($results);
        }

        $systemPrompt = $this->buildSystemPrompt(
            $agent,
            $product,
            $context,
            $studentName,
            $isFirstMessage,
        );
        $model = $this->classifier->modelForIntent($intent);
        $maxTokens = min(
            $agent->max_tokens,
            $this->classifier->maxTokensForIntent($intent),
        );

        $messages = [['role' => 'system', 'content' => $systemPrompt]];
        foreach ($history as $msg) {
            if (! empty($msg['role']) && isset($msg['content'])) {
                $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
            }
        }

        $userContent = $this->buildUserContent($userMessage, $hasImage ? $attachments : []);
        $messages[] = ['role' => 'user', 'content' => $userContent];

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => (float) $agent->temperature,
        ];

        try {
            $response = $client->chatCompletions($payload);
        } catch (\Throwable $e) {
            if ($model !== 'openai/gpt-4o-mini') {
                $payload['model'] = 'openai/gpt-4o-mini';
                $response = $client->chatCompletions($payload);
                $model = 'openai/gpt-4o-mini';
            } else {
                throw $e;
            }
        }

        $content = trim((string) ($response['choices'][0]['message']['content'] ?? ''));
        $usage = $response['usage'] ?? [];

        return [
            'content' => $content !== '' ? $content : 'Desculpe, não consegui gerar uma resposta. Tente reformular sua pergunta.',
            'intent' => $intent,
            'model' => $model,
            'tokens_in' => (int) ($usage['prompt_tokens'] ?? 0),
            'tokens_out' => (int) ($usage['completion_tokens'] ?? 0),
        ];
    }

    private function buildSystemPrompt(
        AiMemberAgent $agent,
        Product $product,
        string $context,
        ?string $studentName = null,
        bool $isFirstMessage = false,
    ): string {
        $parts = [
            "Você é {$agent->name}, assistente de suporte na área de membros.",
            $agent->genderPronounHint(),
            $this->productContext->build($product),
        ];

        if ($agent->personality) {
            $parts[] = "Personalidade: {$agent->personality}";
        }

        if ($agent->system_instructions) {
            $parts[] = "Instruções do produtor:\n{$agent->system_instructions}";
        }

        $parts[] = 'Responda em português do Brasil, de forma clara e objetiva. Se não souber, diga honestamente e sugira onde o aluno pode encontrar a informação no curso.';

        if ($isFirstMessage) {
            $name = trim((string) $studentName);
            $greeting = $name !== '' ? "Cumprimente {$name} pelo nome" : 'Cumprimente o aluno calorosamente';
            $parts[] = "Esta é a PRIMEIRA mensagem desta conversa. {$greeting}, apresente-se como {$agent->name} e convide-o a perguntar sobre o curso \"{$product->name}\".";
        }

        if ($context !== '') {
            $parts[] = $context;
        }

        return implode("\n\n", $parts);
    }

    /**
     * @param  array{image_base64?: string, image_mime?: string}  $attachments
     * @return string|array<int, array<string, mixed>>
     */
    private function buildUserContent(string $text, array $attachments): string|array
    {
        if (empty($attachments['image_base64'])) {
            return $text;
        }

        $mime = $attachments['image_mime'] ?? 'image/jpeg';
        $parts = [
            ['type' => 'text', 'text' => $text !== '' ? $text : 'O aluno enviou esta imagem. Descreva o que vê e ajude com base no curso.'],
            [
                'type' => 'image_url',
                'image_url' => [
                    'url' => "data:{$mime};base64,{$attachments['image_base64']}",
                ],
            ],
        ];

        return $parts;
    }
}
