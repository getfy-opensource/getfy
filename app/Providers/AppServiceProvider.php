<?php

namespace App\Providers;

use App\Events\BoletoGenerated;
use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Listeners\SendAccessEmailOnOrderCompleted;
use App\Listeners\SendPanelPushOnBoletoGenerated;
use App\Listeners\SendPanelPushOnOrderCompleted;
use App\Listeners\SendPanelPushOnPixGenerated;
use App\Listeners\SpedyEventSubscriber;
use App\Listeners\UtmifyEventSubscriber;
use App\Listeners\SendApiApplicationWebhookListener;
use App\Listeners\WebhookEventSubscriber;
use App\Support\DockerSetupState;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureRuntimeDirectories();
        $this->fallbackRedisToDatabase();
        $this->fallbackInvalidQueueConnectionToSync();
        if (DockerSetupState::isDocker() && class_exists(\Illuminate\Support\Facades\Vite::class)) {
            \Illuminate\Support\Facades\Vite::useHotFile(storage_path('framework/vite.hot'));
        }

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        Queue::after(function (): void {
            Cache::put('queue_heartbeat', now()->toIso8601String(), now()->addMinutes(5));
        });

        Event::listen(OrderCompleted::class, SendAccessEmailOnOrderCompleted::class);
        Event::listen(OrderCompleted::class, SendPanelPushOnOrderCompleted::class);
        Event::listen(PixGenerated::class, SendPanelPushOnPixGenerated::class);
        Event::listen(BoletoGenerated::class, SendPanelPushOnBoletoGenerated::class);
        Event::subscribe(WebhookEventSubscriber::class);
        Event::subscribe(SendApiApplicationWebhookListener::class);
        Event::subscribe(UtmifyEventSubscriber::class);
        Event::subscribe(SpedyEventSubscriber::class);

        ResetPassword::toMailUsing(function (object $notifiable, string $token) {
            $params = [
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ];
            $redirect = app()->bound('password_reset_redirect') ? app('password_reset_redirect') : null;
            if ($redirect !== null) {
                $params['redirect'] = $redirect;
            }
            $url = url(route('password.reset', $params, false));
            $expire = config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

            return (new MailMessage)
                ->markdown('notifications::email', ['logoUrl' => 'https://cdn.getfy.cloud/logo-white.png'])
                ->subject('Redefinição de senha')
                ->greeting('Olá!')
                ->line('Você está recebendo este e-mail porque recebemos uma solicitação de redefinição de senha da sua conta.')
                ->action('Redefinir senha', $url)
                ->line('Este link expira em '.$expire.' minutos.')
                ->line('Se você não solicitou a redefinição de senha, nenhuma ação é necessária.');
        });
    }

    private function ensureRuntimeDirectories(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $paths = [
            storage_path('framework/cache/data'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            base_path('bootstrap/cache'),
        ];

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                @mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Se Redis estiver configurado mas indisponível, usa database para cache, sessão e fila.
     */
    private function fallbackRedisToDatabase(): void
    {
        $usesRedis = config('cache.default') === 'redis'
            || config('session.driver') === 'redis'
            || config('queue.default') === 'redis';

        if (! $usesRedis) {
            return;
        }

        try {
            Redis::connection()->ping();
        } catch (\Throwable $e) {
            if (config('cache.default') === 'redis') {
                config(['cache.default' => 'database']);
            }
            if (config('session.driver') === 'redis') {
                config(['session.driver' => 'database']);
            }
            if (config('queue.default') === 'redis') {
                config(['queue.default' => 'database']);
            }
        }
    }

    private function fallbackInvalidQueueConnectionToSync(): void
    {
        $default = (string) config('queue.default', 'sync');
        $connections = config('queue.connections', []);
        if (! is_array($connections) || $connections === []) {
            config(['queue.default' => 'sync']);
            return;
        }
        if (! array_key_exists($default, $connections)) {
            config(['queue.default' => 'sync']);
        }
    }
}
