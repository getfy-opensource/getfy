<?php

namespace Plugins\AiMember\Http\Controllers;

use App\Models\Product;
use App\Services\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\AiMember\Jobs\IndexProductKnowledgeJob;
use Plugins\AiMember\Models\AiMemberAgent;
use Plugins\AiMember\Models\AiMemberConnection;
use Plugins\AiMember\Models\AiMemberDocument;
use Plugins\AiMember\Services\KnowledgeIndexer;
use Plugins\AiMember\Services\OpenRouterClient;

class AgentController extends Controller
{
    public function show(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $agent = AiMemberAgent::query()->firstOrCreate(
            ['product_id' => $product->id],
            [
                'tenant_id' => $product->tenant_id,
                'enabled' => false,
                'name' => 'Assistente',
                'welcome_message' => 'Olá! Sou seu assistente. Como posso ajudar?',
            ],
        );

        $conn = AiMemberConnection::forTenant($product->tenant_id)->first();

        return response()->json([
            'agent' => $this->agentToArray($agent),
            'documents' => AiMemberDocument::query()
                ->where('product_id', $product->id)
                ->orderBy('position')
                ->get()
                ->map(fn ($d) => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'content' => $d->content,
                    'position' => $d->position,
                ]),
            'openrouter_configured' => (bool) ($conn?->isConfigured() && $conn->is_active),
        ]);
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $validated = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'name' => ['sometimes', 'string', 'max:120'],
            'gender' => ['sometimes', 'string', 'in:male,female,neutral'],
            'personality' => ['nullable', 'string', 'max:2000'],
            'temperature' => ['sometimes', 'numeric', 'min:0', 'max:2'],
            'max_tokens' => ['sometimes', 'integer', 'min:100', 'max:4000'],
            'system_instructions' => ['nullable', 'string', 'max:10000'],
            'welcome_message' => ['nullable', 'string', 'max:1000'],
            'intro_headline' => ['nullable', 'string', 'max:255'],
            'widget_icon' => ['nullable', 'string', 'max:512'],
            'widget_color' => ['nullable', 'string', 'max:32'],
            'widget_color_source' => ['sometimes', 'string', 'in:theme,custom'],
            'allow_image' => ['sometimes', 'boolean'],
            'allow_audio' => ['sometimes', 'boolean'],
        ]);

        $agent = AiMemberAgent::query()->firstOrCreate(
            ['product_id' => $product->id],
            ['tenant_id' => $product->tenant_id, 'name' => 'Assistente'],
        );

        $agent->fill($validated);
        $agent->tenant_id = $product->tenant_id;
        $agent->save();

        if ($agent->enabled && $agent->knowledge_chunks_count === 0) {
            IndexProductKnowledgeJob::dispatch($product->id);
        }

        return response()->json(['agent' => $this->agentToArray($agent->fresh())]);
    }

    public function reindex(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $conn = AiMemberConnection::forTenant($product->tenant_id)->first();
        if (! $conn?->isConfigured() || ! $conn->is_active) {
            return response()->json(['message' => 'Configure o OpenRouter em Integrações primeiro.'], 422);
        }

        $count = (new KnowledgeIndexer(new OpenRouterClient($product->tenant_id)))->indexProduct($product->id);

        $agent = AiMemberAgent::query()->where('product_id', $product->id)->first();

        return response()->json([
            'message' => "Indexação concluída ({$count} fontes).",
            'agent' => $agent ? $this->agentToArray($agent) : null,
        ]);
    }

    public function storeDocument(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'string', 'max:20000'],
        ]);

        $position = (int) AiMemberDocument::query()->where('product_id', $product->id)->max('position') + 1;

        $doc = AiMemberDocument::query()->create([
            'product_id' => $product->id,
            'tenant_id' => $product->tenant_id,
            'title' => $validated['title'],
            'content' => $validated['content'],
            'position' => $position,
        ]);

        IndexProductKnowledgeJob::dispatch($product->id);

        return response()->json(['document' => $doc], 201);
    }

    public function destroyDocument(Request $request, Product $product, string $documentId): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        AiMemberDocument::query()
            ->where('product_id', $product->id)
            ->where('id', $documentId)
            ->delete();

        IndexProductKnowledgeJob::dispatch($product->id);

        return response()->json(['ok' => true]);
    }

    public function uploadWidgetIcon(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $request->validate([
            'file' => ['required', 'file', 'image', 'max:2048'],
        ], [
            'file.required' => 'Nenhum arquivo enviado.',
            'file.image' => 'O arquivo deve ser uma imagem (JPG, PNG, GIF ou WebP).',
            'file.max' => 'O ícone deve ter no máximo 2 MB.',
        ]);

        $storage = app(StorageService::class);
        $path = $storage->putFile('ai-member/'.$product->id, $request->file('file'));

        $agent = AiMemberAgent::query()->firstOrCreate(
            ['product_id' => $product->id],
            ['tenant_id' => $product->tenant_id, 'name' => 'Assistente'],
        );
        $agent->widget_icon = $storage->url($path);
        $agent->tenant_id = $product->tenant_id;
        $agent->save();

        return response()->json([
            'url' => $agent->widget_icon,
            'agent' => $this->agentToArray($agent),
        ]);
    }

    public function removeWidgetIcon(Request $request, Product $product): JsonResponse
    {
        $this->authorizeProduct($request, $product);

        $agent = AiMemberAgent::query()->where('product_id', $product->id)->first();
        if ($agent) {
            $agent->widget_icon = null;
            $agent->save();
        }

        return response()->json([
            'agent' => $agent ? $this->agentToArray($agent) : null,
        ]);
    }

    private function authorizeProduct(Request $request, Product $product): void
    {
        if ((int) $product->tenant_id !== (int) $request->user()->tenant_id) {
            abort(403);
        }
    }

    private function agentToArray(AiMemberAgent $agent): array
    {
        return [
            'id' => $agent->id,
            'product_id' => $agent->product_id,
            'enabled' => (bool) $agent->enabled,
            'name' => $agent->name,
            'gender' => $agent->gender,
            'personality' => $agent->personality,
            'temperature' => (float) $agent->temperature,
            'max_tokens' => (int) $agent->max_tokens,
            'system_instructions' => $agent->system_instructions,
            'welcome_message' => $agent->welcome_message,
            'intro_headline' => $agent->intro_headline,
            'widget_icon' => $agent->widget_icon,
            'widget_color' => $agent->widget_color,
            'widget_color_source' => $agent->widget_color_source ?: 'theme',
            'allow_image' => (bool) $agent->allow_image,
            'allow_audio' => (bool) $agent->allow_audio,
            'knowledge_indexed_at' => $agent->knowledge_indexed_at?->toIso8601String(),
            'knowledge_chunks_count' => (int) $agent->knowledge_chunks_count,
        ];
    }
}
