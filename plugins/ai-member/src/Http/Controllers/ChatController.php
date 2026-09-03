<?php

namespace Plugins\AiMember\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\AiMember\Services\ChatService;
use RuntimeException;

class ChatController extends Controller
{
    public function __construct(
        private readonly ChatService $chatService,
    ) {}

    public function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'conversation_id' => ['nullable', 'uuid'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $product = Product::query()->findOrFail($validated['product_id']);
        if (! $product->hasMemberAreaAccess($user)) {
            return response()->json(['message' => 'Sem acesso.'], 403);
        }

        return response()->json($this->chatService->getHistory(
            $product,
            $user,
            $validated['conversation_id'] ?? null,
        ));
    }

    public function conversations(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $product = Product::query()->findOrFail($validated['product_id']);
        if (! $product->hasMemberAreaAccess($user)) {
            return response()->json(['message' => 'Sem acesso.'], 403);
        }

        return response()->json([
            'conversations' => $this->chatService->listConversations($product, $user),
        ]);
    }

    public function startNew(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $product = Product::query()->findOrFail($validated['product_id']);

        try {
            return response()->json($this->chatService->startNewConversation($product, $user));
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', 'uuid'],
            'message' => ['nullable', 'string', 'max:8000'],
            'image_base64' => ['nullable', 'string', 'max:7000000'],
            'image_mime' => ['nullable', 'string', 'max:64'],
            'audio_base64' => ['nullable', 'string', 'max:14000000'],
            'audio_mime' => ['nullable', 'string', 'max:64'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $message = trim((string) ($validated['message'] ?? ''));
        $attachments = array_filter([
            'image_base64' => $validated['image_base64'] ?? null,
            'image_mime' => $validated['image_mime'] ?? null,
            'audio_base64' => $validated['audio_base64'] ?? null,
            'audio_mime' => $validated['audio_mime'] ?? null,
        ]);

        if ($message === '' && $attachments === []) {
            return response()->json(['message' => 'Envie uma mensagem, imagem ou áudio.'], 422);
        }

        $product = Product::query()->findOrFail($validated['product_id']);

        try {
            $result = $this->chatService->sendMessage($product, $user, $message, $attachments);

            return response()->json($result);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
