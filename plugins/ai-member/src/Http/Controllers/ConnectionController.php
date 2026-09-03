<?php

namespace Plugins\AiMember\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\AiMember\Models\AiMemberConnection;
use Plugins\AiMember\Services\OpenRouterClient;
use RuntimeException;

class ConnectionController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;
        $connection = AiMemberConnection::forTenant($tenantId)->first();

        return response()->json([
            'connection' => $this->toArray($connection),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:512'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $tenantId = (int) $request->user()->tenant_id;
        $connection = AiMemberConnection::forTenant($tenantId)->first() ?? new AiMemberConnection([
            'tenant_id' => $tenantId,
        ]);

        if (array_key_exists('is_active', $validated)) {
            $connection->is_active = (bool) $validated['is_active'];
        }

        if ($request->has('api_key')) {
            $key = trim((string) ($validated['api_key'] ?? ''));
            if ($key !== '') {
                $connection->api_key = $key;
            }
        }

        $connection->save();

        return response()->json([
            'connection' => $this->toArray($connection->fresh()),
        ]);
    }

    public function test(Request $request): JsonResponse
    {
        $tenantId = (int) $request->user()->tenant_id;

        try {
            (new OpenRouterClient($tenantId))->testConnection();

            return response()->json(['success' => true, 'message' => 'Conexão OK.']);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    private function toArray(?AiMemberConnection $connection): array
    {
        return [
            'configured' => (bool) $connection?->isConfigured(),
            'is_active' => (bool) ($connection?->is_active ?? false),
            'has_token' => (bool) $connection?->isConfigured(),
            'last_tested_at' => $connection?->last_tested_at?->toIso8601String(),
            'last_error' => $connection?->last_error,
        ];
    }
}
