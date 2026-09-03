<?php

namespace Plugins\AiMember\Console;

use Illuminate\Console\Command;
use Plugins\AiMember\Services\KnowledgeIndexer;
use Plugins\AiMember\Services\OpenRouterClient;

class ReindexCommand extends Command
{
    protected $signature = 'ai-member:reindex {product_id : UUID do produto}';

    protected $description = 'Reindexa a base de conhecimento do AI Member para um produto';

    public function handle(): int
    {
        $productId = (string) $this->argument('product_id');
        $product = \App\Models\Product::query()->find($productId);
        if (! $product) {
            $this->error('Produto não encontrado.');

            return self::FAILURE;
        }

        $client = new OpenRouterClient($product->tenant_id);
        if (! $client->isConfigured()) {
            $this->error('OpenRouter não configurado para este tenant.');

            return self::FAILURE;
        }

        $count = (new KnowledgeIndexer($client))->indexProduct($productId);
        $this->info("Indexação concluída: {$count} fontes processadas.");

        return self::SUCCESS;
    }
}
