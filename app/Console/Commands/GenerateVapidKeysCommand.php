<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeysCommand extends Command
{
    protected $signature = 'pwa:vapid';

    protected $description = 'Gera chaves VAPID para PWA (notificações push) e atualiza o .env';

    public function handle(): int
    {
        $envPath = base_path('.env');
        if (! file_exists($envPath)) {
            $this->error('Arquivo .env não encontrado.');
            return self::FAILURE;
        }

        try {
            $keys = VAPID::createVapidKeys();
        } catch (\Throwable $e) {
            $this->error('Falha ao gerar chaves: ' . $e->getMessage());
            return self::FAILURE;
        }

        $publicKey = $keys['publicKey'];
        $privateKey = $keys['privateKey'];

        try {
            VAPID::validate([
                'subject' => 'mailto:validate@example.invalid',
                'publicKey' => $publicKey,
                'privateKey' => $privateKey,
            ]);
        } catch (\Throwable $e) {
            $this->error('Chaves geradas falharam na validação interna: ' . $e->getMessage());

            return self::FAILURE;
        }

        $content = file_get_contents($envPath);
        $hasPublic = preg_match('/^PWA_VAPID_PUBLIC=/m', $content);
        $hasPrivate = preg_match('/^PWA_VAPID_PRIVATE=/m', $content);

        $publicEscaped = '"' . str_replace('"', '\\"', $publicKey) . '"';
        $privateEscaped = '"' . str_replace('"', '\\"', $privateKey) . '"';

        if ($hasPublic) {
            $content = preg_replace('/^PWA_VAPID_PUBLIC=.*/m', 'PWA_VAPID_PUBLIC=' . $publicEscaped, $content);
        } else {
            $content .= "\n# PWA Painel: chaves VAPID (geradas via php artisan pwa:vapid)\n";
            $content .= 'PWA_VAPID_PUBLIC=' . $publicEscaped . "\n";
        }
        if ($hasPrivate) {
            $content = preg_replace('/^PWA_VAPID_PRIVATE=.*/m', 'PWA_VAPID_PRIVATE=' . $privateEscaped, $content);
        } else {
            $content .= 'PWA_VAPID_PRIVATE=' . $privateEscaped . "\n";
        }

        file_put_contents($envPath, $content);

        $this->info('Chaves VAPID geradas e salvas no .env.');
        $this->line('');
        $this->line('PWA_VAPID_PUBLIC=' . $publicKey);
        $this->line('PWA_VAPID_PRIVATE=' . str_repeat('*', min(strlen($privateKey), 20)) . '...');
        $this->line('');
        $this->comment('Reinicie o servidor ou rode "php artisan config:clear" para carregar as novas variáveis.');

        return self::SUCCESS;
    }
}
