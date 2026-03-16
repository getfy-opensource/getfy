<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\CheckoutTranslations;
use App\Support\DockerSetupState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(): Response
    {
        $tenantId = auth()->user()->tenant_id;
        $defaultTranslations = config('checkout_translations');
        $checkoutTranslationsRaw = Setting::get('checkout_translations', null, $tenantId);
        $savedTranslations = $checkoutTranslationsRaw
            ? (is_string($checkoutTranslationsRaw) ? json_decode($checkoutTranslationsRaw, true) : $checkoutTranslationsRaw)
            : [];
        $checkoutTranslations = CheckoutTranslations::merge($defaultTranslations, is_array($savedTranslations) ? $savedTranslations : []);

        $currenciesRaw = Setting::get('currencies', null, $tenantId);
        $currencies = $currenciesRaw
            ? (is_string($currenciesRaw) ? json_decode($currenciesRaw, true) : $currenciesRaw)
            : config('products.currencies');
        if (! is_array($currencies)) {
            $currencies = config('products.currencies');
        }

        $gitAvailable = is_dir(base_path('.git'));
        $cloudMode = (bool) config('getfy.cloud_mode', false);
        $dockerMode = DockerSetupState::isDocker();
        $cronSecret = config('getfy.cron_secret');
        $appUrl = rtrim(config('app.url'), '/');
        $cronUrl = $cronSecret ? $appUrl . '/cron?token=' . urlencode((string) $cronSecret) : null;
        $versionFile = base_path('VERSION');
        $currentVersion = trim((is_file($versionFile) ? (string) file_get_contents($versionFile) : '') ?: '');
        if ($currentVersion === '') {
            $currentVersion = (string) config('getfy.version');
        }

        return Inertia::render('Settings/Index', [
            'current_version' => $currentVersion,
            'updates_enabled' => config('getfy.updates_enabled', true),
            'git_available' => $gitAvailable,
            'cloud_mode' => $cloudMode,
            'docker_mode' => $dockerMode,
            'app_url' => $appUrl,
            'base_path' => base_path(),
            'cron_url' => $cronUrl,
            'settings' => [
                'email_provider' => Setting::get('email_provider', 'smtp', $tenantId),
                'smtp_host' => Setting::get('smtp_host', '', $tenantId),
                'smtp_port' => Setting::get('smtp_port', '587', $tenantId),
                'smtp_username' => Setting::get('smtp_username', '', $tenantId),
                'smtp_encryption' => Setting::get('smtp_encryption', 'tls', $tenantId),
                // do NOT expose smtp_password to the frontend
                'mail_from_address' => Setting::get('mail_from_address', config('mail.from.address'), $tenantId),
                'mail_from_name' => Setting::get('mail_from_name', config('mail.from.name'), $tenantId),
                'reply_to' => Setting::get('reply_to', '', $tenantId),
                // Hostinger: configuração separada (host/porta/criptografia fixos no código)
                'hostinger_smtp_username' => Setting::get('hostinger_smtp_username', '', $tenantId),
                'hostinger_mail_from_address' => Setting::get('hostinger_mail_from_address', '', $tenantId),
                'hostinger_mail_from_name' => Setting::get('hostinger_mail_from_name', '', $tenantId),
                'hostinger_reply_to' => Setting::get('hostinger_reply_to', '', $tenantId),
                // SendGrid: do NOT expose sendgrid_api_key to the frontend
                'sendgrid_mail_from_address' => Setting::get('sendgrid_mail_from_address', config('mail.from.address', ''), $tenantId),
                'sendgrid_mail_from_name' => Setting::get('sendgrid_mail_from_name', config('mail.from.name', ''), $tenantId),
                'checkout_translations' => $checkoutTranslations,
                'currencies' => $currencies,
                'storage_provider' => Setting::get('storage_provider', 'local', $tenantId),
                'storage_s3_key' => Setting::get('storage_s3_key', '', $tenantId),
                'storage_s3_bucket' => Setting::get('storage_s3_bucket', '', $tenantId),
                'storage_s3_region' => Setting::get('storage_s3_region', 'us-east-1', $tenantId),
                'storage_s3_endpoint' => Setting::get('storage_s3_endpoint', '', $tenantId),
                'storage_s3_url' => Setting::get('storage_s3_url', '', $tenantId),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'email_provider' => ['nullable', 'string', 'in:smtp,hostinger,sendgrid'],
            'smtp_password' => ['nullable', 'string', 'max:255'],
            'mail_from_address' => ['nullable', 'email', 'max:255'],
            'mail_from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email', 'max:255'],
            'smtp_host' => ['nullable', 'string', 'max:255'],
            'smtp_port' => ['nullable', 'string', 'max:10'],
            'smtp_username' => ['nullable', 'string', 'max:255'],
            'smtp_encryption' => ['nullable', 'string', 'in:tls,ssl'],
            'hostinger_smtp_password' => ['nullable', 'string', 'max:255'],
            'hostinger_smtp_username' => ['nullable', 'string', 'max:255'],
            'hostinger_mail_from_address' => ['nullable', 'email', 'max:255'],
            'hostinger_mail_from_name' => ['nullable', 'string', 'max:255'],
            'hostinger_reply_to' => ['nullable', 'email', 'max:255'],
            'sendgrid_api_key' => ['nullable', 'string', 'max:512'],
            'sendgrid_mail_from_address' => ['nullable', 'email', 'max:255'],
            'sendgrid_mail_from_name' => ['nullable', 'string', 'max:255'],
            'checkout_translations' => ['nullable', 'array'],
            'checkout_translations.pt_BR' => ['nullable', 'array'],
            'checkout_translations.en' => ['nullable', 'array'],
            'checkout_translations.es' => ['nullable', 'array'],
            'currencies' => ['nullable', 'array'],
            'currencies.*.code' => ['required', 'string', 'max:10'],
            'currencies.*.symbol' => ['required', 'string', 'max:10'],
            'currencies.*.label' => ['required', 'string', 'max:100'],
            'currencies.*.rate_to_brl' => ['required', 'numeric', 'min:0'],
            'storage_provider' => ['nullable', 'string', 'in:local,s3,wasabi,r2'],
            'storage_s3_key' => ['nullable', 'string', 'max:255'],
            'storage_s3_secret' => ['nullable', 'string', 'max:512'],
            'storage_s3_bucket' => ['nullable', 'string', 'max:255'],
            'storage_s3_region' => ['nullable', 'string', 'max:64'],
            'storage_s3_endpoint' => ['nullable', 'string', 'max:512'],
            'storage_s3_url' => ['nullable', 'string', 'max:512'],
        ]);

        $tenantId = auth()->user()->tenant_id;
        $emailKeys = [
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
            'mail_from_address', 'mail_from_name', 'reply_to',
            'hostinger_smtp_username', 'hostinger_mail_from_address', 'hostinger_mail_from_name', 'hostinger_reply_to',
            'sendgrid_mail_from_address', 'sendgrid_mail_from_name',
        ];
        $alwaysSetKeys = ['email_provider'];
        $brandingKeys = ['theme_primary', 'app_name', 'app_logo', 'app_logo_dark', 'app_logo_icon', 'app_logo_icon_dark'];
        // Handle passwords separately (encrypt)
        if (array_key_exists('smtp_password', $validated) && $validated['smtp_password'] !== null && $validated['smtp_password'] !== '') {
            Setting::set('smtp_password', encrypt($validated['smtp_password']), $tenantId);
        }
        if (array_key_exists('hostinger_smtp_password', $validated) && $validated['hostinger_smtp_password'] !== null && $validated['hostinger_smtp_password'] !== '') {
            Setting::set('hostinger_smtp_password', encrypt($validated['hostinger_smtp_password']), $tenantId);
        }
        if (array_key_exists('sendgrid_api_key', $validated) && $validated['sendgrid_api_key'] !== null && $validated['sendgrid_api_key'] !== '') {
            Setting::set('sendgrid_api_key', encrypt($validated['sendgrid_api_key']), $tenantId);
        }
        if (array_key_exists('storage_s3_secret', $validated) && $validated['storage_s3_secret'] !== null && $validated['storage_s3_secret'] !== '') {
            Setting::set('storage_s3_secret', Crypt::encryptString($validated['storage_s3_secret']), $tenantId);
        }

        $storageKeys = [
            'storage_provider', 'storage_s3_key', 'storage_s3_bucket', 'storage_s3_region',
            'storage_s3_endpoint', 'storage_s3_url',
        ];

        foreach ($validated as $key => $value) {
            if (in_array($key, ['smtp_password', 'hostinger_smtp_password', 'sendgrid_api_key', 'storage_s3_secret'], true)) {
                continue;
            }
            if (in_array($key, $brandingKeys, true)) {
                continue; // branding hardcoded in config/getfy.php - never save
            }

            if (in_array($key, $alwaysSetKeys, true) || in_array($key, $emailKeys, true)) {
                Setting::set($key, $value ?? '', $tenantId);
            } elseif (in_array($key, $storageKeys, true)) {
                Setting::set($key, $value ?? '', $tenantId);
            } elseif ($key === 'checkout_translations' || $key === 'currencies') {
                if (is_array($value) && ! empty($value)) {
                    Setting::set($key, $value, $tenantId);
                }
            } elseif ($value !== null && $value !== '') {
                Setting::set($key, $value, $tenantId);
            }
        }

        return back()->with('success', 'Configurações salvas.');
    }
}
