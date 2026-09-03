<?php

namespace Plugins\AiMember\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Plugins\AiMember\Services\KnowledgeIndexer;
use Plugins\AiMember\Services\OpenRouterClient;

class IndexProductKnowledgeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $productId,
    ) {}

    public function handle(): void
    {
        $product = \App\Models\Product::query()->find($this->productId);
        if (! $product) {
            return;
        }

        $client = new OpenRouterClient($product->tenant_id);
        if (! $client->isConfigured()) {
            return;
        }

        (new KnowledgeIndexer($client))->indexProduct($this->productId);
    }
}
