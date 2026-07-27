<?php

namespace App\Support;

use App\Models\CheckoutSession;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductOffer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\URL;

class WebhookPayloadBuilder
{
    /** @var list<string> */
    private const TRACKING_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_content',
        'utm_term',
        'fbclid',
        'fbc',
        'fbp',
        'gclid',
        'msclkid',
        'src',
        'sck',
    ];

    /** @var list<string> */
    private const KEYS_ALLOW_NULL = ['birthDate'];

    /** Chaves que nunca devem ir para webhooks de integração (PII técnico / infra). */
    private const DENIED_PAYLOAD_KEYS = [
        'customer_ip',
        'ip',
        'ip_address',
        'client_ip',
        'server_ip',
        'remote_addr',
        'x_forwarded_for',
        'host',
        'hostname',
        'server',
        'vps',
        'password',
        'plain_password',
        'api_secret',
        'webhook_secret',
        'bearer_token',
    ];

    /** @var list<string> */
    private const PLAIN_PII_KEYS = ['email', 'phone', 'cpf', 'name', 'docnumber', 'doctype'];

    /** @var list<string> */
    private const HASH_PII_KEYS = ['email_hash', 'phone_hash', 'cpf_hash', 'name_hash'];

    /** @var list<string> */
    public static function allowedTrackingKeys(): array
    {
        return array_merge(self::TRACKING_KEYS, ['affiliate_code', 'sale_channel']);
    }

    /**
     * @param  array<string, mixed>  $extras  pix, boleto, access, test flags, etc.
     * @return array<string, mixed>
     */
    public static function forOrderEvent(Order $order, array $extras = []): array
    {
        $order->loadMissing([
            'user',
            'product',
            'productOffer',
            'subscriptionPlan',
            'orderItems.product',
            'orderItems.productOffer',
            'orderItems.subscriptionPlan',
        ]);

        $session = CheckoutSession::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->first();

        $checkoutLink = self::checkoutLink(
            $order->product,
            $order->productOffer,
            $order->subscriptionPlan,
            $order->getCheckoutSlug(),
        );
        $payment = self::paymentFromOrder($order);
        $tracking = self::trackingFromOrder($order, $session);

        $payload = [
            'order' => self::orderSnapshot($order),
            'customer' => self::customerFromOrder($order, $session),
            'checkout_link' => $checkoutLink,
            'product' => self::productSnapshot($order->product),
            'offer' => self::offerSnapshot($order->productOffer, $order->product),
            'subscription_plan' => self::planSnapshot($order->subscriptionPlan, $order->product),
            'payment' => $payment,
            'tracking' => $tracking,
        ];

        $bumps = self::orderBumpsFromOrder($order);
        if ($bumps !== []) {
            $payload['order_bumps'] = $bumps;
        }

        $payload = array_merge(
            $payload,
            self::orderIntegrationAliases($order, $checkoutLink, $payment, $tracking, $session),
            self::sanitizeExtras($extras),
        );

        return self::sanitizePayload($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forCartAbandoned(CheckoutSession $session): array
    {
        $session->loadMissing(['product', 'productOffer', 'subscriptionPlan']);

        $product = $session->product;
        $offer = $session->productOffer;
        $plan = $session->subscriptionPlan;
        $checkoutLink = self::checkoutLink(
            $product,
            $offer,
            $plan,
            $session->checkout_slug ?? $product?->checkout_slug ?? '',
        );
        $tracking = self::trackingFromSession($session);
        $customer = WebhookPiiHasher::integrationCustomerPayload(
            $session->email,
            null,
            null,
            $session->name,
        );

        $payload = [
            'checkout_session' => array_filter([
                'id' => $session->id,
                'product_offer_id' => $session->product_offer_id,
                'subscription_plan_id' => $session->subscription_plan_id,
                'created_at' => $session->created_at?->toIso8601String(),
                ...$customer,
            ]),
            'customer' => $customer,
            'checkout_link' => $checkoutLink,
            'checkoutUrl' => $checkoutLink,
            'product' => self::productSnapshot($product),
            'offer' => self::offerSnapshot($offer, $product),
            'subscription_plan' => self::planSnapshot($plan, $product),
            'tracking' => $tracking,
            'createdAt' => $session->created_at?->toIso8601String(),
        ];

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbc', 'fbp', 'sck'] as $utmKey) {
            if (($tracking[$utmKey] ?? null) !== null) {
                $payload[$utmKey] = $tracking[$utmKey];
            }
        }

        return self::sanitizePayload($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public static function forSubscriptionEvent(Subscription $subscription): array
    {
        $subscription->loadMissing(['user', 'product', 'subscriptionPlan']);
        $lifecycle = app(\App\Services\SubscriptionLifecycleService::class);
        $accessUntil = $lifecycle->accessUntil($subscription);
        $renewableUntil = $lifecycle->renewableUntil($subscription);
        $periodEnd = $lifecycle->periodEnd($subscription);
        $daysOverdue = null;
        if ($periodEnd && $periodEnd->isPast()) {
            $daysOverdue = (int) $periodEnd->diffInDays(now()->startOfDay(), false);
            if ($daysOverdue < 0) {
                $daysOverdue = 0;
            }
        }

        $checkoutLink = self::checkoutLink(
            $subscription->product,
            null,
            $subscription->subscriptionPlan,
            $subscription->subscriptionPlan?->checkout_slug
                ?? $subscription->product?->checkout_slug
                ?? '',
        );

        return self::sanitizePayload([
            'subscription' => [
                'id' => $subscription->id,
                'status' => $subscription->status,
                'effective_status' => $lifecycle->effectiveStatus($subscription),
                'current_period_start' => $subscription->current_period_start?->toDateString(),
                'current_period_end' => $subscription->current_period_end?->toDateString(),
                'access_until' => $accessUntil?->toDateString(),
                'renewable_until' => $renewableUntil?->toDateString(),
                'days_overdue' => $daysOverdue,
                'cancelled_at' => $subscription->cancelled_at?->toIso8601String(),
            ],
            'customer' => WebhookPiiHasher::integrationCustomerPayload(
                $subscription->user?->email,
                $subscription->user?->phone,
                null,
                $subscription->user?->name,
            ),
            'checkout_link' => $checkoutLink,
            'checkoutUrl' => $checkoutLink,
            'product' => self::productSnapshot($subscription->product),
            'subscription_plan' => self::planSnapshot($subscription->subscriptionPlan, $subscription->product),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function orderSnapshot(Order $order): array
    {
        $snapshot = [
            'id' => $order->id,
            'status' => $order->status,
            'amount' => (float) $order->amount,
            'currency' => $order->getCurrencyOrDefault(),
            'coupon_code' => $order->coupon_code,
            'is_renewal' => (bool) $order->is_renewal,
            'created_at' => $order->created_at?->toIso8601String(),
        ];

        if ($order->product_offer_id) {
            $snapshot['product_offer_id'] = (int) $order->product_offer_id;
        }
        if ($order->subscription_plan_id) {
            $snapshot['subscription_plan_id'] = (int) $order->subscription_plan_id;
        }

        if ($order->period_start || $order->period_end) {
            $snapshot['period_start'] = $order->period_start?->toDateString();
            $snapshot['period_end'] = $order->period_end?->toDateString();
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerFromOrder(Order $order, ?CheckoutSession $session = null): array
    {
        $name = $order->user?->name ?? $session?->name;

        return WebhookPiiHasher::integrationCustomerPayload(
            $order->email,
            $order->phone,
            $order->cpf,
            $name,
        );
    }

    /**
     * Campos no topo do payload (compatível com integrações estilo Cakto).
     *
     * @param  array<string, mixed>  $payment
     * @param  array<string, mixed>  $tracking
     * @return array<string, mixed>
     */
    private static function orderIntegrationAliases(
        Order $order,
        string $checkoutLink,
        array $payment,
        array $tracking,
        ?CheckoutSession $session,
    ): array {
        $method = (string) ($payment['method'] ?? 'pix');
        $meta = is_array($order->metadata) ? $order->metadata : [];

        $aliases = [
            'checkoutUrl' => $checkoutLink,
            'amount' => (float) $order->amount,
            'status' => self::integrationOrderStatus((string) $order->status),
            'createdAt' => $order->created_at?->toIso8601String(),
            'paidAt' => $order->status === 'completed' ? $order->updated_at?->toIso8601String() : null,
            'paymentMethod' => $method,
            'paymentMethodName' => self::paymentMethodLabel($method),
            'couponCode' => $order->coupon_code,
        ];

        if (isset($meta['installments'])) {
            $aliases['installments'] = max(1, (int) $meta['installments']);
        }

        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbc', 'fbp', 'sck'] as $key) {
            $fromTracking = $tracking[$key] ?? null;
            $fromMeta = self::stringOrNull($meta[$key] ?? null);
            $fromSessionMeta = null;
            if ($session && is_array($session->tracking_metadata)) {
                $fromSessionMeta = self::stringOrNull($session->tracking_metadata[$key] ?? null);
            }
            $value = $fromTracking ?? $fromMeta ?? $fromSessionMeta;
            if ($value !== null) {
                $aliases[$key] = $value;
            }
        }

        $affiliate = self::resolveAffiliateContact($order, $tracking);
        if ($affiliate !== null) {
            $aliases['affiliate'] = $affiliate;
        }

        return array_filter(
            $aliases,
            fn ($v, $k) => $v !== null && $v !== '' || in_array($k, self::KEYS_ALLOW_NULL, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    private static function integrationOrderStatus(string $status): string
    {
        return match ($status) {
            'completed' => 'paid',
            'pending' => 'pending',
            'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'rejected' => 'refused',
            default => $status,
        };
    }

    private static function paymentMethodLabel(string $method): string
    {
        return match (strtolower($method)) {
            'pix', 'pix_auto' => 'Pix',
            'card', 'credit_card' => 'Cartão de crédito',
            'boleto' => 'Boleto',
            'apple_pay' => 'Apple Pay',
            'google_pay' => 'Google Pay',
            'paypal' => 'PayPal',
            default => ucfirst(str_replace('_', ' ', $method)),
        };
    }

    private static function resolveAffiliateContact(Order $order, array $tracking): ?string
    {
        $code = self::stringOrNull($tracking['affiliate_code'] ?? null) ?? $order->affiliateCode();
        if ($code === null || $order->product_id === null) {
            return null;
        }

        $affiliate = AffiliateAttribution::approvedAffiliateForCode((string) $order->product_id, $code);
        if ($affiliate) {
            $affiliate->loadMissing('user');
            $email = $affiliate->user?->email;

            return is_string($email) && trim($email) !== '' ? trim($email) : $code;
        }

        return $code;
    }

    private static function checkoutLinkFromSlug(string $slug): string
    {
        $slug = trim($slug);

        return $slug !== '' ? URL::route('checkout.show', ['slug' => $slug]) : '';
    }

    /**
     * URL do checkout alinhada ao painel (oferta exclusiva, ou ?offer= / ?plan= no checkout principal).
     */
    private static function checkoutLink(
        ?Product $product,
        ?ProductOffer $offer = null,
        ?SubscriptionPlan $plan = null,
        ?string $slugOverride = null,
    ): string {
        $slug = trim((string) $slugOverride);
        if ($slug === '') {
            if (filled($offer?->checkout_slug)) {
                $slug = (string) $offer->checkout_slug;
            } elseif (filled($plan?->checkout_slug)) {
                $slug = (string) $plan->checkout_slug;
            } else {
                $slug = trim((string) ($product?->checkout_slug ?? ''));
            }
        }

        $url = self::checkoutLinkFromSlug($slug);
        if ($url === '') {
            return '';
        }

        if ($offer && filled($offer->checkout_slug)) {
            return $url;
        }
        if ($plan && filled($plan->checkout_slug)) {
            return $url;
        }

        $query = self::offerOrPlanQueryParams($offer, $plan);
        if ($query === []) {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    /**
     * @return array<string, string>
     */
    private static function offerOrPlanQueryParams(?ProductOffer $offer, ?SubscriptionPlan $plan): array
    {
        if ($offer) {
            $publicId = trim((string) ($offer->public_id ?? ''));
            if ($publicId !== '') {
                return ['offer' => $publicId];
            }

            return ['offer_id' => (string) $offer->id];
        }

        if ($plan) {
            $publicId = trim((string) ($plan->public_id ?? ''));
            if ($publicId !== '') {
                return ['plan' => $publicId];
            }

            return ['plan_id' => (string) $plan->id];
        }

        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function productSnapshot(?Product $product): ?array
    {
        if (! $product) {
            return null;
        }

        $snapshot = [
            'id' => $product->id,
            'name' => $product->name,
            'type' => $product->type,
            'billing_type' => $product->billing_type,
            'checkout_slug' => $product->checkout_slug,
        ];

        $checkoutUrl = self::checkoutLink($product, null, null);
        if ($checkoutUrl !== '') {
            $snapshot['checkout_url'] = $checkoutUrl;
        }

        return $snapshot;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function offerSnapshot(?ProductOffer $offer, ?Product $product = null): ?array
    {
        if (! $offer) {
            return null;
        }

        $product = $product ?? ($offer->relationLoaded('product') ? $offer->product : null);

        $snapshot = [
            'id' => $offer->id,
            'public_id' => $offer->public_id,
            'name' => $offer->name,
            'price' => (float) $offer->price,
            'currency' => $offer->getCurrencyOrDefault(),
            'position' => $offer->position,
            'offer_type' => filled($offer->checkout_slug) ? 'exclusive_checkout' : 'variant',
        ];

        if (filled($offer->checkout_slug)) {
            $snapshot['checkout_slug'] = $offer->checkout_slug;
        }

        $checkoutUrl = self::checkoutLink($product, $offer, null);
        if ($checkoutUrl !== '') {
            $snapshot['checkout_url'] = $checkoutUrl;
        }

        return array_filter(
            $snapshot,
            fn ($value, $key) => $value !== null && $value !== '' || $key === 'position',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function planSnapshot(?SubscriptionPlan $plan, ?Product $product = null): ?array
    {
        if (! $plan) {
            return null;
        }

        $product = $product ?? ($plan->relationLoaded('product') ? $plan->product : null);

        $snapshot = [
            'id' => $plan->id,
            'public_id' => $plan->public_id,
            'name' => $plan->name,
            'price' => (float) $plan->price,
            'currency' => $plan->getCurrencyOrDefault(),
            'interval' => $plan->interval,
            'position' => $plan->position,
            'plan_type' => filled($plan->checkout_slug) ? 'exclusive_checkout' : 'variant',
        ];

        if (filled($plan->checkout_slug)) {
            $snapshot['checkout_slug'] = $plan->checkout_slug;
        }

        $checkoutUrl = self::checkoutLink($product, null, $plan);
        if ($checkoutUrl !== '') {
            $snapshot['checkout_url'] = $checkoutUrl;
        }

        return array_filter(
            $snapshot,
            fn ($value, $key) => $value !== null && $value !== '' || $key === 'position',
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * Linhas extras (order bumps) — o produto principal fica em product/offer/subscription_plan.
     *
     * @return list<array<string, mixed>>
     */
    private static function orderBumpsFromOrder(Order $order): array
    {
        if ($order->orderItems->isEmpty()) {
            return [];
        }

        $currency = $order->getCurrencyOrDefault();
        $lines = [];

        foreach ($order->orderItems as $item) {
            $isMainLine = (int) ($item->position ?? 0) === 0
                && (string) $item->product_id === (string) $order->product_id;
            if ($isMainLine) {
                continue;
            }
            $product = $item->product;
            if (! $product) {
                continue;
            }
            $line = [
                'product_id' => $product->id,
                'name' => $product->name,
                'amount' => (float) $item->amount,
                'currency' => $currency,
            ];

            if ($item->product_offer_id) {
                $line['product_offer_id'] = (int) $item->product_offer_id;
                $line['offer'] = self::offerSnapshot($item->productOffer, $product);
            }
            if ($item->subscription_plan_id) {
                $line['subscription_plan_id'] = (int) $item->subscription_plan_id;
                $line['subscription_plan'] = self::planSnapshot($item->subscriptionPlan, $product);
            }

            $lines[] = array_filter(
                $line,
                fn ($value) => $value !== null,
            );
        }

        return $lines;
    }

    /**
     * @return array{method: string, gateway: ?string, gateway_transaction_id: ?string}
     */
    private static function paymentFromOrder(Order $order): array
    {
        return [
            'method' => $order->checkoutPaymentMethod(),
            'gateway' => $order->gateway,
            'gateway_transaction_id' => $order->gateway_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function trackingFromOrder(Order $order, ?CheckoutSession $session): array
    {
        $meta = is_array($order->metadata) ? $order->metadata : [];
        $tracking = self::pickTrackingFields($meta);

        foreach (['utm_source', 'utm_medium', 'utm_campaign'] as $key) {
            if (($tracking[$key] ?? null) === null) {
                $tracking[$key] = self::stringOrNull($meta[$key] ?? null);
            }
        }

        if ($tracking['affiliate_code'] === null) {
            $tracking['affiliate_code'] = $order->affiliateCode();
        }
        if ($tracking['sale_channel'] === null) {
            $tracking['sale_channel'] = $order->saleChannel();
        }

        if ($session) {
            $sessionFields = self::pickTrackingFields([
                'utm_source' => $session->utm_source,
                'utm_medium' => $session->utm_medium,
                'utm_campaign' => $session->utm_campaign,
                ...(is_array($session->tracking_metadata) ? $session->tracking_metadata : []),
            ]);
            foreach ($sessionFields as $key => $value) {
                if ($value !== null && ($tracking[$key] ?? null) === null) {
                    $tracking[$key] = $value;
                }
            }
        }

        return self::filterEmptyTracking($tracking);
    }

    /**
     * @return array<string, mixed>
     */
    private static function trackingFromSession(CheckoutSession $session): array
    {
        $tracking = self::pickTrackingFields([
            'utm_source' => $session->utm_source,
            'utm_medium' => $session->utm_medium,
            'utm_campaign' => $session->utm_campaign,
            ...(is_array($session->tracking_metadata) ? $session->tracking_metadata : []),
        ]);

        $sessionMeta = is_array($session->tracking_metadata) ? $session->tracking_metadata : [];
        $ref = $sessionMeta['affiliate_ref'] ?? $sessionMeta['ref'] ?? null;
        if (is_string($ref) && trim($ref) !== '') {
            $tracking['affiliate_code'] = AffiliateAttribution::normalizeRef($ref);
        }

        return self::filterEmptyTracking($tracking);
    }

    /**
     * @param  array<string, mixed>  $source
     * @return array<string, ?string>
     */
    private static function pickTrackingFields(array $source): array
    {
        $tracking = [
            'affiliate_code' => null,
            'sale_channel' => null,
        ];

        foreach (self::TRACKING_KEYS as $key) {
            if (self::isDeniedKey($key)) {
                continue;
            }
            $tracking[$key] = self::stringOrNull($source[$key] ?? null);
        }

        return $tracking;
    }

    public static function isDeniedKey(string $key): bool
    {
        $key = strtolower($key);
        if (in_array($key, self::DENIED_PAYLOAD_KEYS, true)) {
            return true;
        }
        if (! WebhookPiiHasher::includesCustomerHashes() && in_array($key, self::HASH_PII_KEYS, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $extras
     * @return array<string, mixed>
     */
    public static function sanitizeExtras(array $extras): array
    {
        if (isset($extras['pix']) && is_array($extras['pix'])) {
            $extras['pix'] = self::enrichPixIntegrationAliases(self::sanitizePixPayload($extras['pix']));
        }
        if (isset($extras['boleto']) && is_array($extras['boleto'])) {
            $extras['boleto'] = self::enrichBoletoIntegrationAliases($extras['boleto']);
        }
        if (isset($extras['access']) && is_array($extras['access'])) {
            $extras['access'] = self::sanitizeAccessPayload($extras['access']);
        }

        return self::stripDeniedKeysRecursive($extras);
    }

    /**
     * @param  array<string, mixed>  $pix
     * @return array<string, mixed>
     */
    private static function enrichPixIntegrationAliases(array $pix): array
    {
        if (isset($pix['copy_paste']) && ! isset($pix['qrCode'])) {
            $pix['qrCode'] = $pix['copy_paste'];
        }
        if (isset($pix['qrcode']) && ! isset($pix['qrCode'])) {
            $pix['qrCode'] = $pix['qrcode'];
        }

        return $pix;
    }

    /**
     * @param  array<string, mixed>  $boleto
     * @return array<string, mixed>
     */
    private static function enrichBoletoIntegrationAliases(array $boleto): array
    {
        if (isset($boleto['expire_at']) && ! isset($boleto['expirationDate'])) {
            $boleto['expirationDate'] = $boleto['expire_at'];
        }
        if (isset($boleto['pdf_url']) && ! isset($boleto['boletoUrl'])) {
            $boleto['boletoUrl'] = $boleto['pdf_url'];
        }

        return $boleto;
    }

    /**
     * @param  array<string, mixed>  $pix
     * @return array<string, mixed>
     */
    public static function sanitizePixPayload(array $pix): array
    {
        $out = [
            'copy_paste' => $pix['copy_paste'] ?? null,
            'transaction_id' => $pix['transaction_id'] ?? null,
        ];
        $qr = $pix['qrcode'] ?? null;
        if (is_string($qr) && $qr !== '' && ! self::looksLikeEmbeddedImage($qr)) {
            $out['qrcode'] = $qr;
        }

        return array_filter($out, fn ($v) => $v !== null && $v !== '');
    }

    /**
     * @param  array<string, mixed>  $access
     * @return array<string, mixed>
     */
    public static function sanitizeAccessPayload(array $access): array
    {
        $safe = [];
        foreach (['type', 'link', 'product_type'] as $key) {
            if (isset($access[$key]) && is_scalar($access[$key])) {
                $safe[$key] = $access[$key];
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function sanitizePayload(array $payload): array
    {
        return self::stripDeniedKeysRecursive($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stripDeniedKeysRecursive(array $data): array
    {
        if (array_is_list($data)) {
            $out = [];
            foreach ($data as $item) {
                if (is_array($item)) {
                    $nested = self::stripDeniedKeysRecursive($item);
                    if ($nested !== []) {
                        $out[] = $nested;
                    }
                } elseif ($item !== null && $item !== '') {
                    $out[] = $item;
                }
            }

            return $out;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || self::isDeniedKey($key)) {
                continue;
            }
            if (is_array($value)) {
                $nested = self::stripDeniedKeysRecursive($value);
                if ($nested !== []) {
                    $out[$key] = $nested;
                }

                continue;
            }
            if (in_array($key, self::KEYS_ALLOW_NULL, true) && $value === null) {
                $out[$key] = null;

                continue;
            }
            if ($value !== null && $value !== '') {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    private static function looksLikeEmbeddedImage(string $value): bool
    {
        return str_starts_with($value, 'data:image/')
            || (strlen($value) > 2048 && preg_match('/^[A-Za-z0-9+\/=]+$/', substr($value, 0, 256)) === 1);
    }

    /**
     * @param  array<string, mixed>  $tracking
     * @return array<string, mixed>
     */
    private static function filterEmptyTracking(array $tracking): array
    {
        return array_filter(
            $tracking,
            fn ($value) => $value !== null && $value !== ''
        );
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }
        $str = trim((string) $value);

        return $str !== '' ? $str : null;
    }

    /**
     * Payload de exemplo para teste manual no painel de integrações.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function sampleTestPayload(string $eventSlug, array $context = []): array
    {
        $checkoutLink = rtrim((string) config('app.url'), '/').'/c/exemplo-checkout?offer=B8BcHrY';
        $productId = $context['product_id'] ?? 'prod-exemplo-uuid';
        $offerId = $context['offer_id'] ?? 1;
        $offerPublicId = $context['offer_public_id'] ?? 'B8BcHrY';

        $customer = WebhookPiiHasher::integrationCustomerPayload(
            'exemplo@email.com',
            '5511999999999',
            '12345678900',
            'Cliente Exemplo',
        );

        $base = [
            'test' => true,
            'message' => 'Este é um evento de teste disparado manualmente.',
            'webhook_name' => $context['webhook_name'] ?? 'Webhook de teste',
            'webhook_id' => $context['webhook_id'] ?? 0,
            'order' => [
                'id' => 90001,
                'status' => $eventSlug === 'pedido_pago' ? 'completed' : 'pending',
                'amount' => 197.0,
                'currency' => 'BRL',
                'coupon_code' => null,
                'is_renewal' => false,
                'product_offer_id' => $offerId,
                'created_at' => now()->toIso8601String(),
            ],
            'customer' => $customer,
            'checkout_link' => $checkoutLink,
            'checkoutUrl' => $checkoutLink,
            'amount' => 197.0,
            'status' => $eventSlug === 'pedido_pago' ? 'paid' : 'pending',
            'createdAt' => now()->toIso8601String(),
            'paidAt' => $eventSlug === 'pedido_pago' ? now()->toIso8601String() : null,
            'paymentMethod' => 'pix',
            'paymentMethodName' => 'Pix',
            'couponCode' => null,
            'product' => [
                'id' => $productId,
                'name' => 'MeuLink - Full Anual',
                'type' => 'area_membros',
                'billing_type' => 'one_time',
                'checkout_slug' => 'exemplo-checkout',
                'checkout_url' => rtrim((string) config('app.url'), '/').'/c/exemplo-checkout',
            ],
            'offer' => [
                'id' => $offerId,
                'public_id' => $offerPublicId,
                'name' => 'Oferta principal',
                'price' => 197.0,
                'currency' => 'BRL',
                'position' => 0,
                'offer_type' => 'variant',
                'checkout_url' => $checkoutLink,
            ],
            'subscription_plan' => null,
            'payment' => [
                'method' => 'pix',
                'gateway' => 'cajupay',
                'gateway_transaction_id' => 'tx_exemplo_123',
            ],
            'tracking' => [
                'utm_source' => 'instagram',
                'utm_medium' => 'social',
                'utm_campaign' => 'lancamento',
            ],
            'utm_source' => 'instagram',
            'utm_medium' => 'social',
            'utm_campaign' => 'lancamento',
        ];

        if ($eventSlug === 'pix_gerado') {
            $base['pix'] = [
                'copy_paste' => '00020126580014br.gov.bcb.pix...',
                'qrCode' => '00020126580014br.gov.bcb.pix...',
                'transaction_id' => 'txid-exemplo-teste',
            ];
        }

        if ($eventSlug === 'boleto_gerado') {
            $expireAt = now()->addDays(3)->toDateString();
            $base['boleto'] = [
                'amount' => 197.0,
                'expire_at' => $expireAt,
                'expirationDate' => $expireAt,
                'barcode' => '23793.38128 60000.000003 00000.000400 1 84370000019700',
                'pdf_url' => $checkoutLink,
                'boletoUrl' => $checkoutLink,
            ];
        }

        if ($eventSlug === 'carrinho_abandonado') {
            unset(
                $base['order'],
                $base['payment'],
                $base['amount'],
                $base['status'],
                $base['paidAt'],
                $base['paymentMethod'],
                $base['paymentMethodName'],
            );
            $base['checkout_session'] = [
                'id' => 1,
                'product_offer_id' => $offerId,
                'created_at' => now()->toIso8601String(),
                ...$customer,
            ];
        }

        if (str_starts_with($eventSlug, 'assinatura_')) {
            unset($base['order'], $base['offer'], $base['payment']);
            $base['subscription'] = [
                'id' => 1,
                'status' => 'active',
                'effective_status' => 'active',
                'current_period_start' => now()->toDateString(),
                'current_period_end' => now()->addMonth()->toDateString(),
            ];
            $base['subscription_plan'] = [
                'id' => 1,
                'public_id' => 'PlnX9k2',
                'name' => 'Plano mensal',
                'price' => 49.9,
                'currency' => 'BRL',
                'interval' => 'monthly',
                'position' => 0,
                'plan_type' => 'variant',
            ];
        }

        return $base;
    }
}
