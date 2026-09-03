<?php

namespace Plugins\AiMember\Services;

class IntentClassifier
{
    public const INTENT_GREETING = 'greeting';

    public const INTENT_SIMPLE_FAQ = 'simple_faq';

    public const INTENT_LESSON_SUPPORT = 'lesson_support';

    public const INTENT_COMPLEX = 'complex';

    public const INTENT_VISION = 'vision';

    public const INTENT_OFF_TOPIC = 'off_topic';

    private const ROUTER_MODEL = 'google/gemma-2-9b-it';

    public function __construct(
        private readonly OpenRouterClient $client,
    ) {}

    public function classify(string $message, bool $hasImage = false, bool $hasAudio = false, ?int $tenantId = null): string
    {
        if ($hasImage) {
            return self::INTENT_VISION;
        }

        $normalized = mb_strtolower(trim($message));
        if ($normalized === '') {
            return self::INTENT_GREETING;
        }

        if ($this->matchesGreeting($normalized)) {
            return self::INTENT_GREETING;
        }

        if ($this->matchesOffTopic($normalized)) {
            return self::INTENT_OFF_TOPIC;
        }

        if ($tenantId === null) {
            return strlen($message) > 200 ? self::INTENT_COMPLEX : self::INTENT_LESSON_SUPPORT;
        }

        try {
            return $this->classifyWithLlm($message, $hasAudio, $tenantId);
        } catch (\Throwable) {
            return strlen($message) > 200 ? self::INTENT_COMPLEX : self::INTENT_LESSON_SUPPORT;
        }
    }

    public function modelForIntent(string $intent): string
    {
        return match ($intent) {
            self::INTENT_GREETING => 'google/gemma-2-9b-it',
            self::INTENT_SIMPLE_FAQ => 'qwen/qwen-2.5-7b-instruct',
            self::INTENT_LESSON_SUPPORT => 'google/gemini-2.0-flash-001',
            self::INTENT_COMPLEX => 'openai/gpt-4o-mini',
            self::INTENT_VISION => 'google/gemini-2.0-flash-001',
            default => 'qwen/qwen-2.5-7b-instruct',
        };
    }

    public function maxTokensForIntent(string $intent): int
    {
        return match ($intent) {
            self::INTENT_GREETING => 150,
            self::INTENT_SIMPLE_FAQ => 400,
            self::INTENT_LESSON_SUPPORT => 800,
            self::INTENT_COMPLEX => 1200,
            self::INTENT_VISION => 800,
            default => 300,
        };
    }

    public function usesRag(string $intent): bool
    {
        return in_array($intent, [
            self::INTENT_SIMPLE_FAQ,
            self::INTENT_LESSON_SUPPORT,
            self::INTENT_COMPLEX,
            self::INTENT_VISION,
        ], true);
    }

    private function classifyWithLlm(string $message, bool $hasAudio, int $tenantId): string
    {
        $client = $this->client->forTenant($tenantId);
        $response = $client->chatCompletions([
            'model' => self::ROUTER_MODEL,
            'max_tokens' => 20,
            'temperature' => 0,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'Classifique a mensagem do aluno em UMA categoria: greeting, simple_faq, lesson_support, complex, off_topic. Responda só a categoria.',
                ],
                [
                    'role' => 'user',
                    'content' => $hasAudio ? "[mensagem transcrita de áudio] {$message}" : $message,
                ],
            ],
        ]);

        $raw = trim(strtolower((string) ($response['choices'][0]['message']['content'] ?? '')));
        $valid = [
            self::INTENT_GREETING,
            self::INTENT_SIMPLE_FAQ,
            self::INTENT_LESSON_SUPPORT,
            self::INTENT_COMPLEX,
            self::INTENT_OFF_TOPIC,
        ];
        foreach ($valid as $intent) {
            if (str_contains($raw, $intent)) {
                return $intent;
            }
        }

        return self::INTENT_LESSON_SUPPORT;
    }

    private function matchesGreeting(string $text): bool
    {
        return (bool) preg_match('/^(oi|olá|ola|hey|bom dia|boa tarde|boa noite|obrigad|valeu|tchau|até|ate)\b/u', $text);
    }

    private function matchesOffTopic(string $text): bool
    {
        return (bool) preg_match('/\b(hackear|crackear|senha de outro|drogas|armas|política|eleição)\b/u', $text);
    }
}
