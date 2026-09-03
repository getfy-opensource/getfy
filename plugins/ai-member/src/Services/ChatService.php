<?php

namespace Plugins\AiMember\Services;

use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;
use Plugins\AiMember\Models\AiMemberAgent;
use Plugins\AiMember\Models\AiMemberConnection;
use Plugins\AiMember\Models\AiMemberConversation;
use Plugins\AiMember\Models\AiMemberMessage;
use RuntimeException;

class ChatService
{
    public function __construct(
        private readonly LlmOrchestrator $orchestrator,
        private readonly OpenRouterClient $client,
    ) {}

    public function isAvailableForProduct(Product $product): bool
    {
        $conn = AiMemberConnection::forTenant($product->tenant_id)->first();
        if (! $conn?->isConfigured() || ! $conn->is_active) {
            return false;
        }

        $agent = AiMemberAgent::query()->where('product_id', $product->id)->first();

        return $agent?->enabled ?? false;
    }

    /**
     * @return array{conversation_id: string, archived: bool, messages: array<int, array<string, mixed>>}
     */
    public function getHistory(Product $product, User $user, ?string $conversationId = null): array
    {
        $conversation = $this->findConversation($product, $user, $conversationId);

        if (! $conversation) {
            return ['conversation_id' => '', 'archived' => false, 'messages' => []];
        }

        return [
            'conversation_id' => $conversation->id,
            'archived' => $conversation->isArchived(),
            'messages' => $this->formatMessages($conversation),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listConversations(Product $product, User $user): array
    {
        return AiMemberConversation::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->withCount('messages')
            ->orderByDesc('last_message_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AiMemberConversation $c) => [
                'id' => $c->id,
                'title' => $c->title ?: 'Conversa',
                'archived' => $c->isArchived(),
                'archived_at' => $c->archived_at?->toIso8601String(),
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'messages_count' => (int) $c->messages_count,
            ])
            ->all();
    }

    /**
     * @return array{conversation_id: string}
     */
    public function startNewConversation(Product $product, User $user): array
    {
        if (! $product->hasMemberAreaAccess($user)) {
            throw new RuntimeException('Sem acesso à área de membros.');
        }

        AiMemberConversation::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->update(['archived_at' => now()]);

        $conversation = AiMemberConversation::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);

        return ['conversation_id' => $conversation->id];
    }

    /**
     * @param  array{image_base64?: string, image_mime?: string, audio_base64?: string, audio_mime?: string}  $attachments
     * @return array{message: array<string, mixed>, reply: array<string, mixed>, conversation_id: string}
     */
    public function sendMessage(
        Product $product,
        User $user,
        string $message,
        array $attachments = [],
    ): array {
        if (! $product->hasMemberAreaAccess($user)) {
            throw new RuntimeException('Sem acesso à área de membros.');
        }

        if (! $this->isAvailableForProduct($product)) {
            throw new RuntimeException('Assistente IA indisponível para este produto.');
        }

        $agent = AiMemberAgent::query()->where('product_id', $product->id)->firstOrFail();

        if (! empty($attachments['image_base64']) && ! $agent->allow_image) {
            throw new RuntimeException('Envio de imagens desabilitado para este agente.');
        }

        if (! empty($attachments['audio_base64']) && ! $agent->allow_audio) {
            throw new RuntimeException('Envio de áudio desabilitado para este agente.');
        }

        $conversation = $this->getOrCreateActiveConversation($product, $user);

        if ($conversation->isArchived()) {
            throw new RuntimeException('Esta conversa está arquivada. Inicie uma nova conversa.');
        }

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->limit(20)
            ->get()
            ->map(fn (AiMemberMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        $isFirstMessage = $history === [];

        $userMsg = AiMemberMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $message,
            'attachments' => $this->sanitizeAttachmentsMeta($attachments),
        ]);

        if (! $conversation->title && trim($message) !== '') {
            $conversation->title = Str::limit(trim($message), 80);
        }

        $result = $this->orchestrator->respond(
            $agent,
            $product,
            $message,
            $history,
            $attachments,
            $user->name,
            $isFirstMessage,
        );

        $assistantMsg = AiMemberMessage::query()->create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $result['content'],
            'model_used' => $result['model'],
            'tokens_in' => $result['tokens_in'],
            'tokens_out' => $result['tokens_out'],
            'intent' => $result['intent'],
        ]);

        $conversation->last_message_at = now();
        $conversation->save();

        return [
            'conversation_id' => $conversation->id,
            'message' => [
                'id' => $userMsg->id,
                'role' => 'user',
                'content' => $userMsg->content,
                'created_at' => $userMsg->created_at?->toIso8601String(),
            ],
            'reply' => [
                'id' => $assistantMsg->id,
                'role' => 'assistant',
                'content' => $assistantMsg->content,
                'intent' => $assistantMsg->intent,
                'created_at' => $assistantMsg->created_at?->toIso8601String(),
            ],
        ];
    }

    private function findConversation(Product $product, User $user, ?string $conversationId): ?AiMemberConversation
    {
        $query = AiMemberConversation::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id);

        if ($conversationId) {
            return $query->where('id', $conversationId)->first();
        }

        return $query->whereNull('archived_at')->latest('last_message_at')->first();
    }

    private function getOrCreateActiveConversation(Product $product, User $user): AiMemberConversation
    {
        $conversation = AiMemberConversation::query()
            ->where('product_id', $product->id)
            ->where('user_id', $user->id)
            ->whereNull('archived_at')
            ->latest('last_message_at')
            ->first();

        if ($conversation) {
            return $conversation;
        }

        return AiMemberConversation::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'last_message_at' => now(),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(AiMemberConversation $conversation): array
    {
        return $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn (AiMemberMessage $m) => [
                'id' => $m->id,
                'role' => $m->role,
                'content' => $m->content,
                'attachments' => $m->attachments,
                'created_at' => $m->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $attachments
     * @return array<string, mixed>|null
     */
    private function sanitizeAttachmentsMeta(array $attachments): ?array
    {
        $meta = [];
        if (! empty($attachments['image_base64'])) {
            $meta['has_image'] = true;
            $meta['image_mime'] = $attachments['image_mime'] ?? 'image/jpeg';
        }
        if (! empty($attachments['audio_base64'])) {
            $meta['has_audio'] = true;
            $meta['audio_mime'] = $attachments['audio_mime'] ?? 'audio/webm';
        }

        return $meta === [] ? null : $meta;
    }
}
