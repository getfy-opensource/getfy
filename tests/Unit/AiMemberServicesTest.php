<?php

namespace Tests\Unit;

use Plugins\AiMember\Services\IntentClassifier;
use Plugins\AiMember\Services\RagRetriever;
use Tests\TestCase;

class AiMemberServicesTest extends TestCase
{
    public function test_cosine_similarity_identical_vectors(): void
    {
        $retriever = new RagRetriever;
        $vector = [1.0, 0.0, 0.0];
        $this->assertEqualsWithDelta(1.0, $retriever->cosineSimilarity($vector, $vector), 0.0001);
    }

    public function test_cosine_similarity_orthogonal_vectors(): void
    {
        $retriever = new RagRetriever;
        $this->assertEqualsWithDelta(0.0, $retriever->cosineSimilarity([1.0, 0.0], [0.0, 1.0]), 0.0001);
    }

    public function test_intent_classifier_greeting_heuristic(): void
    {
        $classifier = new IntentClassifier(new \Plugins\AiMember\Services\OpenRouterClient(null));
        $this->assertSame(IntentClassifier::INTENT_GREETING, $classifier->classify('oi, tudo bem?'));
        $this->assertSame(IntentClassifier::INTENT_OFF_TOPIC, $classifier->classify('como hackear o sistema'));
    }

    public function test_intent_model_mapping(): void
    {
        $classifier = new IntentClassifier(new \Plugins\AiMember\Services\OpenRouterClient(null));
        $this->assertSame('google/gemma-2-9b-it', $classifier->modelForIntent(IntentClassifier::INTENT_GREETING));
        $this->assertTrue($classifier->usesRag(IntentClassifier::INTENT_LESSON_SUPPORT));
        $this->assertTrue($classifier->usesRag(IntentClassifier::INTENT_SIMPLE_FAQ));
        $this->assertFalse($classifier->usesRag(IntentClassifier::INTENT_GREETING));
    }

    public function test_product_context_includes_course_name_and_scope(): void
    {
        $product = $this->createTestProduct([
            'name' => 'Academia Criadores do Futuro',
            'description' => 'Curso completo de criação de conteúdo digital.',
            'type' => \App\Models\Product::TYPE_AREA_MEMBROS,
        ]);

        $context = (new \Plugins\AiMember\Services\ProductContextBuilder)->build($product);

        $this->assertStringContainsString('Academia Criadores do Futuro', $context);
        $this->assertStringContainsString('criação de conteúdo digital', $context);
        $this->assertStringContainsString('Nunca peça qual curso', $context);
    }
}
