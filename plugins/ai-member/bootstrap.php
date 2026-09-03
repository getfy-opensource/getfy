<?php

use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\PluginSdk\Getfy;
use App\Services\MemberAreaResolver;
use Plugins\AiMember\Jobs\IndexProductKnowledgeJob;
use Plugins\AiMember\Models\AiMemberAgent;
use Plugins\AiMember\Models\AiMemberConnection;
use Plugins\AiMember\Services\ChatService;
use Plugins\AiMember\Services\IntentClassifier;
use Plugins\AiMember\Services\KnowledgeIndexer;
use Plugins\AiMember\Services\LlmOrchestrator;
use Plugins\AiMember\Services\OpenRouterClient;
use Plugins\AiMember\Services\ProductContextBuilder;
use Plugins\AiMember\Services\RagRetriever;

return function ($app, $events): void {
    $app->singleton(RagRetriever::class);
    $app->singleton(IntentClassifier::class, fn ($app) => new IntentClassifier(
        $app->make(OpenRouterClient::class),
    ));
    $app->singleton(KnowledgeIndexer::class, fn ($app) => new KnowledgeIndexer(
        $app->make(OpenRouterClient::class),
    ));
    $app->singleton(ProductContextBuilder::class);
    $app->singleton(LlmOrchestrator::class, fn ($app) => new LlmOrchestrator(
        $app->make(OpenRouterClient::class),
        $app->make(IntentClassifier::class),
        $app->make(RagRetriever::class),
        $app->make(KnowledgeIndexer::class),
        $app->make(ProductContextBuilder::class),
    ));
    $app->singleton(ChatService::class, fn ($app) => new ChatService(
        $app->make(LlmOrchestrator::class),
        $app->make(OpenRouterClient::class),
    ));
    $app->bind(OpenRouterClient::class, fn () => new OpenRouterClient(null));

    Getfy::extensions()->register('ai-member', [
        'integration_status_resolver' => function (?int $tenantId): bool {
            if ($tenantId === null) {
                return false;
            }
            $conn = AiMemberConnection::forTenant($tenantId)->first();

            return (bool) ($conn?->isConfigured() && $conn->is_active);
        },
    ]);

    Getfy::hooks()->addFilter('inertia.shared', function (array $shared, $request) {
        $path = $request->path();
        $resolver = app(MemberAreaResolver::class);
        $resolved = null;

        $isMemberArea = str_starts_with($path, 'm/')
            || $request->attributes->get('member_area_slug')
            || $request->attributes->get('member_area_product');

        if (! $isMemberArea) {
            $resolved = $resolver->resolve($request);
            $isMemberArea = $resolved !== null;
        }

        if (! $isMemberArea) {
            return $shared;
        }

        $product = $request->attributes->get('member_area_product');
        if (! $product instanceof Product) {
            $routeProduct = $request->route('product');
            if ($routeProduct instanceof Product) {
                $product = $routeProduct;
            } else {
                $resolved ??= $resolver->resolve($request);
                $product = $resolved['product'] ?? null;
            }
        }

        if (! $product instanceof Product) {
            return $shared;
        }

        $conn = AiMemberConnection::forTenant($product->tenant_id)->first();
        $agent = AiMemberAgent::query()->where('product_id', $product->id)->first();

        if (! $conn?->isConfigured() || ! $conn->is_active || ! $agent?->enabled) {
            $shared['ai_member_widget'] = ['enabled' => false, 'product_id' => $product->id];

            return $shared;
        }

        $themePrimary = ($product->member_area_config['theme']['primary'] ?? null) ?: '#0ea5e9';

        $shared['ai_member_widget'] = array_merge(
            ['enabled' => true, 'product_id' => $product->id],
            $agent->toPublicWidgetConfig($themePrimary),
        );

        return $shared;
    });

    $scheduleReindex = function (?string $productId): void {
        if ($productId) {
            IndexProductKnowledgeJob::dispatch($productId);
        }
    };

    MemberLesson::saved(fn (MemberLesson $lesson) => $scheduleReindex($lesson->product_id));
    MemberLesson::deleted(fn (MemberLesson $lesson) => $scheduleReindex($lesson->product_id));
    MemberModule::saved(fn (MemberModule $module) => $scheduleReindex($module->product_id));
    MemberSection::saved(fn (MemberSection $section) => $scheduleReindex($section->product_id));
};
