<?php

namespace App\Http\Controllers;

use App\Events\OrderCompleted;
use App\Events\PixGenerated;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\AccessEmailService;
use App\Services\PaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UpsellController extends Controller
{
    private const CACHE_KEY_PREFIX = 'upsell_token.';

    private const TTL_MINUTES = 60;

    /**
     * Config de checkout efetiva do pedido (plano > oferta > produto).
     */
    private function getOrderCheckoutConfig(Order $order): array
    {
        if ($order->subscriptionPlan && $order->subscriptionPlan->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->subscriptionPlan->checkout_config);
        }
        if ($order->productOffer && $order->productOffer->checkout_config) {
            return array_replace_recursive(Product::defaultCheckoutConfig(), $order->productOffer->checkout_config);
        }
        return $order->product ? $order->product->checkout_config : [];
    }

    /**
     * Config de checkout do produto do pedido (só do produto, para downsell).
     * Tenta relação, depois find, depois leitura direta no DB.
     */
    private function getProductCheckoutConfigForOrder(Order $order): array
    {
        $product = $order->product;
        if ($product) {
            $product->refresh();
            $config = $product->checkout_config ?? [];
            if (is_array($config)) {
                return $config;
            }
        }
        $productId = $order->product_id;
        if ($productId === null || $productId === '') {
            return [];
        }
        $product = Product::find($productId) ?? Product::find((string) $productId);
        if ($product) {
            $config = $product->checkout_config ?? [];
            return is_array($config) ? $config : [];
        }
        $raw = DB::table('products')->where('id', $productId)->value('checkout_config')
            ?? DB::table('products')->where('id', (string) $productId)->value('checkout_config');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return json_decode(json_encode($raw), true) ?? [];
        }
        return [];
    }

    /**
     * Valida token e retorna dados em cache ou null.
     *
     * @return array{order_id: int, gateway: string}|null
     */
    private function validateAndConsumeToken(string $token): ?array
    {
        $key = self::CACHE_KEY_PREFIX . $token;
        $data = Cache::get($key);
        if (! is_array($data) || empty($data['order_id'])) {
            return null;
        }
        Cache::forget($key);

        return $data;
    }

    /**
     * Apenas valida e retorna dados do token sem consumir.
     *
     * @return array{order_id: int, gateway: string}|null
     */
    private function validateToken(string $token): ?array
    {
        $key = self::CACHE_KEY_PREFIX . $token;
        $data = Cache::get($key);
        if (! is_array($data) || empty($data['order_id'])) {
            return null;
        }
        return $data;
    }

    private function redirectToThankYou(Order $order): RedirectResponse
    {
        return redirect()->away($this->getThankYouUrl($order));
    }

    /**
     * URL da página de obrigado (redirect_after_purchase ou página obrigado com próximo passo).
     */
    private function getThankYouUrl(Order $order): string
    {
        $config = $this->getOrderCheckoutConfig($order);
        $url = $config['redirect_after_purchase'] ?? null;
        if (! empty($url) && is_string($url)) {
            return $url;
        }
        $next = ($order->user_id && User::find($order->user_id)) ? 'member-area' : 'login';

        return route('checkout.thank-you', ['next' => $next, 'order_id' => $order->id]);
    }

    /**
     * Página de obrigado (exibida ao recusar upsell/downsell ou quando não há redirect_after_purchase).
     */
    public function thankYouPage(Request $request, AccessEmailService $accessEmailService): Response
    {
        $next = $request->query('next', 'member-area');
        $redirectUrl = $next === 'login' ? route('login') : route('member-area.index');
        $redirectLabel = $next === 'login' ? 'Fazer login' : 'Acessar área de membros';
        $subtitle = 'Seu pedido foi registrado. Acesse o conteúdo pelo link abaixo.';

        $orderId = $request->integer('order_id', 0);
        $conversionPixels = Product::defaultConversionPixels();
        $orderAmount = 0;
        if ($orderId > 0) {
            $order = Order::with('product')->find($orderId);
            if ($order && $order->product) {
                $conversionPixels = $order->product->conversion_pixels ?? $conversionPixels;
                $orderAmount = (float) $order->amount;
                if ($order->product->type === Product::TYPE_LINK_PAGAMENTO) {
                    $slug = $order->getCheckoutSlug();
                    $redirectUrl = $slug !== '' ? route('checkout.show', ['slug' => $slug]) : url('/');
                    $redirectLabel = 'Voltar';
                    $subtitle = 'Seu pedido foi registrado. Você pode voltar para o site agora.';
                }
                $accessLink = $accessEmailService->getAccessLinkForOrder($order);
                if ($accessLink !== '') {
                    $redirectUrl = $accessLink;
                    $redirectLabel = $order->product->type === Product::TYPE_LINK
                        ? 'Acessar conteúdo'
                        : 'Acessar área de membros';
                    $subtitle = 'Seu pedido foi registrado. Acesse o conteúdo pelo link abaixo.';
                }
            }
        }

        return Inertia::render('Checkout/ThankYou', [
            'redirect_url' => $redirectUrl,
            'redirect_label' => $redirectLabel,
            'subtitle' => $subtitle,
            'conversion_pixels' => $conversionPixels,
            'order_id' => $orderId > 0 ? $orderId : null,
            'order_amount' => $orderAmount,
        ]);
    }

    /**
     * Monta lista de ofertas para a página de upsell (produto + oferta por item).
     * Mescla overrides por item (title_override, description, image_url, video_url) quando presentes no config.
     * product_id pode ser UUID (string) ou numérico.
     *
     * @param  array<int, array{product_id: string|int, product_offer_id?: int, title_override?: string, description?: string, image_url?: string, video_url?: string}>  $products
     * @return array<int, array{product_id: string, product_offer_id: int|null, name: string, price: float, price_formatted: string, image_url: string|null, checkout_slug: string, description: string|null, video_url: string|null}>
     */
    private function buildUpsellOffers(array $products): array
    {
        $out = [];
        foreach ($products as $item) {
            $productId = isset($item['product_id']) ? trim((string) $item['product_id']) : '';
            $offerId = (int) ($item['product_offer_id'] ?? 0);
            if ($productId === '') {
                continue;
            }
            $product = Product::where('id', $productId)->where('is_active', true)->first();
            if (! $product) {
                continue;
            }
            $offer = null;
            $name = $product->name;
            $price = (float) $product->price;
            $currency = $product->currency ?? 'BRL';
            $checkoutSlug = $product->checkout_slug;
            if ($offerId > 0) {
                $offer = ProductOffer::where('id', $offerId)->where('product_id', $productId)->first();
                if ($offer) {
                    $name = $offer->name;
                    $price = (float) $offer->price;
                    $currency = $offer->getCurrencyOrDefault();
                    $checkoutSlug = $offer->checkout_slug ?: $product->checkout_slug;
                }
            }
            $imageUrl = $product->image
                ? (new \App\Services\StorageService($product->tenant_id))->url($product->image)
                : null;
            if (! empty($item['image_url']) && is_string($item['image_url'])) {
                $imageUrl = $item['image_url'];
            }

            $out[] = [
                'product_id' => (string) $productId,
                'product_offer_id' => $offer ? $offer->id : null,
                'name' => trim((string) ($item['title_override'] ?? $name)) ?: $name,
                'price' => $price,
                'price_formatted' => 'R$ ' . number_format($price, 2, ',', '.'),
                'image_url' => $imageUrl,
                'checkout_slug' => $checkoutSlug,
                'description' => isset($item['description']) && trim((string) $item['description']) !== '' ? trim((string) $item['description']) : null,
                'video_url' => isset($item['video_url']) && trim((string) $item['video_url']) !== '' ? trim((string) $item['video_url']) : null,
            ];
        }
        return $out;
    }

    public function upsellPage(Request $request): Response|RedirectResponse
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $data = $this->validateToken($token);
        if (! $data) {
            return redirect()->route('login')->with('error', 'Link de oferta expirado ou inválido.');
        }

        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'user')->find($data['order_id']);
        if (! $order || $order->status !== 'completed') {
            return redirect()->route('login')->with('error', 'Oferta não disponível para este pedido.');
        }

        $config = $this->getOrderCheckoutConfig($order);
        $upsell = $config['upsell'] ?? [];
        if (empty($upsell['enabled']) || empty($upsell['products']) || ! is_array($upsell['products'])) {
            return $this->redirectToThankYou($order);
        }

        $offers = $this->buildUpsellOffers($upsell['products']);
        if (empty($offers)) {
            return $this->redirectToThankYou($order);
        }

        $productJustBought = $order->product ? [
            'name' => $order->product->name,
            'id' => $order->product->id,
        ] : null;
        $appearance = array_merge(
            [
                'title' => 'Quer levar isso também?',
                'subtitle' => 'Uma oferta exclusiva preparada para você',
                'primary_color' => '#0ea5e9',
                'button_accept' => 'Sim, quero aproveitar',
                'button_decline' => 'Não, obrigado',
            ],
            $upsell['appearance'] ?? []
        );
        $defaults = Product::defaultCheckoutConfig();
        $page = array_replace_recursive($defaults['upsell']['page'] ?? [], $upsell['page'] ?? []);

        $product = $order->product;
        $conversionPixels = $product ? ($product->conversion_pixels ?? Product::defaultConversionPixels()) : Product::defaultConversionPixels();

        return Inertia::render('Checkout/Upsell', [
            'token' => $token,
            'order' => [
                'id' => $order->id,
                'email' => $order->email,
            ],
            'product_just_bought' => $productJustBought,
            'offers' => $offers,
            'appearance' => $appearance,
            'page' => $page,
            'config' => $config,
            'conversion_pixels' => $conversionPixels,
        ]);
    }

    public function downsellPage(Request $request): Response|RedirectResponse
    {
        $token = $request->query('token');
        if (! $token || ! is_string($token)) {
            return redirect()->route('login')->with('error', 'Link inválido ou expirado.');
        }

        $data = $this->validateToken($token);
        if (! $data) {
            return redirect()->route('login')->with('error', 'Link de oferta expirado ou inválido.');
        }

        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'user')->find($data['order_id']);
        if (! $order || $order->status !== 'completed') {
            return redirect()->route('login')->with('error', 'Oferta não disponível para este pedido.');
        }

        $config = $this->getOrderCheckoutConfig($order);
        $downsell = $config['downsell'] ?? [];
        if (empty($downsell['enabled'])) {
            return $this->redirectToThankYou($order);
        }

        $productId = trim((string) ($downsell['product_id'] ?? ''));
        $offerId = (int) ($downsell['product_offer_id'] ?? 0);
        if ($productId === '') {
            return $this->redirectToThankYou($order);
        }

        $downsellProduct = [
            'product_id' => $productId,
            'product_offer_id' => $offerId,
            'title_override' => $downsell['title_override'] ?? null,
            'description' => $downsell['description'] ?? null,
            'image_url' => $downsell['image_url'] ?? null,
            'video_url' => $downsell['video_url'] ?? null,
        ];
        $offers = $this->buildUpsellOffers([$downsellProduct]);
        if (empty($offers)) {
            return $this->redirectToThankYou($order);
        }

        $productJustBought = $order->product ? [
            'name' => $order->product->name,
            'id' => $order->product->id,
        ] : null;
        $appearance = array_merge(
            [
                'title' => 'Última chance com desconto',
                'subtitle' => 'Uma oferta que não pode ficar de fora',
                'primary_color' => '#0ea5e9',
                'button_accept' => 'Aceitar oferta',
                'button_decline' => 'Não, obrigado',
            ],
            $downsell['appearance'] ?? []
        );
        $defaults = Product::defaultCheckoutConfig();
        $page = array_replace_recursive($defaults['downsell']['page'] ?? [], $downsell['page'] ?? []);

        $product = $order->product;
        $conversionPixels = $product ? ($product->conversion_pixels ?? Product::defaultConversionPixels()) : Product::defaultConversionPixels();

        return Inertia::render('Checkout/Downsell', [
            'token' => $token,
            'order' => [
                'id' => $order->id,
                'email' => $order->email,
            ],
            'product_just_bought' => $productJustBought,
            'offer' => $offers[0],
            'appearance' => $appearance,
            'page' => $page,
            'config' => $config,
            'conversion_pixels' => $conversionPixels,
        ]);
    }

    /**
     * Aceitar oferta(s) de upsell: criar pedido(s) e redirecionar para PIX ou checkout.
     * Aceita um único item (product_id/product_offer_id) ou array items[] para múltiplos.
     * product_id pode ser UUID (string).
     */
    public function acceptUpsell(Request $request): RedirectResponse|array
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'product_offer_id' => ['nullable', 'integer'],
            'product_id' => ['nullable', 'string'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['nullable', 'string'],
            'items.*.product_offer_id' => ['nullable', 'integer'],
        ]);

        $token = $validated['token'];
        $data = $this->validateAndConsumeToken($token);
        if (! $data) {
            if ($request->expectsJson()) {
                return ['success' => false, 'message' => 'Token inválido ou expirado.', 'redirect_url' => null];
            }
            return redirect()->route('login')->with('error', 'Link de oferta expirado ou inválido.');
        }

        $order = Order::with('product', 'productOffer', 'subscriptionPlan', 'user')->find($data['order_id']);
        if (! $order || $order->status !== 'completed') {
            if ($request->expectsJson()) {
                return ['success' => false, 'message' => 'Pedido inválido.', 'redirect_url' => null];
            }
            return redirect()->route('login')->with('error', 'Pedido inválido.');
        }

        $items = $validated['items'] ?? null;
        if (empty($items) || ! is_array($items)) {
            $pid = isset($validated['product_id']) ? trim((string) $validated['product_id']) : '';
            $oid = (int) ($validated['product_offer_id'] ?? 0);
            if ($pid !== '' || $oid > 0) {
                $items = [['product_id' => $pid ?: null, 'product_offer_id' => $oid ?: null]];
            }
        }
        if (empty($items)) {
            if ($request->expectsJson()) {
                return ['success' => false, 'message' => 'Nenhuma oferta selecionada.', 'redirect_url' => null];
            }
            return $this->redirectToThankYou($order);
        }

        $firstRedirectUrl = null;
        $gateway = $data['gateway'] ?? 'pix';

        foreach ($items as $one) {
            $productId = isset($one['product_id']) ? trim((string) $one['product_id']) : '';
            $offerId = (int) ($one['product_offer_id'] ?? 0);
            if ($offerId > 0) {
                $offer = ProductOffer::where('id', $offerId)->with('product')->first();
                if ($offer) {
                    $productId = (string) $offer->product_id;
                }
            }
            if ($productId === '') {
                continue;
            }

            $product = Product::where('id', $productId)->where('is_active', true)->first();
            $offer = $offerId > 0 ? ProductOffer::where('id', $offerId)->where('product_id', $productId)->first() : null;
            if (! $product) {
                continue;
            }

            $amount = $offer ? (float) $offer->price : (float) $product->price;
            $checkoutSlug = $offer && $offer->checkout_slug ? $offer->checkout_slug : $product->checkout_slug;

            $newOrder = Order::create([
                'tenant_id' => $order->tenant_id,
                'user_id' => $order->user_id,
                'product_id' => $product->id,
                'product_offer_id' => $offer?->id,
                'subscription_plan_id' => null,
                'period_start' => null,
                'period_end' => null,
                'is_renewal' => false,
                'amount' => $amount,
                'email' => $order->email,
                'cpf' => $order->cpf,
                'phone' => $order->phone,
                'customer_ip' => $request->ip(),
                'coupon_code' => null,
                'status' => 'pending',
                'gateway' => null,
                'gateway_id' => null,
            ]);
            OrderItem::create([
                'order_id' => $newOrder->id,
                'product_id' => $product->id,
                'product_offer_id' => $offer?->id,
                'subscription_plan_id' => null,
                'amount' => $amount,
                'position' => 0,
            ]);

            if ($firstRedirectUrl !== null) {
                continue;
            }

            if ($gateway === 'pix') {
                try {
                    $paymentService = app(PaymentService::class);
                    $user = $order->user;
                    $rawDoc = preg_replace('/\D/', '', $order->cpf ?? '');
                    $consumer = [
                        'name' => $user && trim((string) $user->name) !== '' ? $user->name : 'Cliente',
                        'document' => strlen($rawDoc) >= 11 ? $rawDoc : null,
                        'email' => $order->email,
                    ];
                    if (empty($consumer['document'])) {
                        $consumer['document'] = \App\Support\FakeConsumerData::getForGateway($order->id)['document'] ?? '00000000000';
                    }
                    $pixResult = $paymentService->createPixPayment($newOrder, $product, $consumer);
                    event(new PixGenerated($newOrder, [
                        'qrcode' => $pixResult['qrcode'] ?? null,
                        'copy_paste' => $pixResult['copy_paste'] ?? null,
                        'transaction_id' => $pixResult['transaction_id'] ?? null,
                    ]));
                    $pixToken = Str::random(32);
                    session()->put('pix_display.' . $pixToken, [
                        'order_id' => $newOrder->id,
                        'qrcode' => $pixResult['qrcode'] ?? null,
                        'copy_paste' => $pixResult['copy_paste'] ?? null,
                        'amount' => $amount,
                        'product_name' => $product->name,
                        'checkout_slug' => $checkoutSlug,
                        'redirect_after_purchase' => $this->getOrderCheckoutConfig($order)['redirect_after_purchase'] ?? null,
                        'customer_name' => $consumer['name'],
                        'customer_email' => $order->email,
                        'customer_phone' => $order->phone,
                        'created_at' => time(),
                    ]);
                    $firstRedirectUrl = route('checkout.pix', ['token' => $pixToken]);
                } catch (\Throwable $e) {
                    $newOrder->delete();
                    if ($request->expectsJson()) {
                        return ['success' => false, 'message' => $e->getMessage() ?: 'Não foi possível gerar o PIX.', 'redirect_url' => null];
                    }
                    return $this->redirectToThankYou($order)->with('error', $e->getMessage() ?: 'Não foi possível gerar o PIX.');
                }
            } else {
                $user = $order->user;
                $params = [
                    'offer_id' => $offer?->id,
                    'email' => $order->email,
                    'name' => $user ? $user->name : '',
                    'phone' => $order->phone,
                    'cpf' => $order->cpf,
                ];
                $firstRedirectUrl = route('checkout.show', ['slug' => $checkoutSlug]) . '?' . http_build_query(array_filter($params));
            }
        }

        if ($firstRedirectUrl === null) {
            if ($request->expectsJson()) {
                return ['success' => false, 'message' => 'Nenhuma oferta válida.', 'redirect_url' => null];
            }
            return $this->redirectToThankYou($order);
        }

        if ($request->expectsJson()) {
            return ['success' => true, 'redirect_url' => $firstRedirectUrl];
        }
        return redirect()->to($firstRedirectUrl);
    }

    /**
     * Recusar upsell: redirecionar para downsell (se ativo) ou obrigado.
     */
    public function declineUpsell(Request $request): RedirectResponse|array
    {
        $validated = $request->validate(['token' => ['required', 'string']]);
        $data = $this->validateAndConsumeToken($validated['token']);
        if (! $data) {
            if ($request->expectsJson()) {
                return ['success' => true, 'redirect_url' => route('login')];
            }
            return redirect()->route('login')->with('error', 'Link de oferta expirado ou inválido.');
        }

        $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($data['order_id']);
        if (! $order) {
            if ($request->expectsJson()) {
                return ['success' => true, 'redirect_url' => route('login')];
            }
            return redirect()->route('login');
        }

        // Downsell: config do produto; se vazio, usar config efetiva do pedido (igual à lógica do upsell)
        $productConfig = $this->getProductCheckoutConfigForOrder($order);
        $downsell = $productConfig['downsell'] ?? [];
        if (empty($downsell) || trim((string) ($downsell['product_id'] ?? '')) === '') {
            $orderConfig = $this->getOrderCheckoutConfig($order);
            $downsell = $orderConfig['downsell'] ?? [];
        }
        $downsellProductId = trim((string) ($downsell['product_id'] ?? ''));
        $downsellOn = ! empty($downsell['enabled']) && $downsellProductId !== '';
        if ($downsellOn) {
            $downsellToken = Str::random(64);
            Cache::put(self::CACHE_KEY_PREFIX . $downsellToken, [
                'order_id' => $order->id,
                'gateway' => $data['gateway'] ?? 'pix',
            ], now()->addMinutes(self::TTL_MINUTES));
            $redirectUrl = route('checkout.downsell', ['token' => $downsellToken]);
            if ($request->expectsJson()) {
                return ['success' => true, 'redirect_url' => $redirectUrl];
            }
            return redirect()->to($redirectUrl);
        }

        $thankYouUrl = $this->getThankYouUrl($order);
        if ($request->expectsJson()) {
            $payload = ['success' => true, 'redirect_url' => $thankYouUrl];
            if (config('app.debug')) {
                $payload['_debug'] = [
                    'order_id' => $order->id,
                    'order_product_id' => $order->product_id,
                    'product_config_has_downsell' => isset($productConfig['downsell']),
                    'downsell_keys' => array_keys($downsell),
                    'downsell_product_id' => $downsell['product_id'] ?? null,
                ];
            }
            return $payload;
        }
        return $this->redirectToThankYou($order);
    }

    /**
     * Aceitar oferta de downsell (mesmo fluxo que accept upsell).
     */
    public function acceptDownsell(Request $request): RedirectResponse|array
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
        ]);
        $configDownsell = null;
        $data = $this->validateToken($validated['token']);
        if ($data) {
            $order = Order::with('product', 'productOffer', 'subscriptionPlan')->find($data['order_id']);
            if ($order) {
                $config = $this->getOrderCheckoutConfig($order);
                $downsell = $config['downsell'] ?? [];
                if (! empty($downsell['product_id'])) {
                    $configDownsell = [
                        'product_id' => trim((string) ($downsell['product_id'] ?? '')),
                        'product_offer_id' => (int) ($downsell['product_offer_id'] ?? 0),
                    ];
                }
            }
        }
        if (! $data || ! $configDownsell) {
            $this->validateAndConsumeToken($validated['token']);
            if ($request->expectsJson()) {
                return ['success' => false, 'message' => 'Token inválido ou expirado.', 'redirect_url' => null];
            }
            return redirect()->route('login')->with('error', 'Link de oferta expirado ou inválido.');
        }

        $request->merge([
            'product_id' => $configDownsell['product_id'],
            'product_offer_id' => $configDownsell['product_offer_id'],
        ]);
        return $this->acceptUpsell($request);
    }

    /**
     * Recusar downsell: redirecionar para obrigado.
     */
    public function declineDownsell(Request $request): RedirectResponse|array
    {
        $validated = $request->validate(['token' => ['required', 'string']]);
        $data = $this->validateAndConsumeToken($validated['token']);
        if (! $data) {
            if ($request->expectsJson()) {
                return ['success' => true, 'redirect_url' => route('login')];
            }
            return redirect()->route('login')->with('error', 'Link de oferta expirado ou inválido.');
        }

        $order = Order::with('product')->find($data['order_id']);
        if (! $order) {
            if ($request->expectsJson()) {
                return ['success' => true, 'redirect_url' => route('login')];
            }
            return redirect()->route('login');
        }

        $thankYouUrl = $this->getThankYouUrl($order);
        if ($request->expectsJson()) {
            return ['success' => true, 'redirect_url' => $thankYouUrl];
        }
        return $this->redirectToThankYou($order);
    }
}
