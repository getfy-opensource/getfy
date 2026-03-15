<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiApplication;
use App\Models\ApiCheckoutSession;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CheckoutSessionsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $app = $request->attributes->get('api_application');
        if (! $app instanceof ApiApplication) {
            abort(500, 'API application not resolved');
        }

        $validated = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'customer.cpf' => ['nullable', 'string', 'max:14'],
            'customer.phone' => ['nullable', 'string', 'max:24'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'product_id' => ['nullable', 'string', 'exists:products,id'],
            'product_offer_id' => ['nullable', 'integer', 'exists:product_offers,id'],
            'subscription_plan_id' => ['nullable', 'integer', 'exists:subscription_plans,id'],
            'metadata' => ['nullable', 'array'],
            'return_url' => ['nullable', 'string', 'url', 'max:512'],
            'expires_in' => ['nullable', 'integer', 'min:5', 'max:1440'],
        ]);

        $tenantId = $app->tenant_id;
        if (! empty($validated['product_id'])) {
            $product = Product::where('id', $validated['product_id'])->where('tenant_id', $tenantId)->first();
            if (! $product) {
                return response()->json(['message' => 'Produto não encontrado.'], 422);
            }
        }

        $expiresIn = (int) ($validated['expires_in'] ?? 30);
        $expiresAt = now()->addMinutes($expiresIn);
        $sessionToken = Str::uuid()->toString();

        $session = ApiCheckoutSession::create([
            'api_application_id' => $app->id,
            'tenant_id' => $tenantId,
            'session_token' => $sessionToken,
            'customer' => [
                'email' => $validated['customer']['email'],
                'name' => $validated['customer']['name'] ?? $validated['customer']['email'],
                'cpf' => $validated['customer']['cpf'] ?? null,
                'phone' => $validated['customer']['phone'] ?? null,
            ],
            'amount' => (float) $validated['amount'],
            'currency' => strtoupper((string) ($validated['currency'] ?? 'BRL')),
            'product_id' => $validated['product_id'] ?? null,
            'product_offer_id' => $validated['product_offer_id'] ?? null,
            'subscription_plan_id' => $validated['subscription_plan_id'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
            'return_url' => $validated['return_url'] ?? ($app->default_return_url ?: null),
            'expires_at' => $expiresAt,
        ]);

        $checkoutUrl = url('/api-checkout/' . $sessionToken);

        return response()->json([
            'session_id' => (string) $session->id,
            'checkout_url' => $checkoutUrl,
            'expires_at' => $expiresAt->toIso8601String(),
        ], 201);
    }
}
