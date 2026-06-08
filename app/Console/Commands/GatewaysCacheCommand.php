<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Cache de configurações de gateways autorizados.
 * Executa: php artisan gateways:cache
 * Limpa: php artisan gateways:cache-clear
 */
class GatewaysCacheCommand extends Command
{
    protected $signature = 'gateways:cache';
    protected $description = 'Cache das configurações de gateways autorizados (CajuPay e Asaas)';

    public function handle()
    {
        $basePath = base_path();
        $cacheDir = $basePath . '/bootstrap/cache';
        
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $cacheFile = $cacheDir . '/gateways.php';
        
        $config = [
            'allowed_gateways' => [
                'cajupay',
                'asaas',
            ],
            'blocked_gateways' => [
                'spacepag',
                'sapcepag',
                'efi',
                'stripe',
                'mercadopago',
                'pushinpay',
                'pagarme',
            ],
            'gateway_configs' => [
                'cajupay' => [
                    'signup_url' => 'https://cajupay.com.br/registro?ref=596d6c91fe',
                ],
                'asaas' => [
                    'signup_url' => 'https://www.asaas.com/r/2617ea23-f001-4a8e-8413-2eb1a5f5145c',
                ],
            ],
            'cache_version' => '1.0',
            'cached_at' => time(),
        ];
        
        $phpCode = "<?php\n\nreturn " . var_export($config, true) . ";\n";
        
        if (file_put_contents($cacheFile, $phpCode)) {
            $this->info('✅ Cache de gateways criado em: ' . $cacheFile);
            return Command::SUCCESS;
        }
        
        $this->error('❌ Falha ao criar cache de gateways');
        return Command::FAILURE;
    }
}
