<?php

use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Support\BrandFavicon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

// Storage: servir arquivos de storage/app/public (sem symlink) — deve ser uma das primeiras rotas
Route::get('/storage/{path}', \App\Http\Controllers\StorageServeController::class)
    ->where('path', '.+')
    ->name('storage.serve');

// Instalador: fallback quando o servidor envia /install para o Laravel (ex: document root diferente de public/)
Route::any('/install', [\App\Http\Controllers\InstallServeController::class, '__invoke'])->defaults('path', null);
Route::any('/install/{path}', [\App\Http\Controllers\InstallServeController::class, '__invoke'])->where('path', '.+');

Route::get('/docker-setup', [\App\Http\Controllers\DockerSetupController::class, 'show'])->name('docker-setup');
Route::post('/docker-setup', [\App\Http\Controllers\DockerSetupController::class, 'store'])->middleware('throttle:10,1');

// Favicon da plataforma (sempre via Laravel — funciona mesmo se o docroot não for public/)
Route::get('/brand/favicon.png', fn () => BrandFavicon::serve())->name('brand.favicon');

Route::get('/favicon.ico', fn () => BrandFavicon::serve());

// PWA Painel: manifest e service worker
Route::get('/manifest.json', [\App\Http\Controllers\PanelPwaController::class, 'manifest'])->name('panel.pwa.manifest');
Route::get('/painel-sw.js', function () {
    $path = public_path('painel-sw.js');
    if (! file_exists($path)) {
        abort(404);
    }

    return response()->file($path, [
        'Content-Type' => 'application/javascript; charset=UTF-8',
        // Evita cache agressivo do SW (senão o browser não busca a versão nova e mantém o SW antigo ativo).
        'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        'Pragma' => 'no-cache',
        'Expires' => '0',
    ]);
})->name('panel.pwa.sw');

Route::get('/', function (\Illuminate\Http\Request $request) {
    $resolved = app(\App\Services\MemberAreaResolver::class)->resolve($request);
    if ($resolved && in_array($resolved['access_type'], ['subdomain', 'custom'], true)) {
        $request->attributes->set('member_area_product', $resolved['product']);
        $request->attributes->set('member_area_access_type', $resolved['access_type']);
        $request->attributes->set('member_area_slug', $resolved['slug']);

        if (! $request->user()) {
            return redirect()->to('/login')->with('error', 'Faça login para acessar a área de membros.');
        }

        if (! $resolved['product']->hasMemberAreaAccess($request->user())) {
            return redirect()->route('checkout.show', ['slug' => $resolved['product']->checkout_slug])
                ->with('error', 'Você não tem acesso a esta área. Adquira o produto para continuar.');
        }

        return app()->call(\App\Http\Controllers\MemberAreaAppController::class.'@show', [
            'request' => $request,
            'slug' => $resolved['slug'],
        ]);
    }

    if (auth()->check()) {
        $user = auth()->user();
        if ($user->canAccessPanel()) {
            if ($user->isPartner()) {
                return redirect('/parceiro');
            }

            return redirect('/dashboard');
        }

        return redirect('/meus-produtos');
    }

    return redirect()->to('/login', 302);
});

Route::get('/cron', function () {
    $secret = config('getfy.cron_secret');
    $token = request()->query('token');
    if (! $secret || $token !== $secret) {
        abort(404);
    }
    \Illuminate\Support\Facades\Artisan::call('schedule:run');

    return response()->json(['ok' => true, 'message' => 'Schedule executed']);
})->middleware('throttle:60,1')->name('cron.url');

Route::middleware('throttle:60,1')->group(function () {
    Route::post('/webhooks/gateways/spacepag', [\App\Http\Controllers\Webhooks\SpacepagWebhookController::class, 'handle'])->name('webhooks.spacepag');
    Route::post('/webhooks/gateways/stripe', [\App\Http\Controllers\Webhooks\StripeWebhookController::class, 'handle'])->name('webhooks.stripe');
    Route::post('/webhooks/gateways/efi/pix', [\App\Http\Controllers\Webhooks\EfiWebhookController::class, 'pix'])->name('webhooks.efi.pix');
    Route::post('/webhooks/gateways/efi/pix-recorrente', [\App\Http\Controllers\Webhooks\EfiWebhookController::class, 'pixRecorrente'])->name('webhooks.efi.pix-recorrente');
    Route::post('/webhooks/gateways/efi/notification', [\App\Http\Controllers\Webhooks\EfiWebhookController::class, 'notification'])->name('webhooks.efi.notification');
    Route::post('/webhooks/gateways/mercadopago', [\App\Http\Controllers\Webhooks\MercadoPagoWebhookController::class, 'handle'])->name('webhooks.mercadopago');
    Route::post('/webhooks/gateways/pushinpay', [\App\Http\Controllers\Webhooks\PushinPayWebhookController::class, 'handle'])->name('webhooks.pushinpay');
    Route::post('/webhooks/gateways/asaas', [\App\Http\Controllers\Webhooks\AsaasWebhookController::class, 'handle'])->name('webhooks.asaas');
    Route::post('/webhooks/gateways/pagarme', [\App\Http\Controllers\Webhooks\PagarmeWebhookController::class, 'handle'])->name('webhooks.pagarme');
    Route::post('/webhooks/gateways/cajupay', [\App\Http\Controllers\Webhooks\CajuPayWebhookController::class, 'handle'])->name('webhooks.cajupay');
    Route::post('/webhooks/inbound/{token}', [\App\Http\Controllers\Webhooks\InboundCheckoutWebhookController::class, 'handle'])
        ->where('token', '[a-fA-F0-9]{64}')
        ->name('webhook-entrada.inbound.post');
    // Dispatcher genérico para gateways de plugins (webhook_handler na definição do gateway)
    Route::post('/webhooks/gateways/{slug}', \App\Http\Controllers\Webhooks\GenericGatewayWebhookController::class)
        ->where('slug', '[a-z0-9_-]+')
        ->name('webhooks.gateway');
});

// Verificação pública de autenticidade do dossiê (resumo mascarado).
Route::get('/verify/{code}', [\App\Http\Controllers\PublicProofVerifyController::class, 'show'])
    ->where('code', '[0-9A-Za-z]{6,32}')
    ->middleware('throttle:30,1')
    ->name('public.proof.verify');

// Assets de plugins (imagens, etc.): GET /plugins/{slug}/assets/{path} — arquivos em plugins/{slug}/assets/
Route::get('/plugins/{slug}/assets/{path}', \App\Http\Controllers\PluginAssetController::class)
    ->where('path', '.+')
    ->name('plugins.asset');

Route::get('/renovar/{token}', [\App\Http\Controllers\RenewalController::class, 'show'])->name('renewal.show')->where('token', '[a-zA-Z0-9]{32,64}');
Route::post('/renovar', [\App\Http\Controllers\RenewalController::class, 'process'])
    ->name('renewal.process')
    ->middleware(['checkout.reuse-pix', 'throttle:checkout-process', 'throttle:checkout-pix', 'throttle:checkout-card', 'checkout.abuse']);

// Checkout Pro (API): página hospedada – dados do cliente na sessão
Route::get('/api-checkout/{token}', [\App\Http\Controllers\ApiCheckoutController::class, 'show'])->name('api-checkout.show')->where('token', '[a-zA-Z0-9\-]{36,64}');
Route::post('/api-checkout/pay', [\App\Http\Controllers\ApiCheckoutController::class, 'process'])
    ->name('api-checkout.process')
    ->middleware(['checkout.reuse-pix', 'throttle:checkout-process', 'throttle:checkout-pix', 'throttle:checkout-card', 'throttle:checkout-email', 'throttle:checkout-product-ip', 'checkout.abuse']);
Route::post('/api-checkout/cajupay/session', [\App\Http\Controllers\ApiCheckoutController::class, 'cajupaySession'])->name('api-checkout.cajupay.session');
Route::post('/api-checkout/cajupay/confirm-order', [\App\Http\Controllers\ApiCheckoutController::class, 'cajupayConfirmOrder'])->name('api-checkout.cajupay.confirm-order');
Route::get('/api-checkout/order-status', [\App\Http\Controllers\ApiCheckoutController::class, 'orderStatus'])->name('api-checkout.order-status');
Route::get('/api-checkout/card-confirm', [\App\Http\Controllers\ApiCheckoutController::class, 'cardConfirm'])->name('api-checkout.card-confirm');
Route::get('/api-checkout/obrigado', [\App\Http\Controllers\ApiCheckoutController::class, 'thankYou'])->name('api-checkout.thank-you');

// Commerce (loja / carrinho multi-produto — core para plugins de vitrine)
Route::middleware(['web', 'throttle:120,1', \App\Http\Middleware\ResolveStorefrontTenant::class, 'plugin.commerce.scope'])
    ->prefix('commerce')
    ->group(function () {
        Route::get('/catalog/products', [\App\Http\Controllers\Commerce\CommerceCatalogController::class, 'products'])->name('commerce.catalog.products');
        Route::get('/catalog/products/{idOrSlug}', [\App\Http\Controllers\Commerce\CommerceCatalogController::class, 'product'])->name('commerce.catalog.product');
        Route::get('/cart', [\App\Http\Controllers\Commerce\CommerceCartController::class, 'show'])->name('commerce.cart.show');
        Route::post('/cart/lines', [\App\Http\Controllers\Commerce\CommerceCartController::class, 'addLine'])->name('commerce.cart.lines.add');
        Route::patch('/cart/lines/{lineId}', [\App\Http\Controllers\Commerce\CommerceCartController::class, 'updateLine'])->name('commerce.cart.lines.update');
        Route::delete('/cart/lines/{lineId}', [\App\Http\Controllers\Commerce\CommerceCartController::class, 'removeLine'])->name('commerce.cart.lines.remove');
        Route::delete('/cart', [\App\Http\Controllers\Commerce\CommerceCartController::class, 'clear'])->name('commerce.cart.clear');
        Route::post('/checkout/start', [\App\Http\Controllers\Commerce\CommerceCartController::class, 'startCheckout'])->name('commerce.checkout.start');
    });

Route::get('/commerce/checkout/{token}', [\App\Http\Controllers\Commerce\CommerceCheckoutController::class, 'show'])
    ->name('commerce.checkout.show')
    ->middleware(['plugin.commerce.scope'])
    ->where('token', '[a-zA-Z0-9]{32,64}');
Route::post('/commerce/checkout/pay', [\App\Http\Controllers\Commerce\CommerceCheckoutController::class, 'process'])
    ->name('commerce.checkout.process')
    ->middleware(['plugin.commerce.scope', 'checkout.reuse-pix', 'throttle:checkout-process', 'throttle:checkout-pix', 'throttle:checkout-card', 'checkout.abuse']);

Route::get('/c/{slug}', [\App\Http\Controllers\CheckoutController::class, 'show'])
    ->name('checkout.show')
    ->where('slug', '[a-z0-9]{6,16}')
    ->middleware('throttle:checkout-show');
Route::get('/checkout/pix', [\App\Http\Controllers\CheckoutController::class, 'pixPage'])->name('checkout.pix');
Route::get('/checkout/boleto', [\App\Http\Controllers\CheckoutController::class, 'boletoPage'])->name('checkout.boleto');
Route::get('/checkout/order-status', [\App\Http\Controllers\CheckoutController::class, 'orderStatus'])->name('checkout.order-status')->middleware('throttle:30,1');

// Página genérica de retorno para gateways que exigem return_url (ex.: Stripe 3DS)
// quando o tenant não definiu um default_return_url próprio.
Route::get('/payments/return/{order}', [\App\Http\Controllers\PaymentReturnController::class, 'show'])
    ->name('payments.return')
    ->where('order', '[0-9]+')
    ->middleware('throttle:60,1');
Route::post('/checkout', [\App\Http\Controllers\CheckoutController::class, 'process'])
    ->name('checkout.process')
    ->middleware(['checkout.reuse-pix', 'throttle:checkout-process', 'throttle:checkout-pix', 'throttle:checkout-card', 'throttle:checkout-email', 'throttle:checkout-product-ip', 'checkout.abuse']);
Route::post('/checkout/cajupay/session', [\App\Http\Controllers\CheckoutController::class, 'cajupaySession'])
    ->name('checkout.cajupay.session')
    ->middleware(['throttle:checkout-process', 'checkout.abuse']);
Route::post('/checkout/cajupay/confirm-order', [\App\Http\Controllers\CheckoutController::class, 'cajupayConfirmOrder'])
    ->name('checkout.cajupay.confirm-order')
    ->middleware(['throttle:checkout-process', 'throttle:checkout-card', 'throttle:checkout-email', 'throttle:checkout-product-ip', 'checkout.abuse']);
Route::post('/checkout/cajupay/parcelado/session', [\App\Http\Controllers\CheckoutController::class, 'cajupayParceladoSession'])
    ->name('checkout.cajupay.parcelado.session')
    ->middleware(['throttle:checkout-process', 'checkout.abuse']);
Route::post('/checkout/cajupay/parcelado/confirm-order', [\App\Http\Controllers\CheckoutController::class, 'cajupayParceladoConfirmOrder'])
    ->name('checkout.cajupay.parcelado.confirm-order')
    ->middleware(['throttle:checkout-process', 'throttle:checkout-email', 'throttle:checkout-product-ip', 'checkout.abuse']);
Route::post('/checkout/cajupay/parcelado/complete', [\App\Http\Controllers\CheckoutController::class, 'cajupayParceladoComplete'])
    ->name('checkout.cajupay.parcelado.complete')
    ->middleware(['throttle:checkout-process', 'checkout.abuse']);
Route::get('/checkout/pix-parcelado', [\App\Http\Controllers\CheckoutController::class, 'pixParceladoPage'])->name('checkout.pix-parcelado');
// Mesmo handler que webhooks.cajupay — URL alternativa usada em docs/curl da CajuPay
Route::post('/checkout/cajupay/webhook', [\App\Http\Controllers\Webhooks\CajuPayWebhookController::class, 'handle'])
    ->name('webhooks.cajupay.checkout-alias')
    ->middleware('throttle:60,1');
// Pagar.me tokenizecard: se o submit HTML não for cancelado, evita POST na rota GET /c/{slug} (405).
Route::post('/checkout/pagarme-tokenize-sink', fn () => response()->noContent())
    ->name('checkout.pagarme-tokenize-sink')
    ->middleware('throttle:120,1');
Route::post('/api/checkout/track', [\App\Http\Controllers\CheckoutTrackingController::class, 'track'])->name('checkout.track')->middleware('throttle:60,1');
Route::post('/api/checkout/track-field', [\App\Http\Controllers\CheckoutTrackingController::class, 'trackField'])->name('checkout.track-field')->middleware('throttle:120,1');
Route::post('/api/checkout/track-country', [\App\Http\Controllers\CheckoutTrackingController::class, 'trackCountry'])->name('checkout.track-country')->middleware('throttle:120,1');
Route::post('/checkout/validate-coupon', [\App\Http\Controllers\CheckoutController::class, 'validateCoupon'])->name('checkout.validate-coupon')->middleware('throttle:30,1');

Route::get('/checkout/upsell', [\App\Http\Controllers\UpsellController::class, 'upsellPage'])->name('checkout.upsell');
Route::get('/checkout/downsell', [\App\Http\Controllers\UpsellController::class, 'downsellPage'])->name('checkout.downsell');
Route::get('/checkout/obrigado', [\App\Http\Controllers\UpsellController::class, 'thankYouPage'])->name('checkout.thank-you');
Route::post('/checkout/upsell/accept', [\App\Http\Controllers\UpsellController::class, 'acceptUpsell'])
    ->name('checkout.upsell.accept')
    ->middleware(['checkout.reuse-pix', 'throttle:checkout-process', 'throttle:checkout-pix', 'throttle:checkout-card', 'throttle:checkout-email', 'throttle:checkout-product-ip', 'checkout.abuse']);
Route::post('/checkout/upsell/decline', [\App\Http\Controllers\UpsellController::class, 'declineUpsell'])->name('checkout.upsell.decline')->middleware('throttle:30,1');
Route::post('/checkout/downsell/accept', [\App\Http\Controllers\UpsellController::class, 'acceptDownsell'])->name('checkout.downsell.accept')->middleware('throttle:30,1');
Route::post('/checkout/downsell/decline', [\App\Http\Controllers\UpsellController::class, 'declineDownsell'])->name('checkout.downsell.decline')->middleware('throttle:30,1');

Route::get('/conquistas/{slug}/share', [\App\Http\Controllers\ConquistasController::class, 'share'])
    ->name('conquistas.share')
    ->where('slug', '[a-z0-9-]+');

Route::get('/convite/co-producao/{token}', [\App\Http\Controllers\CoproducerInviteController::class, 'show'])
    ->name('convite.coproducao.show')
    ->where('token', '[a-zA-Z0-9]+');
Route::get('/convite/co-producao/{token}/cadastro', [\App\Http\Controllers\CoproducerInviteController::class, 'cadastro'])
    ->name('convite.coproducao.cadastro')
    ->where('token', '[a-zA-Z0-9]+');
Route::post('/convite/co-producao/{token}', [\App\Http\Controllers\CoproducerInviteController::class, 'accept'])
    ->name('convite.coproducao.accept')
    ->where('token', '[a-zA-Z0-9]+')
    ->middleware('throttle:10,1');

Route::get('/afiliar/{slug}', [\App\Http\Controllers\AffiliateProgramPublicController::class, 'show'])
    ->name('afiliar.show')
    ->where('slug', '[a-z0-9\-]+');
Route::get('/afiliar/{slug}/cadastro', [\App\Http\Controllers\AffiliateProgramPublicController::class, 'cadastro'])
    ->name('afiliar.cadastro')
    ->where('slug', '[a-z0-9\-]+');
Route::post('/afiliar/{slug}/cadastro', [\App\Http\Controllers\AffiliateProgramPublicController::class, 'register'])
    ->name('afiliar.register')
    ->where('slug', '[a-z0-9\-]+')
    ->middleware('throttle:10,1');

Route::middleware('guest')->group(function () {
    Route::get('/criar-admin', [\App\Http\Controllers\CreateFirstAdminController::class, 'show'])->name('criar-admin');
    Route::post('/criar-admin', [\App\Http\Controllers\CreateFirstAdminController::class, 'store'])->middleware('throttle:5,1');
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:platform-login');
    Route::get('/esqueci-senha', [ForgotPasswordController::class, 'showLinkRequestForm'])->name('password.request');
    Route::post('/esqueci-senha', [ForgotPasswordController::class, 'sendResetLinkEmail'])->name('password.email')->middleware('throttle:password-reset');
    Route::get('/redefinir-senha/{token}', [ResetPasswordController::class, 'showResetForm'])->name('password.reset');
    Route::post('/redefinir-senha', [ResetPasswordController::class, 'reset'])->name('password.update')->middleware('throttle:password-reset');
});

Route::middleware('auth')->group(function () {
    Route::match(['get', 'post'], '/logout', [LoginController::class, 'logout'])->name('logout');
});

// Menu Usuários: apenas admin pode gerenciar usuários (infoprodutores)
Route::middleware(['auth', 'role:admin'])->prefix('usuarios')->name('usuarios.')->group(function () {
    Route::get('/', [\App\Http\Controllers\UsersController::class, 'index'])->name('index');
    Route::get('/create', [\App\Http\Controllers\UsersController::class, 'create'])->name('create');
    Route::post('/', [\App\Http\Controllers\UsersController::class, 'store'])->name('store');
    Route::put('/{user}', [\App\Http\Controllers\UsersController::class, 'update'])->name('update');
    Route::delete('/{user}', [\App\Http\Controllers\UsersController::class, 'destroy'])->name('destroy');
});

// Equipe: cargos e membros (admin e infoprodutor; equipe apenas se tiver permissão)
Route::middleware(['auth', 'admin.tenant', 'role:admin|infoprodutor|team', 'team.permission:equipe.manage'])
    ->prefix('usuarios/equipe')
    ->group(function () {
        Route::get('/', [\App\Http\Controllers\EquipeController::class, 'index'])->name('usuarios.equipe');

        Route::post('/cargos', [\App\Http\Controllers\EquipeController::class, 'storeRole'])->name('usuarios.equipe.cargos.store');
        Route::put('/cargos/{role}', [\App\Http\Controllers\EquipeController::class, 'updateRole'])->name('usuarios.equipe.cargos.update');
        Route::delete('/cargos/{role}', [\App\Http\Controllers\EquipeController::class, 'destroyRole'])->name('usuarios.equipe.cargos.destroy');

        Route::post('/membros', [\App\Http\Controllers\EquipeController::class, 'storeMember'])->name('usuarios.equipe.membros.store');
        Route::put('/membros/{member}', [\App\Http\Controllers\EquipeController::class, 'updateMember'])->name('usuarios.equipe.membros.update');
        Route::delete('/membros/{member}', [\App\Http\Controllers\EquipeController::class, 'destroyMember'])->name('usuarios.equipe.membros.destroy');

        Route::post('/logs/clear', [\App\Http\Controllers\EquipeController::class, 'clearLogs'])->name('usuarios.equipe.logs.clear');
    });

Route::middleware(['auth', 'admin.tenant', 'role:admin|infoprodutor|team', 'audit.log'])->group(function () {
    Route::post('/painel/push-subscribe', [\App\Http\Controllers\PanelPwaController::class, 'pushSubscribe'])->name('panel.pwa.push-subscribe')->middleware('throttle:10,1');
    Route::patch('/painel/push-preferences', [\App\Http\Controllers\PanelPwaController::class, 'updatePushPreferences'])->name('panel.pwa.push-preferences');
    Route::get('/painel/notifications', [\App\Http\Controllers\PanelNotificationsController::class, 'index'])->name('panel.notifications.index');
    Route::patch('/painel/notifications/{notification}/read', [\App\Http\Controllers\PanelNotificationsController::class, 'markRead'])->name('panel.notifications.mark-read');
    Route::post('/painel/notifications/mark-read', [\App\Http\Controllers\PanelNotificationsController::class, 'markReadBatch'])->name('panel.notifications.mark-read-batch');
    Route::post('/painel/notifications/mark-all-read', [\App\Http\Controllers\PanelNotificationsController::class, 'markAllRead'])->name('panel.notifications.mark-all-read');
    Route::delete('/painel/notifications', [\App\Http\Controllers\PanelNotificationsController::class, 'clearAll'])->name('panel.notifications.clear-all');
    Route::get('/cloud/billing/status', function (Request $request) {
        if (! config('getfy.cloud_mode')) {
            abort(404);
        }

        $token = (string) env('GETFY_CLOUD_INSTALL_TOKEN', '');
        $base = (string) config('getfy.cloud.orch_api_base_url', '');
        if ($token === '' || $base === '') {
            return response()->json(['enabled' => false]);
        }

        $cacheMinutes = max(1, (int) config('getfy.cloud.billing_cache_minutes', 10));
        $cacheKey = 'cloud:billing:status';
        $lastGoodKey = 'cloud:billing:status:last_good';

        try {
            $payload = Cache::remember($cacheKey, now()->addMinutes($cacheMinutes), function () use ($base, $token, $lastGoodKey) {
                $url = $base.'/v1/public/billing/status';
                $hostHeader = parse_url($url, PHP_URL_HOST);
                $headers = array_filter([
                    'Authorization' => 'Bearer '.$token,
                    'Host' => $hostHeader ?: null,
                ]);

                $res = Http::timeout(10)
                    ->connectTimeout(5)
                    ->withHeaders($headers)
                    ->get($url);

                if ($res->status() === 401) {
                    return ['enabled' => false];
                }

                if (! $res->successful()) {
                    throw new \RuntimeException('Orchestrator retornou HTTP '.$res->status().'.');
                }

                $json = $res->json();
                if (! is_array($json)) {
                    throw new \RuntimeException('Resposta inválida do Orchestrator.');
                }

                $payload = ['enabled' => true] + $json;
                $payload['portalUrl'] = 'http://getfy.cloud/login';
                Cache::put($lastGoodKey, $payload, now()->addMinutes(60));

                return $payload;
            });

            return response()->json(is_array($payload) ? $payload : ['enabled' => false]);
        } catch (\Throwable $e) {
            $last = Cache::get($lastGoodKey);
            if (is_array($last) && isset($last['enabled'])) {
                return response()->json($last);
            }

            report($e);

            return response()->json(['enabled' => false]);
        }
    })->name('cloud.billing.status')->middleware('throttle:60,1');
    Route::get('/conquistas', [\App\Http\Controllers\ConquistasController::class, 'index'])->name('conquistas.index');
    Route::get('/meu-perfil', [\App\Http\Controllers\ProfileController::class, 'index'])->name('profile.index');
    Route::post('/meu-perfil', [\App\Http\Controllers\ProfileController::class, 'update'])->name('profile.update');
    Route::put('/meu-perfil/username', [\App\Http\Controllers\ProfileController::class, 'updateUsername'])->name('profile.update-username');
    Route::put('/meu-perfil/senha', [\App\Http\Controllers\ProfileController::class, 'updatePassword'])->name('profile.update-password');

    Route::get('/dashboard', [\App\Http\Controllers\DashboardController::class, '__invoke'])
        ->middleware('team.permission:dashboard.view')
        ->name('dashboard');

    Route::middleware('team.permission:dashboard.view')->prefix('api/dashboard/tracking')->group(function () {
        Route::get('/', [\App\Http\Controllers\TrackingController::class, 'data'])->name('dashboard.tracking.data');
        Route::put('/ad-spend/daily', [\App\Http\Controllers\TrackingController::class, 'updateDailyAdSpend'])->name('dashboard.tracking.ad-spend.daily');
        Route::put('/ad-spend/period', [\App\Http\Controllers\TrackingController::class, 'updatePeriodAdSpend'])->name('dashboard.tracking.ad-spend.period');
        Route::delete('/ad-spend/period', [\App\Http\Controllers\TrackingController::class, 'deletePeriodAdSpend'])->name('dashboard.tracking.ad-spend.period.delete');
    });

    Route::middleware('team.permission:reembolsos.view')->group(function () {
        Route::get('/reembolsos', [\App\Http\Controllers\ReembolsosController::class, 'index'])->name('reembolsos.index');
        Route::post('/reembolsos/{refundRequest}/approve', [\App\Http\Controllers\ReembolsosController::class, 'approve'])
            ->middleware('team.permission:reembolsos.manage')
            ->name('reembolsos.approve');
        Route::post('/reembolsos/{refundRequest}/reject', [\App\Http\Controllers\ReembolsosController::class, 'reject'])
            ->middleware('team.permission:reembolsos.manage')
            ->name('reembolsos.reject');
    });

    Route::middleware('team.permission:afiliados.manage')->group(function () {
        Route::get('/afiliados', [\App\Http\Controllers\AffiliatesHubController::class, 'index'])->name('afiliados.index');
    });

    Route::middleware('team.permission:financeiro.view')->group(function () {
        Route::get('/financeiro', [\App\Http\Controllers\FinanceiroController::class, 'index'])->name('financeiro.index');
    });

    Route::middleware('team.permission:financeiro.manage')->group(function () {
        Route::post('/financeiro/pix', [\App\Http\Controllers\FinanceiroController::class, 'updatePix'])
            ->middleware('throttle:payout')
            ->name('financeiro.pix');
        Route::post('/financeiro/payout', [\App\Http\Controllers\FinanceiroController::class, 'requestPayout'])
            ->middleware('throttle:payout')
            ->name('financeiro.payout');
        Route::post('/financeiro/saques-parceiros/{payout}/approve', [\App\Http\Controllers\FinanceiroPartnerPayoutsController::class, 'approve'])
            ->middleware('throttle:payout')
            ->name('financeiro.partner-payouts.approve');
        Route::post('/financeiro/saques-parceiros/{payout}/reject', [\App\Http\Controllers\FinanceiroPartnerPayoutsController::class, 'reject'])
            ->middleware('throttle:payout')
            ->name('financeiro.partner-payouts.reject');
    });

    Route::middleware('team.permission:vendas.view')->group(function () {
        Route::get('/vendas', [\App\Http\Controllers\VendasController::class, 'index'])->name('vendas.index');
        Route::get('/vendas/export', [\App\Http\Controllers\VendasController::class, 'export'])->name('vendas.export');
        Route::post('/vendas/{order}/resend-access-email', [\App\Http\Controllers\VendasController::class, 'resendAccessEmail'])->name('vendas.resend-access-email');
        Route::post('/vendas/{order}/approve-manually', [\App\Http\Controllers\VendasController::class, 'approveManually'])->name('vendas.approve-manually');
        Route::post('/vendas/{order}/refund', [\App\Http\Controllers\VendasController::class, 'refund'])
            ->middleware('team.permission:reembolsos.manage')
            ->name('vendas.refund');

        // Dossiê de comprovação (gateways/compliance)
        Route::get('/vendas/{order}/comprovacao', [\App\Http\Controllers\ProofDocumentsController::class, 'show'])->name('vendas.proof.show');
        Route::post('/vendas/{order}/comprovacao/gerar', [\App\Http\Controllers\ProofDocumentsController::class, 'generate'])->name('vendas.proof.generate');
        Route::get('/vendas/{order}/comprovacao/pdf', [\App\Http\Controllers\ProofDocumentsController::class, 'pdf'])->name('vendas.proof.pdf');

        // Exportação em lote (ZIP) com filtros
        Route::get('/vendas/comprovacao/exportar', [\App\Http\Controllers\ProofExportsController::class, 'index'])->name('vendas.proof.export.index');
        Route::post('/vendas/comprovacao/exportar/zip', [\App\Http\Controllers\ProofExportsController::class, 'exportZip'])->name('vendas.proof.export.zip');
        Route::post('/vendas/comprovacao/exportar/pdf', [\App\Http\Controllers\ProofExportsController::class, 'exportPdf'])->name('vendas.proof.export.pdf');
    });

    Route::middleware('team.permission:produtos.view')->group(function () {
        Route::get('/produtos', [\App\Http\Controllers\ProdutosController::class, 'index'])->name('produtos.index');
        Route::get('/produtos/create', [\App\Http\Controllers\ProdutosController::class, 'create'])->name('produtos.create');
        Route::post('/produtos', [\App\Http\Controllers\ProdutosController::class, 'store'])->name('produtos.store');
        Route::get('/produtos/{produto}/edit', [\App\Http\Controllers\ProdutosController::class, 'edit'])->name('produtos.edit');
        Route::get('/produtos/{produto}/checkout/edit', [\App\Http\Controllers\CheckoutConfigController::class, 'edit'])->name('checkout.builder');
        Route::post('/produtos/{produto}/checkout/ensure-slug', [\App\Http\Controllers\ProdutosController::class, 'ensureCheckoutSlug'])->name('produtos.checkout.ensure-slug');
        Route::delete('/produtos/{produto}/checkout/remove-slug', [\App\Http\Controllers\ProdutosController::class, 'removeCheckoutSlug'])->name('produtos.checkout.remove-slug');
        Route::put('/produtos/{produto}/checkout-config', [\App\Http\Controllers\CheckoutConfigController::class, 'update'])->name('checkout.config.update');
        Route::post('/produtos/{produto}/checkout-upload', [\App\Http\Controllers\CheckoutConfigController::class, 'uploadImage'])->name('checkout.upload');
    });
    Route::middleware('team.permission:produtos.view')->group(function () {
        Route::get('/produtos/{produto}/upsell-page/edit', [\App\Http\Controllers\UpsellDownsellPageController::class, 'editUpsellPage'])->name('upsell-page.edit');
        Route::put('/produtos/{produto}/upsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateUpsellPage'])->name('upsell-page.update');
        Route::post('/produtos/{produto}/upsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateUpsellPage'])->name('upsell-page.update.post');
        Route::get('/produtos/{produto}/downsell-page/edit', [\App\Http\Controllers\UpsellDownsellPageController::class, 'editDownsellPage'])->name('downsell-page.edit');
        Route::put('/produtos/{produto}/downsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateDownsellPage'])->name('downsell-page.update');
        Route::post('/produtos/{produto}/downsell-page/config', [\App\Http\Controllers\UpsellDownsellPageController::class, 'updateDownsellPage'])->name('downsell-page.update.post');
        Route::put('/produtos/{produto}', [\App\Http\Controllers\ProdutosController::class, 'update'])->name('produtos.update');
        Route::get('/produtos/{produto}/pix-parcelado/platform-rules', [\App\Http\Controllers\ProdutosController::class, 'pixParceladoPlatformRules'])->name('produtos.pix-parcelado.platform-rules');
        Route::post('/produtos/{produto}/email-template-logo', [\App\Http\Controllers\ProdutosController::class, 'uploadEmailTemplateLogo'])->name('produtos.email-template-logo');
        Route::delete('/produtos/{produto}', [\App\Http\Controllers\ProdutosController::class, 'destroy'])->name('produtos.destroy');
        Route::post('/produtos/{produto}/duplicate', [\App\Http\Controllers\ProdutosController::class, 'duplicate'])->name('produtos.duplicate');
        Route::post('/produtos/{produto}/alunos', [\App\Http\Controllers\ProdutosController::class, 'addAluno'])->name('produtos.alunos.add');
        Route::post('/produtos/{produto}/offers', [\App\Http\Controllers\ProdutosController::class, 'storeOffer'])->name('produtos.offers.store');
        Route::put('/produtos/{produto}/offers/{offer}', [\App\Http\Controllers\ProdutosController::class, 'updateOffer'])->name('produtos.offers.update');
        Route::delete('/produtos/{produto}/offers/{offer}', [\App\Http\Controllers\ProdutosController::class, 'destroyOffer'])->name('produtos.offers.destroy');
        Route::post('/produtos/{produto}/order-bumps', [\App\Http\Controllers\ProdutosController::class, 'storeOrderBump'])->name('produtos.order-bumps.store');
        Route::put('/produtos/{produto}/order-bumps/{bump}', [\App\Http\Controllers\ProdutosController::class, 'updateOrderBump'])->name('produtos.order-bumps.update');
        Route::delete('/produtos/{produto}/order-bumps/{bump}', [\App\Http\Controllers\ProdutosController::class, 'destroyOrderBump'])->name('produtos.order-bumps.destroy');
        Route::post('/produtos/{produto}/subscription-plans', [\App\Http\Controllers\ProdutosController::class, 'storeSubscriptionPlan'])->name('produtos.subscription-plans.store');
        Route::put('/produtos/{produto}/subscription-plans/{plan}', [\App\Http\Controllers\ProdutosController::class, 'updateSubscriptionPlan'])->name('produtos.subscription-plans.update');
        Route::delete('/produtos/{produto}/subscription-plans/{plan}', [\App\Http\Controllers\ProdutosController::class, 'destroySubscriptionPlan'])->name('produtos.subscription-plans.destroy');
        Route::put('/produtos/{produto}/external-member-area', [\App\Http\Controllers\ProdutosController::class, 'updateExternalMemberArea'])->name('produtos.external-member-area.update');
        Route::put('/produtos/{produto}/member-area-refund', [\App\Http\Controllers\ProdutosController::class, 'updateMemberAreaRefund'])->name('produtos.member-area-refund.update');
        Route::get('/produtos/cupons', [\App\Http\Controllers\CuponsController::class, 'index'])->name('cupons.index');
        Route::post('/produtos/cupons', [\App\Http\Controllers\CuponsController::class, 'store'])->name('cupons.store');
        Route::put('/produtos/cupons/{coupon}', [\App\Http\Controllers\CuponsController::class, 'update'])->name('cupons.update');
        Route::delete('/produtos/cupons/{coupon}', [\App\Http\Controllers\CuponsController::class, 'destroy'])->name('cupons.destroy');
        Route::get('/produtos/alunos', [\App\Http\Controllers\AlunosController::class, 'index'])->name('alunos.index');
        Route::get('/produtos/alunos/{aluno}', [\App\Http\Controllers\AlunosController::class, 'show'])->name('alunos.show')->where('aluno', '[0-9]+');
        Route::post('/produtos/alunos', [\App\Http\Controllers\AlunosController::class, 'store'])->name('alunos.store');
        Route::get('/produtos/alunos/import-example', [\App\Http\Controllers\AlunosController::class, 'downloadImportExample'])->name('alunos.import-example');
        Route::post('/produtos/alunos/import', [\App\Http\Controllers\AlunosController::class, 'import'])->name('alunos.import');
        Route::put('/produtos/alunos/{aluno}', [\App\Http\Controllers\AlunosController::class, 'update'])->name('alunos.update')->where('aluno', '[0-9]+');
        Route::delete('/produtos/alunos/{aluno}', [\App\Http\Controllers\AlunosController::class, 'destroy'])->name('alunos.destroy')->where('aluno', '[0-9]+');
        Route::delete('/produtos/alunos/{aluno}/produtos/{produto}', [\App\Http\Controllers\AlunosController::class, 'removeProduct'])->name('alunos.remove-product')->where('aluno', '[0-9]+');

        Route::get('/produtos/co-produtores', [\App\Http\Controllers\AffiliatesHubController::class, 'coproducersIndex'])->name('produtos.coproducers.index');
        Route::get('/produtos/afiliados-programas', [\App\Http\Controllers\AffiliatesHubController::class, 'affiliatesProductsIndex'])->name('produtos.affiliates.index');

        Route::get('/produtos/{produto}/coproducers', [\App\Http\Controllers\ProductCoproducerController::class, 'index'])->name('produtos.coproducers.api');
        Route::patch('/produtos/{produto}/coproduction-settings', [\App\Http\Controllers\ProductCoproducerController::class, 'updateCoproductionSettings'])->name('produtos.coproduction-settings');
        Route::get('/produtos/{produto}/coproducers/candidates', [\App\Http\Controllers\ProductCoproducerController::class, 'candidates'])->name('produtos.coproducers.candidates');
        Route::post('/produtos/{produto}/coproducers/assign', [\App\Http\Controllers\ProductCoproducerController::class, 'assign'])->name('produtos.coproducers.assign');
        Route::patch('/produtos/{produto}/coproducers/{coproducer}', [\App\Http\Controllers\ProductCoproducerController::class, 'update'])->name('produtos.coproducers.update');
        Route::post('/produtos/{produto}/coproducers/invite', [\App\Http\Controllers\ProductCoproducerController::class, 'invite'])->name('produtos.coproducers.invite');
        Route::post('/produtos/{produto}/coproducers/{coproducer}/revoke', [\App\Http\Controllers\ProductCoproducerController::class, 'revoke'])->name('produtos.coproducers.revoke');
        Route::post('/produtos/{produto}/coproducers/{coproducer}/resend', [\App\Http\Controllers\ProductCoproducerController::class, 'resendInvite'])->name('produtos.coproducers.resend');

        Route::get('/produtos/{produto}/affiliate-program', [\App\Http\Controllers\ProductAffiliateProgramController::class, 'show'])->name('produtos.affiliate-program.show');
        Route::put('/produtos/{produto}/affiliate-program', [\App\Http\Controllers\ProductAffiliateProgramController::class, 'updateProgram'])->name('produtos.affiliate-program.update');
        Route::post('/produtos/{produto}/affiliate-program', [\App\Http\Controllers\ProductAffiliateProgramController::class, 'updateProgram'])->name('produtos.affiliate-program.update.post');
        Route::put('/produtos/{produto}/affiliates/{affiliate}', [\App\Http\Controllers\ProductAffiliateProgramController::class, 'updateAffiliate'])->name('produtos.affiliates.update');
        Route::delete('/produtos/{produto}/affiliates/{affiliate}', [\App\Http\Controllers\ProductAffiliateProgramController::class, 'destroyAffiliate'])->name('produtos.affiliates.destroy');

        // Member Builder (área de membros do produto)
        Route::get('/produtos/{produto}/member-builder', [\App\Http\Controllers\MemberBuilderController::class, 'index'])->name('member-builder.index');
        Route::put('/produtos/{produto}/member-builder/config', [\App\Http\Controllers\MemberBuilderController::class, 'updateConfig'])->name('member-builder.config.update');
        // POST aceito para config: frontend envia JSON e em muitos ambientes _method não é aplicado a body JSON
        Route::post('/produtos/{produto}/member-builder/config', [\App\Http\Controllers\MemberBuilderController::class, 'updateConfig'])->name('member-builder.config.update.post');
        Route::post('/produtos/{produto}/member-builder/upload', [\App\Http\Controllers\MemberBuilderController::class, 'uploadImage'])->name('member-builder.upload');
        Route::post('/produtos/{produto}/member-builder/upload-pdf', [\App\Http\Controllers\MemberBuilderController::class, 'uploadPdf'])->name('member-builder.upload-pdf');
        Route::post('/produtos/{produto}/member-builder/upload-badge', [\App\Http\Controllers\MemberBuilderController::class, 'uploadBadge'])->name('member-builder.upload-badge');
        Route::post('/produtos/{produto}/member-builder/sections', [\App\Http\Controllers\MemberBuilderController::class, 'storeSection'])->name('member-builder.sections.store');
        Route::put('/produtos/{produto}/member-builder/sections/{section}', [\App\Http\Controllers\MemberBuilderController::class, 'updateSection'])->name('member-builder.sections.update');
        Route::delete('/produtos/{produto}/member-builder/sections/{section}', [\App\Http\Controllers\MemberBuilderController::class, 'destroySection'])->name('member-builder.sections.destroy');
        Route::post('/produtos/{produto}/member-builder/sections/{section}/duplicate', [\App\Http\Controllers\MemberBuilderController::class, 'duplicateSection'])->name('member-builder.sections.duplicate');
        Route::post('/produtos/{produto}/member-builder/reorder', [\App\Http\Controllers\MemberBuilderController::class, 'reorder'])->name('member-builder.reorder');
        Route::post('/produtos/{produto}/member-builder/sections/{section}/modules', [\App\Http\Controllers\MemberBuilderController::class, 'storeModule'])->name('member-builder.modules.store');
        Route::put('/produtos/{produto}/member-builder/modules/{module}', [\App\Http\Controllers\MemberBuilderController::class, 'updateModule'])->name('member-builder.modules.update');
        Route::delete('/produtos/{produto}/member-builder/modules/{module}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyModule'])->name('member-builder.modules.destroy');
        Route::post('/produtos/{produto}/member-builder/modules/{module}/duplicate', [\App\Http\Controllers\MemberBuilderController::class, 'duplicateModule'])->name('member-builder.modules.duplicate');
        Route::post('/produtos/{produto}/member-builder/modules/{module}/lessons', [\App\Http\Controllers\MemberBuilderController::class, 'storeLesson'])->name('member-builder.lessons.store');
        Route::put('/produtos/{produto}/member-builder/lessons/{lesson}', [\App\Http\Controllers\MemberBuilderController::class, 'updateLesson'])->name('member-builder.lessons.update');
        Route::delete('/produtos/{produto}/member-builder/lessons/{lesson}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyLesson'])->name('member-builder.lessons.destroy');
        Route::post('/produtos/{produto}/member-builder/lessons/{lesson}/duplicate', [\App\Http\Controllers\MemberBuilderController::class, 'duplicateLesson'])->name('member-builder.lessons.duplicate');
        Route::post('/produtos/{produto}/member-builder/internal-products', [\App\Http\Controllers\MemberBuilderController::class, 'storeInternalProduct'])->name('member-builder.internal-products.store');
        Route::delete('/produtos/{produto}/member-builder/internal-products/{internalProduct}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyInternalProduct'])->name('member-builder.internal-products.destroy');
        Route::post('/produtos/{produto}/member-builder/turmas', [\App\Http\Controllers\MemberBuilderController::class, 'storeTurma'])->name('member-builder.turmas.store');
        Route::put('/produtos/{produto}/member-builder/turmas/{turma}', [\App\Http\Controllers\MemberBuilderController::class, 'updateTurma'])->name('member-builder.turmas.update');
        Route::delete('/produtos/{produto}/member-builder/turmas/{turma}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyTurma'])->name('member-builder.turmas.destroy');
        Route::post('/produtos/{produto}/member-builder/turmas/{turma}/users', [\App\Http\Controllers\MemberBuilderController::class, 'attachTurmaUser'])->name('member-builder.turmas.users.attach');
        Route::delete('/produtos/{produto}/member-builder/turmas/{turma}/users/{user}', [\App\Http\Controllers\MemberBuilderController::class, 'detachTurmaUser'])->name('member-builder.turmas.users.detach');
        Route::post('/produtos/{produto}/member-builder/alunos', [\App\Http\Controllers\MemberBuilderController::class, 'storeNewAluno'])->name('member-builder.alunos.store');
        Route::get('/produtos/{produto}/member-builder/comments', [\App\Http\Controllers\MemberBuilderController::class, 'commentsIndex'])->name('member-builder.comments.index');
        Route::put('/produtos/{produto}/member-builder/comments/{comment}', [\App\Http\Controllers\MemberBuilderController::class, 'updateComment'])->name('member-builder.comments.update');
        Route::post('/produtos/{produto}/member-builder/community-pages', [\App\Http\Controllers\MemberBuilderController::class, 'storeCommunityPage'])->name('member-builder.community-pages.store');
        Route::put('/produtos/{produto}/member-builder/community-pages/{page}', [\App\Http\Controllers\MemberBuilderController::class, 'updateCommunityPage'])->name('member-builder.community-pages.update');
        Route::delete('/produtos/{produto}/member-builder/community-pages/{page}', [\App\Http\Controllers\MemberBuilderController::class, 'destroyCommunityPage'])->name('member-builder.community-pages.destroy');
        Route::post('/produtos/{produto}/member-builder/send-push', [\App\Http\Controllers\MemberBuilderController::class, 'sendPushNotification'])->name('member-builder.send-push');
    });

    Route::get('/vendas/assinaturas', [\App\Http\Controllers\AssinaturasController::class, 'index'])
        ->middleware('team.permission:vendas.view')
        ->name('assinaturas.index');
    Route::post('/vendas/assinaturas/{subscription}/cancel', [\App\Http\Controllers\AssinaturasController::class, 'cancel'])
        ->middleware('team.permission:vendas.manage')
        ->name('assinaturas.cancel');
    Route::get('/relatorios', [\App\Http\Controllers\RelatoriosController::class, 'index'])
        ->middleware('team.permission:relatorios.view')
        ->name('relatorios.index');
    Route::get('/relatorios/export/meta-compradores', [\App\Http\Controllers\RelatoriosController::class, 'exportMetaCompradores'])
        ->middleware(['team.permission:relatorios.view', 'throttle:20,1'])
        ->name('relatorios.export.meta-compradores');
    Route::get('/relatorios/export/meta-abandonos', [\App\Http\Controllers\RelatoriosController::class, 'exportMetaAbandonos'])
        ->middleware(['team.permission:relatorios.view', 'throttle:20,1'])
        ->name('relatorios.export.meta-abandonos');

    Route::middleware('team.permission:configuracoes.view')->group(function () {
        Route::get('/configuracoes', [\App\Http\Controllers\SettingsController::class, 'index'])->name('settings.index');
        Route::put('/configuracoes', [\App\Http\Controllers\SettingsController::class, 'update'])->name('settings.update');
        Route::post('/configuracoes/currencies/import-catalog', [\App\Http\Controllers\SettingsController::class, 'importCurrencyCatalog'])->name('settings.currencies.import');
        Route::post('/configuracoes/currencies/sync-rates', [\App\Http\Controllers\SettingsController::class, 'syncCurrencyRates'])->name('settings.currencies.sync');
        Route::post('/configuracoes/email/test', [\App\Http\Controllers\EmailTestController::class, 'test'])->name('settings.email.test');
        Route::post('/configuracoes/email/connection-test', [\App\Http\Controllers\EmailTestController::class, 'connectionTest'])->name('settings.email.connection-test');
        Route::post('/configuracoes/email/send-test', [\App\Http\Controllers\EmailTestController::class, 'sendTest'])->name('settings.email.send-test');
        Route::post('/configuracoes/storage/test', [\App\Http\Controllers\StorageTestController::class, '__invoke'])->name('settings.storage.test');
        Route::post('/configuracoes/storage/migrate', [\App\Http\Controllers\StorageMigrateController::class, '__invoke'])->name('settings.storage.migrate');
        Route::get('/configuracoes/update/check', [\App\Http\Controllers\UpdateController::class, 'check'])->name('settings.update.check');
        Route::get('/configuracoes/update/integrity', [\App\Http\Controllers\UpdateController::class, 'integrity'])->name('settings.update.integrity');
        Route::post('/configuracoes/update/migrate', [\App\Http\Controllers\UpdateController::class, 'migrateNow'])->name('settings.update.migrate')->middleware('throttle:10,1');
        Route::post('/configuracoes/update/run', [\App\Http\Controllers\UpdateController::class, 'run'])->name('settings.update.run')->middleware('throttle:10,1');
        Route::get('/configuracoes/gateways/{slug}', [\App\Http\Controllers\GatewaysController::class, 'show'])->name('gateways.show');
        Route::put('/configuracoes/gateways/{slug}', [\App\Http\Controllers\GatewaysController::class, 'update'])->name('gateways.update');
        Route::post('/configuracoes/gateways/{slug}/test', [\App\Http\Controllers\GatewaysController::class, 'test'])->name('gateways.test');
        Route::post('/configuracoes/gateways/cajupay/rotate-webhook-secret', [\App\Http\Controllers\GatewaysController::class, 'rotateCajuPayWebhookSecret'])
            ->name('gateways.cajupay.rotate-webhook');
        Route::post('/configuracoes/gateways/cajupay/pix-parcelado/accept', [\App\Http\Controllers\GatewaysController::class, 'acceptCajuPayPixParceladoEnrollment'])
            ->name('gateways.cajupay.parcelado.accept');
    });
    // Upload de arquivo (PHP/Laravel lida melhor via POST)
    Route::post('/configuracoes/gateways/{slug}/certificate', [\App\Http\Controllers\GatewaysController::class, 'updateCertificate'])
        ->middleware('team.permission:configuracoes.view')
        ->name('gateways.certificate');
    // Compat: mantem PUT caso algum cliente antigo use
    Route::put('/configuracoes/gateways/{slug}/certificate', [\App\Http\Controllers\GatewaysController::class, 'updateCertificate'])
        ->middleware('team.permission:configuracoes.view');
    Route::put('/configuracoes/gateways/order', [\App\Http\Controllers\GatewaysController::class, 'updateOrder'])
        ->middleware('team.permission:configuracoes.view')
        ->name('gateways.order');
    Route::get('/configuracoes/gateways/{slug}/fees', [\App\Http\Controllers\GatewaysController::class, 'fees'])
        ->middleware('team.permission:configuracoes.view')
        ->name('gateways.fees');
    Route::put('/configuracoes/gateways/{slug}/fees', [\App\Http\Controllers\GatewaysController::class, 'updateFees'])
        ->middleware('team.permission:configuracoes.view')
        ->name('gateways.fees.update');
    Route::middleware('role:admin|infoprodutor')->group(function () {
        Route::get('/gerenciar-plugins', [\App\Http\Controllers\PluginsController::class, 'index'])->name('plugins.index');
        Route::get('/gerenciar-plugins/store-plugins-list', [\App\Http\Controllers\PluginsController::class, 'storePluginsList'])->name('plugins.store.list');
        Route::get('/gerenciar-plugins/store-plugin/{slug}', [\App\Http\Controllers\PluginStoreController::class, 'show'])->name('plugins.store.show')->where('slug', '[a-z0-9\-]+');
        Route::post('/gerenciar-plugins/install/{slug}', [\App\Http\Controllers\PluginInstallController::class, '__invoke'])->name('plugins.install')->where('slug', '[a-z0-9\-]+')->middleware('throttle:10,1');
        Route::post('/gerenciar-plugins/install-from-zip', [\App\Http\Controllers\PluginInstallController::class, 'installFromZip'])->name('plugins.install.from-zip')->middleware('throttle:10,1');
        Route::post('/gerenciar-plugins/register-plugin/{slug}', [\App\Http\Controllers\PluginsController::class, 'registerPlugin'])->name('plugins.register')->where('slug', '[a-z0-9\-_]+')->middleware('throttle:10,1');
    });

    Route::get('/integracoes', [\App\Http\Controllers\IntegrationsController::class, 'index'])
        ->middleware('team.permission:integracoes.view')
        ->name('integrations.index');

    // API de pagamentos – aplicações
    Route::middleware('team.permission:api_pagamentos.view')->group(function () {
        Route::get('/aplicacoes-api', [\App\Http\Controllers\ApiApplicationsController::class, 'index'])->name('api-applications.index');
        Route::get('/aplicacoes-api/create', [\App\Http\Controllers\ApiApplicationsController::class, 'create'])->name('api-applications.create');
        Route::post('/aplicacoes-api', [\App\Http\Controllers\ApiApplicationsController::class, 'store'])->name('api-applications.store');
        Route::get('/aplicacoes-api/{apiApplication}/edit', [\App\Http\Controllers\ApiApplicationsController::class, 'edit'])->name('api-applications.edit');
        Route::put('/aplicacoes-api/{apiApplication}', [\App\Http\Controllers\ApiApplicationsController::class, 'update'])->name('api-applications.update');
        Route::delete('/aplicacoes-api/{apiApplication}', [\App\Http\Controllers\ApiApplicationsController::class, 'destroy'])->name('api-applications.destroy');
        Route::post('/aplicacoes-api/{apiApplication}/regenerate-key', [\App\Http\Controllers\ApiApplicationsController::class, 'regenerateKey'])->name('api-applications.regenerate-key');
        Route::post('/aplicacoes-api/{apiApplication}/logo', [\App\Http\Controllers\ApiApplicationsController::class, 'uploadLogo'])->name('api-applications.logo.upload');
        Route::delete('/aplicacoes-api/{apiApplication}/logo', [\App\Http\Controllers\ApiApplicationsController::class, 'removeLogo'])->name('api-applications.logo.remove');
        Route::get('/docs/api-pagamentos', [\App\Http\Controllers\ApiDocsController::class, '__invoke'])->name('api-docs.pagamentos');
        Route::get('/docs/api-pagamentos/testar', [\App\Http\Controllers\ApiDocsController::class, 'testar'])->name('api-docs.pagamentos.testar');
    });
    Route::middleware('team.permission:integracoes.view')->group(function () {
        Route::post('/integracoes/plugins/{slug}/enable', [\App\Http\Controllers\IntegrationsController::class, 'enablePlugin'])->name('integrations.plugins.enable');
        Route::post('/integracoes/plugins/{slug}/disable', [\App\Http\Controllers\IntegrationsController::class, 'disablePlugin'])->name('integrations.plugins.disable');
        Route::delete('/integracoes/plugins/{slug}', [\App\Http\Controllers\IntegrationsController::class, 'uninstallPlugin'])->name('integrations.plugins.uninstall');

        Route::post('/integracoes/utmify', [\App\Http\Controllers\UtmifyController::class, 'store'])->name('integrations.utmify.store');
        Route::put('/integracoes/utmify/{utmify}', [\App\Http\Controllers\UtmifyController::class, 'update'])->name('integrations.utmify.update');
        Route::delete('/integracoes/utmify/{utmify}', [\App\Http\Controllers\UtmifyController::class, 'destroy'])->name('integrations.utmify.destroy');

        Route::post('/integracoes/spedy', [\App\Http\Controllers\SpedyController::class, 'store'])->name('integrations.spedy.store');
        Route::put('/integracoes/spedy/{spedy}', [\App\Http\Controllers\SpedyController::class, 'update'])->name('integrations.spedy.update');
        Route::delete('/integracoes/spedy/{spedy}', [\App\Http\Controllers\SpedyController::class, 'destroy'])->name('integrations.spedy.destroy');

        Route::post('/integracoes/cademi', [\App\Http\Controllers\CademiController::class, 'store'])->name('integrations.cademi.store');
        Route::put('/integracoes/cademi/{cademi}', [\App\Http\Controllers\CademiController::class, 'update'])->name('integrations.cademi.update');
        Route::delete('/integracoes/cademi/{cademi}', [\App\Http\Controllers\CademiController::class, 'destroy'])->name('integrations.cademi.destroy');
    Route::get('/integracoes/cademi/{cademi}/tags', [\App\Http\Controllers\CademiController::class, 'tags'])->name('integrations.cademi.tags');

        Route::post('/integracoes/conversion-pixels', [\App\Http\Controllers\ConversionPixelIntegrationController::class, 'store'])->name('integrations.conversion-pixels.store');
        Route::put('/integracoes/conversion-pixels/{conversionPixelIntegration}', [\App\Http\Controllers\ConversionPixelIntegrationController::class, 'update'])->name('integrations.conversion-pixels.update');
        Route::delete('/integracoes/conversion-pixels/{conversionPixelIntegration}', [\App\Http\Controllers\ConversionPixelIntegrationController::class, 'destroy'])->name('integrations.conversion-pixels.destroy');

        Route::get('/integracoes/webhooks', [\App\Http\Controllers\WebhookController::class, 'index'])->name('integrations.webhooks.index');
        Route::get('/integracoes/webhooks/dashboard-stats', [\App\Http\Controllers\WebhookController::class, 'dashboardStats'])->name('integrations.webhooks.dashboard-stats');
        Route::get('/integracoes/webhooks/payload-preview', [\App\Http\Controllers\WebhookController::class, 'payloadPreview'])->name('integrations.webhooks.payload-preview');
        Route::post('/integracoes/webhooks', [\App\Http\Controllers\WebhookController::class, 'store'])->name('integrations.webhooks.store');
        Route::put('/integracoes/webhooks/{webhook}', [\App\Http\Controllers\WebhookController::class, 'update'])->name('integrations.webhooks.update');
        Route::delete('/integracoes/webhooks/{webhook}', [\App\Http\Controllers\WebhookController::class, 'destroy'])->name('integrations.webhooks.destroy');
        Route::post('/integracoes/webhooks/{webhook}/test', [\App\Http\Controllers\WebhookController::class, 'test'])->name('integrations.webhooks.test');
        Route::get('/integracoes/webhooks/{webhook}/logs', [\App\Http\Controllers\WebhookController::class, 'logs'])->name('integrations.webhooks.logs');
        Route::get('/integracoes/webhooks/{webhook}/logs/{log}', [\App\Http\Controllers\WebhookController::class, 'showLog'])->name('integrations.webhooks.logs.show');
        Route::get('/integracoes/checkout-externo/products', [\App\Http\Controllers\Integrations\ExternalCheckoutController::class, 'products'])->name('integrations.checkout-externo.products');
        Route::get('/integracoes/checkout-externo/endpoints', [\App\Http\Controllers\Integrations\ExternalCheckoutController::class, 'index'])->name('integrations.checkout-externo.endpoints.index');
        Route::post('/integracoes/checkout-externo/endpoints', [\App\Http\Controllers\Integrations\ExternalCheckoutController::class, 'store'])
            ->middleware('throttle:60,1')
            ->name('integrations.checkout-externo.endpoints.store');
        Route::put('/integracoes/checkout-externo/endpoints/{endpoint}', [\App\Http\Controllers\Integrations\ExternalCheckoutController::class, 'update'])
            ->whereNumber('endpoint')
            ->middleware('throttle:60,1')
            ->name('integrations.checkout-externo.endpoints.update');
        Route::delete('/integracoes/checkout-externo/endpoints/{endpoint}', [\App\Http\Controllers\Integrations\ExternalCheckoutController::class, 'destroy'])
            ->whereNumber('endpoint')
            ->middleware('throttle:30,1')
            ->name('integrations.checkout-externo.endpoints.destroy');
        Route::post('/integracoes/checkout-externo/endpoints/{endpoint}/regenerate-token', [\App\Http\Controllers\Integrations\ExternalCheckoutController::class, 'regenerateToken'])
            ->whereNumber('endpoint')
            ->middleware('throttle:20,1')
            ->name('integrations.checkout-externo.endpoints.regenerate-token');
    });

    // E-mail Marketing
    Route::middleware('team.permission:email_marketing.view')->group(function () {
        Route::get('/email-marketing', [\App\Http\Controllers\EmailMarketingController::class, 'index'])->name('email-marketing.index');
        Route::get('/email-marketing/create', [\App\Http\Controllers\EmailMarketingController::class, 'create'])->name('email-marketing.create');
        Route::post('/email-marketing/preview-recipients', [\App\Http\Controllers\EmailMarketingController::class, 'previewRecipientsByFilter'])->name('email-marketing.preview-recipients-by-filter');
        Route::post('/email-marketing', [\App\Http\Controllers\EmailMarketingController::class, 'store'])->name('email-marketing.store');
        Route::get('/email-marketing/{campaign}/edit', [\App\Http\Controllers\EmailMarketingController::class, 'edit'])->name('email-marketing.edit');
        Route::put('/email-marketing/{campaign}', [\App\Http\Controllers\EmailMarketingController::class, 'update'])->name('email-marketing.update');
        Route::post('/email-marketing/{campaign}/preview-recipients', [\App\Http\Controllers\EmailMarketingController::class, 'previewRecipients'])->name('email-marketing.preview-recipients');
        Route::post('/email-marketing/{campaign}/send', [\App\Http\Controllers\EmailMarketingController::class, 'send'])->name('email-marketing.send');
        Route::post('/email-marketing/{campaign}/pause', [\App\Http\Controllers\EmailMarketingController::class, 'pause'])->name('email-marketing.pause');
        Route::post('/email-marketing/{campaign}/resume', [\App\Http\Controllers\EmailMarketingController::class, 'resume'])->name('email-marketing.resume');
        Route::post('/email-marketing/{campaign}/cancel', [\App\Http\Controllers\EmailMarketingController::class, 'cancel'])->name('email-marketing.cancel');
    });

});

Route::middleware(['auth', 'admin.tenant', 'partner.panel'])->prefix('parceiro')->name('parceiro.')->group(function () {
    Route::get('/', [\App\Http\Controllers\Partner\PartnerDashboardController::class, 'index'])->name('dashboard');
    Route::get('/produtos', [\App\Http\Controllers\Partner\PartnerProductsController::class, 'index'])->name('produtos.index');
    Route::get('/vendas', [\App\Http\Controllers\Partner\PartnerVendasController::class, 'index'])->name('vendas.index');
    Route::get('/financeiro', [\App\Http\Controllers\Partner\PartnerFinanceiroController::class, 'index'])->name('financeiro.index');
    Route::post('/financeiro/pix', [\App\Http\Controllers\Partner\PartnerFinanceiroController::class, 'updatePix'])
        ->middleware('throttle:payout')
        ->name('financeiro.pix');
    Route::post('/financeiro/payout', [\App\Http\Controllers\Partner\PartnerFinanceiroController::class, 'requestPayout'])
        ->middleware('throttle:payout')
        ->name('financeiro.payout');
    Route::get('/produtos/{produto}', [\App\Http\Controllers\Partner\PartnerProductController::class, 'show'])
        ->middleware('partner.product')
        ->name('produtos.show');
    Route::put('/produtos/{produto}/pixels', [\App\Http\Controllers\Partner\PartnerProductController::class, 'updatePixels'])
        ->middleware('partner.product')
        ->name('produtos.pixels');
});

Route::middleware(['auth', 'role:aluno'])->group(function () {
    Route::get('/meus-produtos', [\App\Http\Controllers\MemberAreaController::class, 'index'])->name('student-area.index');
    Route::get('/meus-produtos/produtos/{product}/acessar', [\App\Http\Controllers\StudentProductAccessController::class, 'access'])
        ->name('student-area.product.access');
    Route::get('/meus-produtos/produtos/{product}/reembolso/eligibility', [\App\Http\Controllers\StudentAreaRefundController::class, 'eligibility'])
        ->name('student-area.refund.eligibility');
    Route::post('/meus-produtos/produtos/{product}/reembolso', [\App\Http\Controllers\StudentAreaRefundController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('student-area.refund.store');
    Route::redirect('/area-membros', '/meus-produtos', 302)->name('member-area.index');
});

// Área de membros por produto (path: /m/{slug})
Route::prefix('m/{slug}')->where(['slug' => '[a-zA-Z0-9\-]{3,64}'])->middleware('member.area.resolve')->group(function () {
    Route::get('manifest.json', [\App\Http\Controllers\MemberAreaAppController::class, 'manifest'])->name('member-area-app.manifest');
    Route::get('sw.js', function () {
        $path = public_path('member-area-sw.js');
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    })->name('member-area-app.sw');
    Route::get('login', [\App\Http\Controllers\MemberAreaLoginController::class, 'showLoginForm'])->name('member-area.login')->middleware('guest');
    Route::post('login', [\App\Http\Controllers\MemberAreaLoginController::class, 'login'])->name('member-area.login.post')->middleware(['guest', 'throttle:member-login']);
    Route::post('login-without-password', [\App\Http\Controllers\MemberAreaLoginController::class, 'loginWithoutPassword'])->name('member-area.login.without-password')->middleware(['guest', 'throttle:member-login']);
    Route::get('esqueci-senha', [\App\Http\Controllers\MemberAreaForgotPasswordController::class, 'showLinkRequestForm'])->name('member-area.password.request')->middleware('guest');
    Route::post('esqueci-senha', [\App\Http\Controllers\MemberAreaForgotPasswordController::class, 'sendResetLinkEmail'])->name('member-area.password.email')->middleware(['guest', 'throttle:password-reset']);
    Route::get('access', [\App\Http\Controllers\MemberAreaLoginController::class, 'magicAccess'])->name('member-area.magic-access')->middleware(['member.area.signed', 'throttle:magic-access']);

    Route::middleware(['member.area.access'])->group(function () {
        Route::get('/', [\App\Http\Controllers\MemberAreaAppController::class, 'show'])->name('member-area-app.show');
        Route::get('modulos', fn (string $slug) => redirect()->route('member-area-app.show', $slug))->name('member-area-app.modulos');
        Route::get('modulo/{module}', [\App\Http\Controllers\MemberAreaAppController::class, 'moduleContent'])->name('member-area-app.module');
        Route::get('aula/{lesson}', [\App\Http\Controllers\MemberAreaAppController::class, 'lesson'])->name('member-area-app.lesson');
        // Outros produtos: abrir dentro da mesma área (resolve para módulo embutido) ou abrir deliverable (produto tipo Link)
        Route::get('products/{relatedProduct}/open', [\App\Http\Controllers\MemberAreaAppController::class, 'openRelatedProduct'])
            ->where('relatedProduct', '[0-9A-Za-z\\-]{1,64}')
            ->name('member-area-app.products.open');
        Route::get('products/{relatedProduct}/deliverable', [\App\Http\Controllers\MemberAreaAppController::class, 'openRelatedProductDeliverable'])
            ->where('relatedProduct', '[0-9A-Za-z\\-]{1,64}')
            ->name('member-area-app.products.deliverable');
        Route::get('aula/{lesson}/pdf/{fileIndex}', [\App\Http\Controllers\MemberAreaAppController::class, 'presentationPdf'])
            ->whereNumber('fileIndex')
            ->name('member-area-app.lesson.pdf');
        Route::get('aula/{lesson}/pdf-annotations', [\App\Http\Controllers\MemberAreaAppController::class, 'getLessonPdfAnnotations'])->name('member-area-app.lesson.pdf-annotations');
        Route::put('aula/{lesson}/pdf-annotations', [\App\Http\Controllers\MemberAreaAppController::class, 'putLessonPdfAnnotations'])->middleware('throttle:120,1')->name('member-area-app.lesson.pdf-annotations.put');
        Route::post('aula/{lesson}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'toggleLessonLike'])->middleware('throttle:60,1')->name('member-area-app.lesson.like');
        Route::put('aula/{lesson}/notes', [\App\Http\Controllers\MemberAreaAppController::class, 'putLessonNote'])->middleware('throttle:60,1')->name('member-area-app.lesson.notes.put');
        Route::post('aula/{lesson}/complete', [\App\Http\Controllers\MemberAreaAppController::class, 'completeLesson'])->name('member-area-app.lesson.complete');
        Route::post('aula/{lesson}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeLessonComment'])->name('member-area-app.lesson.comments.store');
        Route::get('loja', [\App\Http\Controllers\MemberAreaAppController::class, 'loja'])->name('member-area-app.loja');
        Route::get('comunidade', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidade'])->name('member-area-app.comunidade');
        Route::get('comunidade/{pageSlug}', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidadePage'])->name('member-area-app.comunidade.page');
        Route::post('comunidade/{pageSlug}/posts', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPost'])->name('member-area-app.comunidade.posts.store');
        Route::delete('comunidade/{pageSlug}/posts/{post}', [\App\Http\Controllers\MemberAreaAppController::class, 'destroyCommunityPost'])->name('member-area-app.comunidade.posts.destroy');
        Route::post('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'likeCommunityPost'])->name('member-area-app.comunidade.posts.like');
        Route::delete('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'unlikeCommunityPost'])->name('member-area-app.comunidade.posts.unlike');
        Route::post('comunidade/{pageSlug}/posts/{post}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPostComment'])->name('member-area-app.comunidade.posts.comments.store');
        Route::get('certificado', [\App\Http\Controllers\MemberAreaAppController::class, 'certificado'])->name('member-area-app.certificado');
        Route::post('push-subscribe', [\App\Http\Controllers\MemberAreaAppController::class, 'pushSubscribe'])->name('member-area-app.push.subscribe');
        Route::get('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'index'])->name('member-area-app.notifications.index');
        Route::patch('notifications/{notification}/read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markRead'])->name('member-area-app.notifications.mark-read');
        Route::post('notifications/mark-all-read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markAllRead'])->name('member-area-app.notifications.mark-all-read');
        Route::delete('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'clearAll'])->name('member-area-app.notifications.clear-all');
        Route::put('conta', [\App\Http\Controllers\MemberAreaAccountController::class, 'updateProfile'])->name('member-area-app.conta.update');
        Route::put('conta/senha', [\App\Http\Controllers\MemberAreaAccountController::class, 'updatePassword'])->name('member-area-app.conta.password');
        Route::get('refund/eligibility', [\App\Http\Controllers\MemberAreaRefundController::class, 'eligibility'])->name('member-area-app.refund.eligibility');
        Route::post('refund', [\App\Http\Controllers\MemberAreaRefundController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('member-area-app.refund.store');
    });
});

// PWA e login da área de membros quando acessada por subdomínio ou domínio próprio (sem prefixo /m/slug)
Route::middleware(['web', 'member.area.resolve.by.host'])->group(function () {
    Route::get('sw.js', function () {
        $path = public_path('member-area-sw.js');
        if (! file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    })->name('member-area-app.sw.host');
    Route::get('access', [\App\Http\Controllers\MemberAreaLoginController::class, 'magicAccessHost'])->name('member-area.magic-access.host')->middleware(['member.area.signed', 'throttle:magic-access']);
    // Login da área de membros por host: não registramos GET/POST /login aqui para não sobrescrever
    // o login da plataforma. O Auth\LoginController delega para MemberAreaLoginController quando
    // o host for de área de membros (subdomínio ou domínio próprio).
    Route::post('login-without-password', function (\Illuminate\Http\Request $request) {
        $slug = $request->attributes->get('member_area_slug');
        if (! $slug) {
            abort(404);
        }

        return app()->call(\App\Http\Controllers\MemberAreaLoginController::class.'@loginWithoutPassword', [
            'request' => $request,
            'slug' => $slug,
        ]);
    })->name('member-area.login.without-password.host')->middleware(['guest', 'throttle:member-login']);

    Route::middleware(['member.area.access'])->group(function () {
        Route::get('modulos', [\App\Http\Controllers\MemberAreaAppController::class, 'modulos'])->name('member-area-app.modulos.host');
        Route::get('modulo/{module}', [\App\Http\Controllers\MemberAreaAppController::class, 'moduleContent'])->name('member-area-app.module.host');
        Route::get('aula/{lesson}', [\App\Http\Controllers\MemberAreaAppController::class, 'lesson'])->name('member-area-app.lesson.host');
        // Outros produtos (modo host): abrir dentro da mesma área (resolve para módulo embutido) ou abrir deliverable (produto tipo Link)
        Route::get('products/{relatedProduct}/open', [\App\Http\Controllers\MemberAreaAppController::class, 'openRelatedProduct'])
            ->where('relatedProduct', '[0-9A-Za-z\\-]{1,64}')
            ->name('member-area-app.products.open.host');
        Route::get('products/{relatedProduct}/deliverable', [\App\Http\Controllers\MemberAreaAppController::class, 'openRelatedProductDeliverable'])
            ->where('relatedProduct', '[0-9A-Za-z\\-]{1,64}')
            ->name('member-area-app.products.deliverable.host');
        Route::get('aula/{lesson}/pdf/{fileIndex}', [\App\Http\Controllers\MemberAreaAppController::class, 'presentationPdf'])
            ->whereNumber('fileIndex')
            ->name('member-area-app.lesson.pdf.host');
        Route::get('aula/{lesson}/pdf-annotations', [\App\Http\Controllers\MemberAreaAppController::class, 'getLessonPdfAnnotations'])->name('member-area-app.lesson.pdf-annotations.host');
        Route::put('aula/{lesson}/pdf-annotations', [\App\Http\Controllers\MemberAreaAppController::class, 'putLessonPdfAnnotations'])->middleware('throttle:120,1')->name('member-area-app.lesson.pdf-annotations.put.host');
        Route::post('aula/{lesson}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'toggleLessonLike'])->middleware('throttle:60,1')->name('member-area-app.lesson.like.host');
        Route::put('aula/{lesson}/notes', [\App\Http\Controllers\MemberAreaAppController::class, 'putLessonNote'])->middleware('throttle:60,1')->name('member-area-app.lesson.notes.put.host');
        Route::post('aula/{lesson}/complete', [\App\Http\Controllers\MemberAreaAppController::class, 'completeLesson'])->name('member-area-app.lesson.complete.host');
        Route::post('aula/{lesson}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeLessonComment'])->name('member-area-app.lesson.comments.store.host');
        Route::get('loja', [\App\Http\Controllers\MemberAreaAppController::class, 'loja'])->name('member-area-app.loja.host');
        Route::get('comunidade', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidade'])->name('member-area-app.comunidade.host');
        Route::get('comunidade/{pageSlug}', [\App\Http\Controllers\MemberAreaAppController::class, 'comunidadePage'])->name('member-area-app.comunidade.page.host');
        Route::post('comunidade/{pageSlug}/posts', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPost'])->name('member-area-app.comunidade.posts.store.host');
        Route::delete('comunidade/{pageSlug}/posts/{post}', [\App\Http\Controllers\MemberAreaAppController::class, 'destroyCommunityPost'])->name('member-area-app.comunidade.posts.destroy.host');
        Route::post('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'likeCommunityPost'])->name('member-area-app.comunidade.posts.like.host');
        Route::delete('comunidade/{pageSlug}/posts/{post}/like', [\App\Http\Controllers\MemberAreaAppController::class, 'unlikeCommunityPost'])->name('member-area-app.comunidade.posts.unlike.host');
        Route::post('comunidade/{pageSlug}/posts/{post}/comments', [\App\Http\Controllers\MemberAreaAppController::class, 'storeCommunityPostComment'])->name('member-area-app.comunidade.posts.comments.store.host');
        Route::get('certificado', [\App\Http\Controllers\MemberAreaAppController::class, 'certificado'])->name('member-area-app.certificado.host');
        Route::post('push-subscribe', [\App\Http\Controllers\MemberAreaAppController::class, 'pushSubscribe'])->name('member-area-app.push.subscribe.host');
        Route::get('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'index'])->name('member-area-app.notifications.index.host');
        Route::patch('notifications/{notification}/read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markRead'])->name('member-area-app.notifications.mark-read.host');
        Route::post('notifications/mark-all-read', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'markAllRead'])->name('member-area-app.notifications.mark-all-read.host');
        Route::delete('notifications', [\App\Http\Controllers\MemberAreaNotificationsController::class, 'clearAll'])->name('member-area-app.notifications.clear-all.host');
        Route::put('conta', [\App\Http\Controllers\MemberAreaAccountController::class, 'updateProfile'])->name('member-area-app.conta.update.host');
        Route::put('conta/senha', [\App\Http\Controllers\MemberAreaAccountController::class, 'updatePassword'])->name('member-area-app.conta.password.host');
        Route::get('refund/eligibility', [\App\Http\Controllers\MemberAreaRefundController::class, 'eligibility'])->name('member-area-app.refund.eligibility.host');
        Route::post('refund', [\App\Http\Controllers\MemberAreaRefundController::class, 'store'])
            ->middleware('throttle:10,1')
            ->name('member-area-app.refund.store.host');
    });
});
