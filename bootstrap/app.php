<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Artisan;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*', headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX | Request::HEADER_X_FORWARDED_AWS_ELB);

        // Redirect de convidados para /login (evita RouteNotFoundException quando route('login') não está disponível, ex.: cache de rotas)
        $middleware->redirectGuestsTo(fn () => url('/login'));

        // Webhooks recebem POST de gateways externos sem CSRF token
        $middleware->validateCsrfTokens(except: [
            'webhooks/gateways/*',
        ]);

        $middleware->web(prepend: [
            \App\Http\Middleware\ForceHttpsWhenForwardedProto::class,
            \App\Http\Middleware\EnsureDockerSetup::class,
            \App\Http\Middleware\EnsureInstalled::class,
        ], append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\PreventCacheForHtml::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\RunScheduleFallback::class,
        ]);
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureRole::class,
            'team.permission' => \App\Http\Middleware\EnsureTeamPermission::class,
            'audit.log' => \App\Http\Middleware\AuditLogMiddleware::class,
            'guest' => \App\Http\Middleware\EnsureGuest::class,
            'api.application' => \App\Http\Middleware\AuthenticateApiApplication::class,
            'member.area.resolve' => \App\Http\Middleware\ResolveMemberAreaProduct::class,
            'member.area.resolve.by.host' => \App\Http\Middleware\ResolveMemberAreaByHost::class,
            'member.area.access' => \App\Http\Middleware\EnsureMemberAreaAccess::class,
            'admin.tenant' => \App\Http\Middleware\EnsureAdminHasTenant::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->header('X-Inertia')) {
                return redirect()->to('/login')->with('error', 'Sessão expirada. Tente fazer login novamente.');
            }
            return null;
        });

        // Fallback: se der erro por tabela/view inexistente e APP_AUTO_MIGRATE=true, roda migrate e redireciona para tentar de novo
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->expectsJson() && filter_var(config('getfy.auto_migrate', false), FILTER_VALIDATE_BOOLEAN)) {
                $message = $e->getMessage();
                $isTableMissing = $e instanceof QueryException
                    || str_contains($message, '42S02')
                    || str_contains($message, 'Base table or view not found')
                    || str_contains($message, "doesn't exist");
                $previous = $e->getPrevious();
                if (! $isTableMissing && $previous instanceof \Throwable) {
                    $message = $previous->getMessage();
                    $isTableMissing = str_contains($message, '42S02')
                        || str_contains($message, 'Base table or view not found')
                        || str_contains($message, "doesn't exist");
                }
                if ($isTableMissing) {
                    try {
                        Artisan::call('migrate', ['--force' => true]);
                        $url = $request->fullUrl();
                        if ($request->header('X-Inertia')) {
                            return redirect()->to($url)->with('success', 'Migrações executadas automaticamente. Página recarregada.');
                        }
                        return redirect()->to($url)->with('success', 'Migrações executadas automaticamente. Recarregue a página se necessário.');
                    } catch (\Throwable $migrateEx) {
                        report($migrateEx);
                    }
                }
            }
            return null;
        });
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->job(new \App\Jobs\SendSubscriptionRemindersJob)->dailyAt('09:00');
        $schedule->command('checkout:fire-abandoned-cart-webhooks')->everyTenMinutes();
        $schedule->command('checkout:send-cart-recovery-emails')->everyMinute();
        $schedule->command('email-campaign:process')->everyMinute();
        $schedule->command('payments:reconcile-pending --limit=200 --days=45')->everyTwoMinutes();
        $schedule->command('schedule:heartbeat')->everyMinute();
        $schedule->job(new \App\Jobs\QueueHeartbeatJob)->everyMinute();
    })
    ->create();
