<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Models\GatewayCredential;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

class GatewaysController extends Controller
{
    public function index(): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $gateways = $this->buildGatewaysList($tenantId);
        $gatewayOrder = $this->getGatewayOrder($tenantId);

        return response()->json([
            'gateways' => $gateways,
            'gateway_order' => $gatewayOrder,
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        if ($credential !== null) {
            $credential = $credential->fresh();
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $credentialValues = [];
        $certificateConfigured = false;
        $certificateFilename = null;
        $decrypted = [];
        if ($credential !== null && $credential->getRawOriginal('credentials') !== null && $credential->getRawOriginal('credentials') !== '') {
            $decrypted = $credential->getDecryptedCredentials();
            if ($decrypted === [] && (string) $credential->getRawOriginal('credentials') !== '') {
                \Log::warning('GatewaysController::show decryption returned empty', [
                    'slug' => $slug,
                    'tenant_id' => $tenantId,
                ]);
            }
            $certificateConfigured = !empty($decrypted['certificate_path'] ?? '');
            $certificateFilename = $decrypted['certificate_filename'] ?? null;
        }
        foreach ($credentialKeys as $keyDef) {
            $keyDef = is_array($keyDef) ? $keyDef : (array) $keyDef;
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '') {
                continue;
            }
            if ($type === 'file') {
                continue;
            }
            $raw = $decrypted[$key] ?? null;
            if ($type === 'boolean') {
                $credentialValues[$key] = filter_var($raw, FILTER_VALIDATE_BOOLEAN);
            } else {
                $credentialValues[$key] = $raw !== null && $raw !== '' ? (string) $raw : '';
            }
        }

        $webhookUrl = null;
        if ($slug === 'pushinpay') {
            $webhookRoute = $gateway['webhook_route'] ?? 'webhooks.' . $slug;
            $webhookUrl = Route::has($webhookRoute) ? route($webhookRoute) : null;
        }

        $payload = [
            'slug' => $gateway['slug'],
            'name' => $gateway['name'],
            'image' => $gateway['image'] ?? null,
            'methods' => $gateway['methods'] ?? [],
            'scope' => $gateway['scope'] ?? 'national',
            'signup_url' => $gateway['signup_url'] ?? null,
            'credential_keys' => $gateway['credential_keys'] ?? [],
            'certificate_key' => $gateway['certificate_key'] ?? null,
            'is_configured' => $credential !== null,
            'is_connected' => $credential?->is_connected ?? false,
            'credential_values' => $credentialValues,
            'certificate_configured' => $certificateConfigured,
            'certificate_filename' => $certificateFilename && is_string($certificateFilename) ? $certificateFilename : null,
            'webhook_url' => $webhookUrl,
        ];

        return response()->json($payload)->header('Cache-Control', 'no-store, no-cache, must-revalidate');
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $certificateKey = $gateway['certificate_key'] ?? null;

        $rules = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '') {
                continue;
            }
            if ($type === 'file') {
                $rules[$key] = ['nullable', 'file', 'max:512'];
                continue;
            }
            if ($type === 'boolean') {
                $rules[$key] = ['nullable', 'boolean'];
                continue;
            }
            // Tokens (ex.: Mercado Pago Access Token) podem ultrapassar 500 caracteres
            $rules[$key] = ['nullable', 'string', 'max:2000'];
        }

        $validated = $request->validate($rules);

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->firstOrNew(
            ['gateway_slug' => $slug],
            ['tenant_id' => $tenantId]
        );

        $credentials = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            $v = array_key_exists($key, $validated) ? $validated[$key] : $request->input($key);
            if ($type === 'boolean') {
                $credentials[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }
            $credentials[$key] = is_string($v) ? trim($v) : '';
        }

        // Preserve existing certificate_path when nenhum novo arquivo foi enviado
        if ($credential->exists) {
            $existingCredentials = $credential->getDecryptedCredentials();
            if (!empty($existingCredentials['certificate_path'])) {
                $credentials['certificate_path'] = $existingCredentials['certificate_path'];
            }
        }

        if ($certificateKey && $request->hasFile($certificateKey)) {
            $file = $request->file($certificateKey);
            if ($file->isValid() && strtolower($file->getClientOriginalExtension()) === 'p12') {
                $path = $file->storeAs(
                    'gateway_certs/' . ($tenantId ?? 'global'),
                    $slug . '.p12',
                    'local'
                );
                $absolutePath = Storage::path($path);
                $credentials['certificate_path'] = $absolutePath;
                $credentials['certificate_filename'] = $file->getClientOriginalName();
            }
        }

        $driver = GatewayRegistry::driver($slug);
        $isConnected = false;
        if ($driver && !empty($credentials)) {
            try {
                $isConnected = $driver->testConnection($credentials);
            } catch (\Throwable) {
                $isConnected = false;
            }
        }

        $credential->is_connected = $isConnected;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        return response()->json([
            'success' => true,
            'is_connected' => $isConnected,
            'message' => $isConnected ? 'Credenciais salvas e conexão verificada.' : 'Credenciais salvas.',
        ]);
    }

    public function test(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $certificateKey = $gateway['certificate_key'] ?? null;

        $rules = [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            if ($type === 'boolean') {
                $rules[$key] = ['nullable', 'boolean'];
                continue;
            }
            $rules[$key] = ['required', 'string', 'max:2000'];
        }
        $validated = $request->validate($rules);

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        $credentials = $credential ? $credential->getDecryptedCredentials() : [];
        foreach ($credentialKeys as $keyDef) {
            $key = $keyDef['key'] ?? '';
            $type = $keyDef['type'] ?? 'text';
            if ($key === '' || $key === $certificateKey) {
                continue;
            }
            $v = $validated[$key] ?? null;
            if ($type === 'boolean') {
                $credentials[$key] = filter_var($v, FILTER_VALIDATE_BOOLEAN);
                continue;
            }
            if (is_string($v)) {
                $credentials[$key] = trim($v);
            }
        }

        if ($certificateKey && empty($credentials['certificate_path'])) {
            return response()->json([
                'success' => false,
                'message' => 'Envie e salve o certificado P12 antes de testar a conexão.',
            ], 422);
        }

        $driver = GatewayRegistry::driver($slug);
        if (!$driver) {
            return response()->json(['success' => false, 'message' => 'Driver do gateway não disponível.'], 422);
        }

        try {
            $ok = $driver->testConnection($credentials);
            return response()->json([
                'success' => $ok,
                'message' => $ok ? 'Conexão realizada com sucesso.' : 'Falha na autenticação. Verifique as credenciais.',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Erro ao testar conexão.',
            ], 422);
        }
    }

    public function updateCertificate(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $certificateKey = $gateway['certificate_key'] ?? null;
        if (!$certificateKey) {
            return response()->json([
                'success' => false,
                'message' => 'Este gateway não utiliza certificado.',
            ], 422);
        }

        $rules = [
            $certificateKey => ['required', 'file', 'max:4096'],
        ];
        $request->validate($rules);

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->firstOrNew(
            ['gateway_slug' => $slug],
            ['tenant_id' => $tenantId]
        );

        $credentials = $credential->exists ? $credential->getDecryptedCredentials() : [];

        if (! $request->hasFile($certificateKey)) {
            return response()->json([
                'success' => false,
                'message' => 'Nenhum arquivo recebido. Envie o certificado .p12 novamente.',
            ], 422);
        }

        /** @var \Illuminate\Http\UploadedFile|null $file */
        $file = $request->file($certificateKey);
        if ($file && $file->isValid() && strtolower($file->getClientOriginalExtension()) === 'p12') {
            $path = $file->storeAs(
                'gateway_certs/' . ($tenantId ?? 'global'),
                $slug . '.p12',
                'local'
            );
            $absolutePath = Storage::path($path);
            $credentials['certificate_path'] = $absolutePath;
            $credentials['certificate_filename'] = $file->getClientOriginalName();
            \Log::info('GatewaysController::updateCertificate saved certificate', [
                'slug' => $slug,
                'tenant_id' => $tenantId,
                'path' => $absolutePath,
                'keys' => array_keys($credentials),
            ]);
        } elseif ($file && $file->isValid()) {
            return response()->json([
                'success' => false,
                'message' => 'O certificado deve ser um arquivo .p12.',
            ], 422);
        }

        $driver = GatewayRegistry::driver($slug);
        $isConnected = $credential->is_connected ?? false;
        if ($driver && !empty($credentials)) {
            try {
                $isConnected = $driver->testConnection($credentials);
            } catch (\Throwable) {
                $isConnected = false;
            }
        }

        $credential->is_connected = $isConnected;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        return response()->json([
            'success' => true,
            'is_connected' => $isConnected,
            'message' => $isConnected ? 'Certificado salvo e conexão verificada.' : 'Certificado salvo.',
        ]);
    }

    public function updateOrder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'gateway_order' => ['required', 'array'],
            'gateway_order.pix' => ['nullable', 'array'],
            'gateway_order.pix.*' => ['string', 'max:64'],
            'gateway_order.card' => ['nullable', 'array'],
            'gateway_order.card.*' => ['string', 'max:64'],
            'gateway_order.boleto' => ['nullable', 'array'],
            'gateway_order.boleto.*' => ['string', 'max:64'],
            'gateway_order.pix_auto' => ['nullable', 'array'],
            'gateway_order.pix_auto.*' => ['string', 'max:64'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        Setting::set('gateway_order', $validated['gateway_order'], $tenantId);

        return response()->json(['success' => true, 'message' => 'Ordem de redundância atualizada.']);
    }

    /**
     * Build gateways list with is_configured and is_connected for tenant.
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildGatewaysList(?int $tenantId): array
    {
        $all = GatewayRegistry::all();
        $credentialBySlug = GatewayCredential::forTenant($tenantId)
            ->get()
            ->keyBy('gateway_slug');

        return array_map(function ($g) use ($credentialBySlug) {
            $cred = $credentialBySlug->get($g['slug'] ?? '');
            $image = $g['image'] ?? null;
            return [
                'slug' => $g['slug'],
                'name' => $g['name'],
                'image' => GatewayRegistry::resolveImageUrl(is_string($image) ? $image : null),
                'methods' => $g['methods'] ?? [],
                'scope' => $g['scope'] ?? 'national',
                'signup_url' => $g['signup_url'] ?? null,
                'is_configured' => $cred !== null,
                'is_connected' => $cred?->is_connected ?? false,
            ];
        }, $all);
    }

    /**
     * @return array{pix: array<string>, card: array<string>, boleto: array<string>, pix_auto: array<string>}
     */
    private function getGatewayOrder(?int $tenantId): array
    {
        $raw = Setting::get('gateway_order', null, $tenantId);
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
        $default = config('gateways.default_order', ['pix' => [], 'card' => [], 'boleto' => [], 'pix_auto' => []]);
        if (!is_array($raw)) {
            return $default;
        }
        return [
            'pix' => $raw['pix'] ?? $default['pix'] ?? [],
            'card' => $raw['card'] ?? $default['card'] ?? [],
            'boleto' => $raw['boleto'] ?? $default['boleto'] ?? [],
            'pix_auto' => $raw['pix_auto'] ?? $default['pix_auto'] ?? [],
        ];
    }
}
