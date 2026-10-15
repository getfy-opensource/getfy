<?php

namespace App\Http\Controllers\Webhooks;

use App\Gateways\GatewayRegistry;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessPaymentWebhook;
use App\Jobs\SyncPagarmeChargePayables;
use App\Models\GatewayCredential;
use App\Models\Order;
use App\Models\PagarmeAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PagarmeWebhookController extends Controller
{
    private const SLUG = 'pagarme';

    /**
     * POST /webhooks/gateways/pagarme — payload com "type" e "data" (API v5).
     *
     * @see https://docs.pagar.me/reference/exemplo-de-webhook-1
     */
    public function handle(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $payload = $request->all();

        $chargeId = $this->resolveChargeId($payload);
        if ($chargeId === null || $chargeId === '') {
            return response()->json(['received' => true]);
        }

        $order = Order::where('gateway', self::SLUG)->where('gateway_id', $chargeId)->first();
        if (! $order) {
            Log::debug('PagarmeWebhook: order not found', ['gateway_id' => $chargeId]);

            return response()->json(['received' => true]);
        }

        $credential = GatewayCredential::forTenant($order->tenant_id)
            ->where('gateway_slug', self::SLUG)
            ->where('is_connected', true)
            ->first();

        if ($credential && ! $this->verifyHubSignature($raw, $request->header('X-Hub-Signature'), $credential)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        $type = (string) ($payload['type'] ?? '');

        if (str_contains($type, 'refunded')) {
            ProcessPaymentWebhook::dispatchSync(self::SLUG, $chargeId, 'order.refunded', 'refunded', is_array($payload) ? $payload : []);
            $this->schedulePayablesSync($order);

            return response()->json(['received' => true]);
        }

        $event = 'order.pending';
        $status = 'pending';

        if ($credential) {
            $credentials = $credential->getDecryptedCredentials();
            $driver = GatewayRegistry::driver(self::SLUG);
            if (! empty($credentials) && $driver) {
                $apiStatus = $driver->getTransactionStatus($chargeId, $credentials);
                if ($apiStatus === 'paid') {
                    $event = 'order.paid';
                    $status = 'paid';
                } elseif ($apiStatus === 'cancelled') {
                    $event = 'order.cancelled';
                    $status = 'cancelled';
                }
            }
        }

        if (str_contains($type, 'payment_failed')) {
            $event = 'order.rejected';
            $status = 'rejected';
        }

        ProcessPaymentWebhook::dispatchSync(self::SLUG, $chargeId, $event, $status, is_array($payload) ? $payload : []);
        if ($status === 'paid') {
            $this->schedulePayablesSync($order);
        }

        return response()->json(['received' => true]);
    }

    /**
     * Taxa real: lê os recebíveis da cobrança na API (a Pagar.me cria os payables logo após o pagamento).
     */
    private function schedulePayablesSync(Order $order): void
    {
        if (! PagarmeAccount::forTenant((int) $order->tenant_id)) {
            return;
        }

        SyncPagarmeChargePayables::dispatch($order->id)->delay(now()->addMinutes(2));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveChargeId(array $payload): ?string
    {
        $type = (string) ($payload['type'] ?? '');
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return null;
        }

        $id = $data['id'] ?? null;
        if (is_string($id) && str_starts_with($id, 'ch_')) {
            return $id;
        }

        if (str_starts_with($type, 'charge.') && is_string($id) && $id !== '') {
            return $id;
        }

        $charges = $data['charges'] ?? null;
        if (is_array($charges) && $charges !== []) {
            $first = $charges[0];
            if (is_array($first)) {
                $cid = $first['id'] ?? null;
                if (is_string($cid) && str_starts_with($cid, 'ch_')) {
                    return $cid;
                }
            }
        }

        return null;
    }

    /**
     * HMAC-SHA1 do corpo bruto com a Secret Key (comportamento legado documentado para postbacks Pagar.me).
     */
    private function verifyHubSignature(string $rawBody, mixed $header, GatewayCredential $credential): bool
    {
        if (! is_string($header) || trim($header) === '') {
            return true;
        }

        $credentials = $credential->getDecryptedCredentials();
        $secret = trim((string) ($credentials['secret_key'] ?? ''));
        if ($secret === '') {
            return true;
        }

        $expectedHex = hash_hmac('sha1', $rawBody, $secret, false);
        $sig = trim($header);
        if (preg_match('/^sha1=(.+)$/i', $sig, $m)) {
            $sig = trim($m[1]);
        }

        if (hash_equals(strtolower($expectedHex), strtolower($sig))) {
            return true;
        }

        Log::warning('PagarmeWebhook: invalid X-Hub-Signature');

        return false;
    }
}
