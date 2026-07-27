<?php

namespace App\Http\Controllers;

use App\Gateways\GatewayRegistry;
use App\Gateways\CajuPay\CajuPayDriver;
use App\Models\GatewayCredential;
use App\Models\GatewayFeeSetting;
use App\Models\Setting;
use App\Services\CajuPayPixParceladoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
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
            } elseif ($type === 'select') {
                $str = $raw !== null && $raw !== '' ? (string) $raw : '';
                if ($str === '' && is_array($keyDef['options'] ?? null) && isset($keyDef['options'][0]['value'])) {
                    $str = (string) $keyDef['options'][0]['value'];
                }
                $credentialValues[$key] = $str;
            } elseif (in_array($key, ['secret_key', 'webhook_secret', 'webhook_signing_secret'], true) && $raw !== null && (string) $raw !== '') {
                $credentialValues[$key] = $this->maskCredentialSecret((string) $raw) ?? '';
            } else {
                $credentialValues[$key] = $raw !== null && $raw !== '' ? (string) $raw : '';
            }
        }

        $webhookUrl = null;
        $webhookUrlSecondary = null;
        if ($slug === 'pushinpay') {
            $webhookRoute = $gateway['webhook_route'] ?? 'webhooks.' . $slug;
            $webhookUrl = Route::has($webhookRoute) ? route($webhookRoute) : null;
        } elseif ($slug === 'cajupay' && Route::has('webhooks.cajupay')) {
            $webhookUrl = route('webhooks.cajupay');
        } elseif ($slug === 'paypal' && Route::has('webhooks.paypal')) {
            $webhookUrl = route('webhooks.paypal');
        }

        $usesOauth = ! empty($gateway['oauth']);
        $oauthRoutePrefix = 'gateways.'.$slug.'.oauth.';
        $oauthStartUrl = $usesOauth && Route::has($oauthRoutePrefix.'start')
            ? route($oauthRoutePrefix.'start')
            : null;
        $oauthDisconnectUrl = $usesOauth && Route::has($oauthRoutePrefix.'disconnect')
            ? route($oauthRoutePrefix.'disconnect')
            : null;
        $oauthCallbackUrl = $usesOauth && Route::has($oauthRoutePrefix.'callback')
            ? route($oauthRoutePrefix.'callback', [], true)
            : null;
        $oauthConnected = $usesOauth
            && ($credential?->is_connected ?? false)
            && trim((string) ($decrypted['access_token'] ?? '')) !== '';

        $oauthClientConfigured = false;

        $cajupayDriver = ($slug === 'cajupay' && ($credential?->is_connected ?? false))
            ? GatewayRegistry::driver('cajupay')
            : null;
        $cajupayWebhookMeta = $slug === 'cajupay'
            ? $this->buildCajuPayWebhookMeta(
                $decrypted,
                $credential?->is_connected ?? false,
                $cajupayDriver instanceof CajuPayDriver ? $cajupayDriver : null
            )
            : [];

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
            'webhook_url_secondary' => $webhookUrlSecondary,
            'webhook_signing_secret_set' => $slug === 'cajupay'
                && ! empty(trim((string) ($decrypted['webhook_signing_secret'] ?? ''))),
            'webhook_signing_secret_masked' => $cajupayWebhookMeta['webhook_signing_secret_masked'] ?? null,
            'webhook_auto_configured' => $cajupayWebhookMeta['webhook_auto_configured'] ?? false,
            'webhook_setup_status' => $cajupayWebhookMeta['webhook_setup_status'] ?? null,
            'webhook_rotate_url' => $slug === 'cajupay' && Route::has('gateways.cajupay.rotate-webhook')
                ? route('gateways.cajupay.rotate-webhook')
                : null,
            'spacepag_keys_configured' => $slug === 'spacepag' && (
                trim((string) ($decrypted['secret_key'] ?? '')) !== ''
                || trim((string) ($decrypted['public_key'] ?? '')) !== ''
            ),
            'spacepag_secret_key_set' => $slug === 'spacepag'
                && ! empty(trim((string) ($decrypted['secret_key'] ?? ''))),
            'webhook_secret_set' => $slug === 'spacepag'
                && ! empty(trim((string) ($decrypted['webhook_secret'] ?? ''))),
            'uses_oauth' => $usesOauth,
            'oauth_client_configured' => $oauthClientConfigured,
            'oauth_start_url' => $oauthStartUrl,
            'oauth_disconnect_url' => $oauthDisconnectUrl,
            'oauth_callback_url' => $oauthCallbackUrl,
            'oauth_connected' => $oauthConnected,
        ];

        if ($slug === 'cajupay' && ($credential?->is_connected ?? false)) {
            $parceladoService = app(\App\Services\CajuPayPixParceladoService::class);
            $creds = $decrypted;
            try {
                $payload['pix_parcelado_enrollment'] = $parceladoService->enrollmentStatus($creds);
                $payload['pix_parcelado_enrolled'] = $parceladoService->isEnrolled($creds);
            } catch (\Throwable $e) {
                $payload['pix_parcelado_enrollment'] = ['status' => 'unknown', 'message' => $e->getMessage()];
                $payload['pix_parcelado_enrolled'] = false;
            }
            $payload['pix_parcelado_accept_url'] = Route::has('gateways.cajupay.parcelado.accept')
                ? route('gateways.cajupay.parcelado.accept')
                : null;
        }

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
            if ($type === 'select') {
                $allowed = [];
                foreach ($keyDef['options'] ?? [] as $opt) {
                    if (is_array($opt) && isset($opt['value'])) {
                        $allowed[] = (string) $opt['value'];
                    } elseif (is_string($opt)) {
                        $allowed[] = $opt;
                    }
                }
                $rules[$key] = $allowed !== []
                    ? ['nullable', 'string', 'in:'.implode(',', $allowed)]
                    : ['nullable', 'string', 'max:64'];
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

        $existingCredentials = $credential->exists ? $credential->getDecryptedCredentials() : [];

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
            if ($type === 'select') {
                $trimmed = is_string($v) ? trim($v) : '';
                if ($trimmed === '' && ! empty($existingCredentials[$key])) {
                    $credentials[$key] = $existingCredentials[$key];
                } else {
                    $credentials[$key] = $trimmed !== '' ? $trimmed : (string) (($keyDef['options'][0]['value'] ?? 'auto'));
                }
                continue;
            }
            $trimmed = is_string($v) ? trim($v) : '';
            if (in_array($key, ['secret_key', 'webhook_secret', 'webhook_signing_secret'], true) && $trimmed === '' && ! empty($existingCredentials[$key])) {
                $credentials[$key] = $existingCredentials[$key];
                continue;
            }
            $credentials[$key] = $trimmed;
        }

        if ($slug === 'spacepag') {
            foreach (['public_key', 'secret_key', 'webhook_secret'] as $preserveField) {
                if (trim((string) ($credentials[$preserveField] ?? '')) === '' && ! empty($existingCredentials[$preserveField])) {
                    $credentials[$preserveField] = $existingCredentials[$preserveField];
                }
            }
            unset($credentials['base_url'], $credentials['api_key']);
            if (trim((string) ($credentials['public_key'] ?? '')) === '' && trim((string) ($credentials['secret_key'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Informe ao menos a chave pública (pk_) ou a chave privada (sk_) da Spacepag.',
                ], 422);
            }
        }

        // Preserve existing certificate_path when nenhum novo arquivo foi enviado
        if (! empty($existingCredentials['certificate_path'])) {
            $credentials['certificate_path'] = $existingCredentials['certificate_path'];
        }

        if (! empty($gateway['oauth'])) {
            foreach (['access_token', 'refresh_token', 'token_expires_at'] as $oauthKey) {
                if (! isset($credentials[$oauthKey]) || (string) ($credentials[$oauthKey] ?? '') === '') {
                    if (array_key_exists($oauthKey, $existingCredentials)) {
                        $credentials[$oauthKey] = $existingCredentials[$oauthKey];
                    }
                }
            }
        }

        if (! empty($existingCredentials['webhook_endpoint_id']) && empty($credentials['webhook_endpoint_id'] ?? null)) {
            $credentials['webhook_endpoint_id'] = $existingCredentials['webhook_endpoint_id'];
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
        if ($driver && ! empty($credentials)) {
            try {
                if ($slug === 'spacepag' && $driver instanceof \App\Gateways\Spacepag\SpacepagDriver) {
                    $authMode = $driver->detectAuthMode($credentials);
                    if ($authMode !== null) {
                        $credentials['auth_mode'] = $authMode;
                        $isConnected = true;
                    }
                } else {
                    $isConnected = $driver->testConnection($credentials);
                }
            } catch (\Throwable) {
                $isConnected = false;
            }
        }

        $webhookWarning = null;
        if ($slug === 'cajupay' && $isConnected && $driver instanceof CajuPayDriver) {
            $credentials = $this->ensureCajuPayWebhookRegistered($driver, $credentials, $webhookWarning);
            $credentials = $this->syncCajuPayParceladoMetadata($credentials, $tenantId);
        }

        $credential->is_connected = $isConnected;
        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        $cajupayMetaDriver = ($slug === 'cajupay' && $isConnected && $driver instanceof CajuPayDriver)
            ? $driver
            : null;
        $cajupayMeta = $slug === 'cajupay'
            ? $this->buildCajuPayWebhookMeta($credentials, $isConnected, $cajupayMetaDriver)
            : [];

        $message = $isConnected ? 'Credenciais salvas e conexão verificada.' : 'Credenciais salvas.';
        if ($slug === 'cajupay' && ($cajupayMeta['webhook_auto_configured'] ?? false)) {
            $message = 'Credenciais salvas. Webhook configurado automaticamente na CajuPay.';
        }

        return response()->json(array_merge([
            'success' => true,
            'is_connected' => $isConnected,
            'message' => $message,
            'webhook_warning' => $webhookWarning,
        ], $cajupayMeta));
    }

    public function test(Request $request, string $slug): JsonResponse
    {
        $gateway = GatewayRegistry::get($slug);
        if (!$gateway) {
            abort(404, 'Gateway não encontrado.');
        }

        $credentialKeys = collect($gateway['credential_keys'] ?? []);
        $certificateKey = $gateway['certificate_key'] ?? null;

        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', $slug)->first();
        $existingCredentials = $credential ? $credential->getDecryptedCredentials() : [];

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
            if (in_array($slug, ['spacepag', 'cajupay'], true) && in_array($key, ['public_key', 'secret_key'], true)) {
                $rules[$key] = ['nullable', 'string', 'max:2000'];

                continue;
            }
            $optional = ! empty($keyDef['optional']);
            $rules[$key] = $optional ? ['nullable', 'string', 'max:2000'] : ['required', 'string', 'max:2000'];
        }
        $validated = $request->validate($rules);
        $credentials = $existingCredentials;
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

        foreach (['public_key', 'secret_key', 'webhook_secret', 'webhook_signing_secret', 'webhook_endpoint_id', 'auth_mode'] as $preserveKey) {
            if (
                (! isset($credentials[$preserveKey]) || $credentials[$preserveKey] === '' || $credentials[$preserveKey] === null)
                && ! empty($existingCredentials[$preserveKey])
            ) {
                $credentials[$preserveKey] = $existingCredentials[$preserveKey];
            }
        }

        if ($slug === 'spacepag') {
            foreach (['public_key', 'secret_key', 'webhook_secret', 'auth_mode'] as $preserveField) {
                if (trim((string) ($credentials[$preserveField] ?? '')) === '' && ! empty($existingCredentials[$preserveField])) {
                    $credentials[$preserveField] = $existingCredentials[$preserveField];
                }
            }
            if (trim((string) ($credentials['public_key'] ?? '')) === '' && trim((string) ($credentials['secret_key'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Informe ao menos a chave pública (pk_) ou a chave privada (sk_) da Spacepag.',
                ], 422);
            }
        }

        if ($slug === 'cajupay') {
            foreach (['public_key', 'secret_key'] as $preserveField) {
                if (trim((string) ($credentials[$preserveField] ?? '')) === '' && ! empty($existingCredentials[$preserveField])) {
                    $credentials[$preserveField] = $existingCredentials[$preserveField];
                }
            }
            if (trim((string) ($credentials['public_key'] ?? '')) === '' || trim((string) ($credentials['secret_key'] ?? '')) === '') {
                return response()->json([
                    'success' => false,
                    'message' => 'Configure a chave pública (gpk_) e a chave secreta (gsk_) da CajuPay.',
                ], 422);
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
            $ok = false;
            if ($slug === 'spacepag' && $driver instanceof \App\Gateways\Spacepag\SpacepagDriver) {
                $authMode = $driver->detectAuthMode($credentials);
                if ($authMode !== null) {
                    $credentials['auth_mode'] = $authMode;
                    $ok = true;
                    if ($credential) {
                        $credential->setEncryptedCredentials($credentials);
                        $credential->is_connected = true;
                        $credential->save();
                    }
                }
            } else {
                $ok = $driver->testConnection($credentials);
            }

            $webhookWarning = null;
            if ($ok && $slug === 'cajupay' && $driver instanceof CajuPayDriver) {
                $credentials = $this->ensureCajuPayWebhookRegistered($driver, $credentials, $webhookWarning);
                if ($credential) {
                    $credential->setEncryptedCredentials($credentials);
                    $credential->save();
                }
            }

            $failMessage = $slug === 'spacepag'
                ? 'Falha na autenticação. Confira pk_ e sk_ no painel Spacepag (copie sem espaços) e salve de novo.'
                : 'Falha na autenticação. Verifique as credenciais.';

            $cajupayMeta = ($ok && $slug === 'cajupay')
                ? $this->buildCajuPayWebhookMeta(
                    $credentials,
                    true,
                    $driver instanceof CajuPayDriver ? $driver : null
                )
                : [];

            $testMessage = $ok ? 'Conexão realizada com sucesso.' : $failMessage;
            if ($ok && $slug === 'cajupay' && ($cajupayMeta['webhook_auto_configured'] ?? false)) {
                $testMessage = 'Conexão OK. Webhook configurado automaticamente na CajuPay.';
            }

            return response()->json(array_merge([
                'success' => $ok,
                'message' => $testMessage,
                'webhook_warning' => $webhookWarning,
            ], $cajupayMeta));
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Erro ao testar conexão.',
            ], 422);
        }
    }

    public function rotateCajuPayWebhookSecret(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', 'cajupay')->first();
        if ($credential === null) {
            return response()->json([
                'success' => false,
                'message' => 'Configure as credenciais CajuPay antes de rotacionar o token do webhook.',
            ], 422);
        }

        $credentials = $credential->getDecryptedCredentials();
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return response()->json([
                'success' => false,
                'message' => 'Driver CajuPay indisponível.',
            ], 422);
        }

        $warning = null;
        $credentials = $this->ensureCajuPayWebhookRegistered($driver, $credentials, $warning, true);

        $credential->setEncryptedCredentials($credentials);
        $credential->save();

        $meta = $this->buildCajuPayWebhookMeta($credentials, true, $driver);
        $hasSecret = ! empty(trim((string) ($credentials['webhook_signing_secret'] ?? '')));

        return response()->json(array_merge([
            'success' => $hasSecret,
            'message' => $hasSecret
                ? 'Token do webhook rotacionado e salvo.'
                : ($warning ?? 'Não foi possível obter um novo token do webhook.'),
            'webhook_warning' => $warning,
        ], $meta), $hasSecret ? 200 : 422);
    }

    public function acceptCajuPayPixParceladoEnrollment(Request $request): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $credential = GatewayCredential::forTenant($tenantId)->where('gateway_slug', 'cajupay')->where('is_connected', true)->first();
        if ($credential === null) {
            return response()->json(['success' => false, 'message' => 'Conecte a CajuPay antes de aceitar o contrato PIX Parcelado.'], 422);
        }

        $credentials = $credential->getDecryptedCredentials();
        $driver = GatewayRegistry::driver('cajupay');
        if (! $driver instanceof CajuPayDriver) {
            return response()->json(['success' => false, 'message' => 'Driver CajuPay indisponível.'], 422);
        }

        try {
            $result = $driver->acceptPixParceladoEnrollment($credentials);
            $status = strtolower(trim((string) ($result['status'] ?? 'active')));
            $credentials['pix_parcelado_enrollment_status'] = $status;
            $credentials = $this->syncCajuPayParceladoMetadata($credentials, $tenantId);
            $credential->setEncryptedCredentials($credentials);
            $credential->save();

            return response()->json([
                'success' => true,
                'message' => 'Contrato PIX Parcelado aceito.',
                'pix_parcelado_enrollment' => $result,
                'pix_parcelado_enrolled' => $status === 'active',
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Não foi possível aceitar o contrato PIX Parcelado.',
            ], 422);
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    private function syncCajuPayParceladoMetadata(array $credentials, ?int $tenantId): array
    {
        $parceladoService = app(CajuPayPixParceladoService::class);

        try {
            $enrollment = $parceladoService->enrollmentStatus($credentials);
            $status = strtolower(trim((string) ($enrollment['status'] ?? '')));
            if ($status !== '') {
                $credentials['pix_parcelado_enrollment_status'] = $status;
            }
        } catch (\Throwable) {
            // Mantém status já gravado.
        }

        $payAccountId = $parceladoService->resolvePayAccountId($credentials);
        if ($payAccountId !== null && $payAccountId !== '') {
            $credentials['pay_account_id'] = $payAccountId;
        }

        return $credentials;
    }

    /**
     * Auto-registra o endpoint de webhook na API CajuPay (idempotente) e devolve
     * credentials atualizadas com webhook_endpoint_id e webhook_signing_secret.
     *
     * @param  array<string, mixed>  $credentials
     * @param  string|null  $warning  out param
     * @return array<string, mixed>
     */
    private function ensureCajuPayWebhookRegistered(
        CajuPayDriver $driver,
        array $credentials,
        ?string &$warning,
        bool $forceRotate = false
    ): array {
        $warning = null;
        try {
            $url = route('webhooks.cajupay');
        } catch (\Throwable) {
            $warning = 'Webhook CajuPay: rota webhooks.cajupay indisponível.';

            return $credentials;
        }

        $tenantId = auth()->user()?->tenant_id;
        $description = 'Getfy'.($tenantId ? ' (tenant #'.$tenantId.')' : '');

        $hasLocalSecret = trim((string) ($credentials['webhook_signing_secret'] ?? '')) !== '';

        if ($forceRotate) {
            try {
                $reg = $driver->registerWebhookEndpoint($credentials, $url, true, $description);
                $credentials = $this->applyCajuPayWebhookRegistration($credentials, $reg);

                return $credentials;
            } catch (\Throwable $e) {
                $warning = 'Falha ao rotacionar token do webhook: '.$e->getMessage();
                Log::warning('GatewaysController: rotação webhook CajuPay falhou', [
                    'error' => $e->getMessage(),
                    'url' => $url,
                ]);

                return $credentials;
            }
        }

        try {
            $reg = $driver->registerWebhookEndpoint($credentials, $url, false, $description);
            $credentials = $this->applyCajuPayWebhookRegistration($credentials, $reg);

            if (
                empty($reg['signing_secret'])
                && ! $hasLocalSecret
                && ($reg['already_exists'] ?? false)
            ) {
                $reg = $driver->registerWebhookEndpoint($credentials, $url, true, $description);
                $credentials = $this->applyCajuPayWebhookRegistration($credentials, $reg);
            }
        } catch (\Throwable $e) {
            $warning = $this->formatCajuPayWebhookError($e->getMessage());
            Log::warning('GatewaysController: registro de webhook CajuPay falhou', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return $credentials;
        }

        if (trim((string) ($credentials['webhook_signing_secret'] ?? '')) === '') {
            $warning = 'Credenciais OK, mas o token do webhook não pôde ser obtido. Use "Rotacionar token" na configuração avançada.';
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array{endpoint_id: string, signing_secret: string|null, created?: bool, already_exists?: bool}  $reg
     * @return array<string, mixed>
     */
    private function applyCajuPayWebhookRegistration(array $credentials, array $reg): array
    {
        $credentials['webhook_endpoint_id'] = $reg['endpoint_id'];
        if (! empty($reg['signing_secret'])) {
            $credentials['webhook_signing_secret'] = $reg['signing_secret'];
        }

        return $credentials;
    }

    /**
     * @param  array<string, mixed>  $decrypted
     * @return array{webhook_signing_secret_set: bool, webhook_signing_secret_masked: string|null, webhook_auto_configured: bool, webhook_setup_status: array<string, mixed>|null}
     */
    private function buildCajuPayWebhookMeta(
        array $decrypted,
        bool $isConnected,
        ?CajuPayDriver $driver = null
    ): array {
        $secret = trim((string) ($decrypted['webhook_signing_secret'] ?? ''));
        $endpointId = trim((string) ($decrypted['webhook_endpoint_id'] ?? ''));
        $secretSet = $secret !== '';
        $autoConfigured = $secretSet && $endpointId !== '';

        $setupStatus = null;
        if ($isConnected && $driver instanceof CajuPayDriver && $secretSet) {
            $setupStatus = $driver->getWebhookSetupStatus($decrypted);
        }

        return [
            'webhook_signing_secret_set' => $secretSet,
            'webhook_signing_secret_masked' => $this->maskWebhookSigningSecret($secret),
            'webhook_auto_configured' => $autoConfigured,
            'webhook_setup_status' => $setupStatus,
        ];
    }

    private function maskWebhookSigningSecret(string $secret): ?string
    {
        return $this->maskCredentialSecret($secret);
    }

    private function maskCredentialSecret(string $secret): ?string
    {
        $secret = trim($secret);
        if ($secret === '') {
            return null;
        }

        $suffix = strlen($secret) >= 4 ? substr($secret, -4) : $secret;
        $prefix = '';
        foreach (['cwhsec_', 'gsk_', 'gpk_', 'sk_', 'pk_', 'whsec_'] as $knownPrefix) {
            if (str_starts_with($secret, $knownPrefix)) {
                $prefix = $knownPrefix;
                break;
            }
        }

        return $prefix.'••••••••••••'.$suffix;
    }

    private function formatCajuPayWebhookError(string $message): string
    {
        if (stripos($message, 'url_host_blocked_ip') !== false) {
            return 'Webhook não registrado: a URL precisa ser HTTPS pública (configure APP_URL com seu domínio real).';
        }

        return 'Webhook ainda não registrado: '.$message;
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
            'gateway_order.apple_pay' => ['nullable', 'array'],
            'gateway_order.apple_pay.*' => ['string', 'max:64'],
            'gateway_order.google_pay' => ['nullable', 'array'],
            'gateway_order.google_pay.*' => ['string', 'max:64'],
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
     * @return array{pix: array<string>, card: array<string>, boleto: array<string>, pix_auto: array<string>, apple_pay: array<string>, google_pay: array<string>}
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
        $default = config('gateways.default_order', [
            'pix' => [],
            'card' => [],
            'boleto' => [],
            'pix_auto' => [],
            'apple_pay' => [],
            'google_pay' => [],
        ]);
        if (!is_array($raw)) {
            return $default;
        }
        return [
            'pix' => $raw['pix'] ?? $default['pix'] ?? [],
            'card' => $raw['card'] ?? $default['card'] ?? [],
            'boleto' => $raw['boleto'] ?? $default['boleto'] ?? [],
            'pix_auto' => $raw['pix_auto'] ?? $default['pix_auto'] ?? [],
            'apple_pay' => $raw['apple_pay'] ?? $default['apple_pay'] ?? [],
            'google_pay' => $raw['google_pay'] ?? $default['google_pay'] ?? [],
        ];
    }

    public function fees(string $slug): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $methods = ['pix', 'card', 'boleto'];
        $fees = [];
        foreach ($methods as $method) {
            $row = \App\Models\GatewayFeeSetting::forTenant($tenantId)
                ->where('gateway_slug', $slug)
                ->where('method', $method)
                ->first();
            $defaults = GatewayFeeSetting::defaultsFor($slug, $method);
            $fees[$method] = [
                'percent' => $row ? (float) $row->percent : (float) ($defaults['percent'] ?? 0),
                'fixed_cents' => $row ? (int) $row->fixed_cents : (int) ($defaults['fixed_cents'] ?? 0),
            ];
        }

        return response()->json(['fees' => $fees]);
    }

    public function updateFees(Request $request, string $slug): JsonResponse
    {
        $tenantId = auth()->user()->tenant_id;
        $validated = $request->validate([
            'fees' => ['required', 'array'],
            'fees.pix' => ['nullable', 'array'],
            'fees.pix.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fees.pix.fixed_cents' => ['nullable', 'integer', 'min:0'],
            'fees.card' => ['nullable', 'array'],
            'fees.card.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fees.card.fixed_cents' => ['nullable', 'integer', 'min:0'],
            'fees.boleto' => ['nullable', 'array'],
            'fees.boleto.percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fees.boleto.fixed_cents' => ['nullable', 'integer', 'min:0'],
        ]);

        foreach (['pix', 'card', 'boleto'] as $method) {
            $cfg = $validated['fees'][$method] ?? null;
            if (! is_array($cfg)) {
                continue;
            }
            \App\Models\GatewayFeeSetting::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'gateway_slug' => $slug,
                    'method' => $method,
                ],
                [
                    'percent' => $cfg['percent'] ?? 0,
                    'fixed_cents' => $cfg['fixed_cents'] ?? 0,
                ]
            );
        }

        return response()->json(['success' => true]);
    }
}
