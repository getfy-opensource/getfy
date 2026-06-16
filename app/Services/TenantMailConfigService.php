<?php

namespace App\Services;

use App\Models\Setting;

class TenantMailConfigService
{
    /**
     * Whether the tenant has at least one email provider configured (SMTP, Hostinger or SendGrid).
     */
    public function isEmailConfigured(?int $tenantId): bool
    {
        $smtpHost = Setting::get('smtp_host', '', $tenantId);
        if ($smtpHost !== null && $smtpHost !== '') {
            return true;
        }
        $hostingerUser = Setting::get('hostinger_smtp_username', '', $tenantId);
        if ($hostingerUser !== null && $hostingerUser !== '') {
            $encrypted = Setting::get('hostinger_smtp_password', null, $tenantId);
            $password = $encrypted ? @decrypt($encrypted) : null;
            if ($password !== null && $password !== '') {
                return true;
            }
        }
        $sendgridEncrypted = Setting::get('sendgrid_api_key', null, $tenantId);
        if ($sendgridEncrypted) {
            $key = @decrypt($sendgridEncrypted);
            if ($key !== null && $key !== '') {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{host: string, port: int, encryption: ?string, username: ?string, password: ?string}
     */
    public function getMailConfigForProvider(?int $tenantId, array $overrides, string $provider): array
    {
        if ($provider === 'sendgrid') {
            $password = $overrides['sendgrid_api_key'] ?? null;
            if ($password === null) {
                $encrypted = Setting::get('sendgrid_api_key', null, $tenantId);
                $password = $encrypted ? @decrypt($encrypted) : null;
            }
            return [
                'host' => 'smtp.sendgrid.net',
                'port' => 587,
                'encryption' => 'tls',
                'username' => 'apikey',
                'password' => $password,
            ];
        }
        if ($provider === 'hostinger') {
            $host = 'smtp.hostinger.com';
            $port = 465;
            $encryption = 'ssl';
            $username = $overrides['smtp_username'] ?? Setting::get('hostinger_smtp_username', '', $tenantId);
            $password = $overrides['smtp_password'] ?? null;
            if ($password === null) {
                $encrypted = Setting::get('hostinger_smtp_password', null, $tenantId);
                $password = $encrypted ? @decrypt($encrypted) : null;
            }
            return ['host' => $host, 'port' => $port, 'encryption' => $encryption, 'username' => $username, 'password' => $password];
        }
        $host = $overrides['smtp_host'] ?? Setting::get('smtp_host', config('mail.mailers.smtp.host'), $tenantId);
        $port = (int) ($overrides['smtp_port'] ?? Setting::get('smtp_port', config('mail.mailers.smtp.port'), $tenantId));
        $encryption = $overrides['smtp_encryption'] ?? Setting::get('smtp_encryption', config('mail.mailers.smtp.encryption'), $tenantId);
        if ($encryption === '' || $encryption === null) {
            $encryption = null;
        } elseif (! in_array($encryption, ['tls', 'ssl'], true)) {
            $encryption = 'tls';
        }
        $username = $overrides['smtp_username'] ?? Setting::get('smtp_username', config('mail.mailers.smtp.username'), $tenantId);
        $password = $overrides['smtp_password'] ?? null;
        if ($password === null) {
            $encrypted = Setting::get('smtp_password', null, $tenantId);
            $password = $encrypted ? @decrypt($encrypted) : null;
        }
        return ['host' => $host, 'port' => $port, 'encryption' => $encryption, 'username' => $username, 'password' => $password];
    }

    /**
     * Return the email provider name for the tenant (smtp, hostinger, sendgrid).
     * Uses resolveTenantIdForMail so it matches the tenant used when applying config.
     */
    public function getProviderForTenant(?int $tenantId): string
    {
        $resolved = $this->resolveTenantIdForMail($tenantId);

        return (string) Setting::get('email_provider', 'smtp', $resolved);
    }

    /**
     * Quando não há usuário logado (ex.: esqueci a senha), as configs de SMTP foram salvas
     * com o tenant_id do infoprodutor. Retorna o primeiro tenant_id que tem smtp_host
     * configurado, ou null para usar fallback do .env.
     */
    public function resolveTenantIdForMail(?int $tenantId): ?int
    {
        if ($tenantId !== null) {
            return $tenantId;
        }
        $row = Setting::query()
            ->where('key', 'smtp_host')
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('tenant_id')
            ->first();
        if ($row !== null) {
            return $row->tenant_id;
        }
        $row = Setting::query()
            ->whereIn('key', ['hostinger_smtp_username', 'sendgrid_api_key'])
            ->whereNotNull('value')
            ->where('value', '!=', '')
            ->orderBy('tenant_id')
            ->first();
        return $row?->tenant_id;
    }

    public function applyMailerConfigForTenant(?int $tenantId, array $overrides = [], ?string $provider = null): void
    {
        $tenantId = $this->resolveTenantIdForMail($tenantId);
        $provider = $provider ?? Setting::get('email_provider', 'smtp', $tenantId);
        $config = $this->getMailConfigForProvider($tenantId, $overrides, $provider);

        config(['mail.mailers.smtp.transport' => 'smtp']);
        config(['mail.mailers.smtp.host' => $config['host']]);
        config(['mail.mailers.smtp.port' => $config['port']]);
        config(['mail.mailers.smtp.username' => $config['username']]);
        config(['mail.mailers.smtp.encryption' => $config['encryption']]);
        config(['mail.mailers.smtp.password' => $config['password']]);

        $fromAddress = $config['username'] ?: config('mail.from.address');
        if ($provider === 'sendgrid') {
            $fromAddress = $overrides['sendgrid_mail_from_address'] ?? Setting::get('sendgrid_mail_from_address', config('mail.from.address'), $tenantId);
            $fromName = $overrides['sendgrid_mail_from_name'] ?? Setting::get('sendgrid_mail_from_name', config('mail.from.name'), $tenantId);
            $replyTo = null;
        } elseif ($provider === 'hostinger') {
            $hostingerFrom = Setting::get('hostinger_mail_from_address', '', $tenantId);
            if ($hostingerFrom !== null && $hostingerFrom !== '') {
                $fromAddress = $hostingerFrom;
            }
            $fromName = Setting::get('hostinger_mail_from_name', config('mail.from.name'), $tenantId);
            $replyTo = Setting::get('hostinger_reply_to', null, $tenantId);
        } else {
            $smtpFrom = $overrides['mail_from_address'] ?? Setting::get('mail_from_address', '', $tenantId);
            if ($smtpFrom !== null && $smtpFrom !== '') {
                $fromAddress = $smtpFrom;
            }
            $fromName = $overrides['mail_from_name'] ?? Setting::get('mail_from_name', config('mail.from.name'), $tenantId);
            $replyTo = Setting::get('reply_to', null, $tenantId);
        }

        config(['mail.from' => [
            'address' => $fromAddress ?: config('mail.from.address'),
            'name' => $fromName ?: config('mail.from.name', 'Getfy'),
        ]]);
        if ($replyTo) {
            config(['mail.reply_to' => ['address' => $replyTo, 'name' => null]]);
        }
    }
}
