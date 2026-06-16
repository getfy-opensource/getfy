<script setup>
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { AlertCircle, CheckCircle2 } from 'lucide-vue-next';
import { useCheckoutLocale } from '@/composables/useCheckoutLocale';
import { useCheckoutCustomCode } from '@/composables/useCheckoutCustomCode';
import CheckoutTimer from '@/components/checkout/CheckoutTimer.vue';
import CheckoutBanners from '@/components/checkout/CheckoutBanners.vue';
import CheckoutSalesPage from '@/components/checkout/CheckoutSalesPage.vue';
import CheckoutYoutube from '@/components/checkout/CheckoutYoutube.vue';
import CheckoutSummary from '@/components/checkout/CheckoutSummary.vue';
import CheckoutForm from '@/components/checkout/CheckoutForm.vue';
import CheckoutSidebar from '@/components/checkout/CheckoutSidebar.vue';
import SalesNotification from '@/components/checkout/SalesNotification.vue';
import SupportButton from '@/components/checkout/SupportButton.vue';
import ExitPopup from '@/components/checkout/ExitPopup.vue';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import { firePurchaseWhenReady, waitForPixelSdks } from '@/composables/useConversionPurchase';
import {
    pixelsNeedAnyBrowserSdk,
} from '@/lib/pixelPlatforms';
import {
    normalizeContentBlocks,
    normalizeContentBlocksForPreview,
    dedupeContentBlocks,
} from '@/lib/checkoutContentFormats';
import { registerPluginCheckoutComponents } from '@/composables/usePluginCheckoutRegistry';
import {
    PREVIEW_MESSAGE_TYPE,
    PREVIEW_ACK_TYPE,
    PREVIEW_WINDOW_CALLBACK,
    isCheckoutBuilderPreviewUrl,
    subscribePreviewBroadcast,
} from '@/lib/checkoutBuilderPreview';

defineOptions({ layout: null });

const page = usePage();

const previewViewport = ref('desktop');

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    checkout_session_token: { type: String, default: '' },
    affiliate_ref: { type: String, default: '' },
    available_payment_methods: { type: Array, default: () => [] },
    flash: { type: Object, default: () => ({}) },
    exit_popup_coupon: { type: Object, default: null },
    suggested_locale: { type: String, default: 'pt_BR' },
    suggested_currency: { type: String, default: 'BRL' },
    suggested_country_code: { type: String, default: null },
    checkout_translations: { type: Object, default: () => ({}) },
    currencies: { type: Array, default: () => [] },
    order_bumps: { type: Array, default: () => [] },
    conversion_pixels: { type: Object, default: () => ({}) },
    /** Payee code Efí para tokenização de cartão (quando método card está disponível com gateway efi). */
    card_payee_code: { type: String, default: '' },
    /** Se o gateway Efí está em homologação (token deve ser gerado com setEnvironment('sandbox')). */
    card_efi_sandbox: { type: Boolean, default: false },
    /** Publishable Key Stripe (quando método cartão está disponível com gateway stripe). */
    card_stripe_publishable_key: { type: String, default: '' },
    /** Se o gateway Stripe está em ambiente de teste. */
    card_stripe_sandbox: { type: Boolean, default: false },
    /** Se o Stripe Link está habilitado no Card Element. */
    card_stripe_link_enabled: { type: Boolean, default: true },
    card_installments_enabled: { type: Boolean, default: false },
    card_max_installments: { type: Number, default: 1 },
    /** Public Key Mercado Pago (quando método cartão está disponível com gateway mercadopago). */
    card_mercadopago_public_key: { type: String, default: '' },
    /** Se o gateway Mercado Pago está em sandbox. */
    card_mercadopago_sandbox: { type: Boolean, default: false },
    /** Chaves por gateway slug para gateways de plugin (checkout_payload_keys na definição). Ex.: { 'meu-gateway': { publishable_key: '...' } } */
    card_gateway_keys: { type: Object, default: () => ({}) },
    subscription_plan: { type: Object, default: null },
    /** Definido no servidor quando a URL traz `?preview=1` (preview no iframe do Builder). */
    checkout_builder_preview: { type: Boolean, default: false },
    plugin_checkout_extensions: { type: Array, default: () => [] },
    cajupay_pay_account_id: { type: String, default: '' },
    cajupay_public_key: { type: String, default: '' },
    parcelado_sdk_options: { type: Object, default: () => ({}) },
    pix_parcelado_rules: { type: Object, default: null },
});

const previewConfig = ref(null);
const previewConfigSeq = ref(0);
let lastAppliedPreviewSeq = 0;

const isPreviewIframe = computed(
    () => props.checkout_builder_preview || isCheckoutBuilderPreviewUrl()
);

function applyPreviewPayload(data) {
    if (!data?.config) {
        return;
    }
    const seq = Number(data.seq) || 0;
    if (seq > 0 && seq <= lastAppliedPreviewSeq) {
        return;
    }
    if (seq > 0) {
        lastAppliedPreviewSeq = seq;
    }
    previewConfig.value = structuredClone(data.config);
    previewConfigSeq.value = seq || previewConfigSeq.value + 1;
    if (data.previewViewport === 'mobile' || data.previewViewport === 'desktop') {
        previewViewport.value = data.previewViewport;
    }
}

function onPreviewMessage(event) {
    if (!isPreviewIframe.value) {
        return;
    }
    if (event.source !== window.parent) {
        return;
    }
    if (event?.data?.type !== PREVIEW_MESSAGE_TYPE) {
        return;
    }
    handlePreviewPayload(event.data);
}

/** Registrado no onMounted do iframe (garante DOM + ?preview=1). */
let previewBridgeRegistered = false;
/** @type {(() => void)|null} */
let unsubscribePreviewBroadcast = null;

function ackPreviewToParent() {
    try {
        window.parent?.postMessage({ type: PREVIEW_ACK_TYPE, at: Date.now() }, '*');
    } catch (_) {}
}

function handlePreviewPayload(payload) {
    applyPreviewPayload(payload);
    ackPreviewToParent();
}

function registerPreviewBridge() {
    if (typeof window === 'undefined' || !isPreviewIframe.value || previewBridgeRegistered) {
        return;
    }
    previewBridgeRegistered = true;
    window[PREVIEW_WINDOW_CALLBACK] = handlePreviewPayload;
    window.addEventListener('message', onPreviewMessage);
    unsubscribePreviewBroadcast = subscribePreviewBroadcast(handlePreviewPayload);
}

function unregisterPreviewBridge() {
    if (typeof window === 'undefined') {
        return;
    }
    previewBridgeRegistered = false;
    delete window[PREVIEW_WINDOW_CALLBACK];
    window.removeEventListener('message', onPreviewMessage);
    unsubscribePreviewBroadcast?.();
    unsubscribePreviewBroadcast = null;
}

/** Config ao vivo do Builder (postMessage); antes da primeira mensagem usa o config do servidor. */
const effectiveConfig = computed(() => {
    if (previewConfig.value != null) {
        return previewConfig.value;
    }
    return props.config;
});

onUnmounted(() => {
    if (initiateCheckoutDebounceTimer) {
        clearTimeout(initiateCheckoutDebounceTimer);
        initiateCheckoutDebounceTimer = null;
    }
    unregisterPreviewBridge();
    if (isPreviewIframe.value && typeof document !== 'undefined') {
        document.documentElement.classList.remove('checkout-builder-preview-mode');
    }
});

const appliedCoupon = ref(null);

const storageKey = computed(() => props.product?.checkout_slug || 'default');
const checkoutForceConfig = computed(() => props.product?.checkout_config?.checkout_force ?? null);
const checkoutCurrencyConfig = computed(() => {
    const cc = props.product?.checkout_config?.checkout_currency;
    if (cc?.mode === 'fixed' || cc?.mode === 'global') {
        return cc;
    }
    const force = props.product?.checkout_config?.checkout_force;
    if (force?.enabled && force?.currency) {
        return { mode: 'fixed', currency: force.currency };
    }

    return { mode: 'global', currency: 'BRL' };
});
const isCheckoutCurrencyFixed = computed(() => checkoutCurrencyConfig.value?.mode === 'fixed');
const customDisplayPricesByCurrency = computed(() => props.product?.custom_display_prices_by_currency ?? {});
const skipCustomDisplayPrices = computed(() => appliedCoupon.value != null);

const {
    locale,
    setLocale,
    currency: displayCurrency,
    setCurrency,
    t,
    currencies: currencyList,
    featuredCurrencies,
    otherCurrencies,
    priceInCurrency,
    formatPrice,
    supportedLocales,
} = useCheckoutLocale({
    translations: props.checkout_translations,
    currencies: props.currencies,
    suggestedLocale: props.suggested_locale,
    suggestedCurrency: props.suggested_currency,
    storageKey: props.product?.checkout_slug || 'default',
    checkoutForce: checkoutForceConfig,
    checkoutCurrency: checkoutCurrencyConfig,
    customDisplayPricesByCurrency,
    skipCustomDisplayPrices,
});

const localeLabels = { pt_BR: 'PT', en: 'EN', es: 'ES' };
const appearance = computed(() => effectiveConfig.value?.appearance ?? {});
const backgroundColor = computed(() => appearance.value.background_color || '#E3E3E3');
const primaryColor = computed(() => appearance.value.primary_color || '#0ea5e9');

function buildLegacyContentBlocks(appearanceValue) {
    const blocks = [];
    for (const url of (appearanceValue.banners ?? []).filter(Boolean)) {
        blocks.push({
            id: `legacy-hero-${blocks.length}`,
            type: 'image',
            url,
            format: 'hero',
            placement: 'main',
            link: '',
            alt: '',
        });
    }
    for (const url of (appearanceValue.side_banners ?? []).filter(Boolean)) {
        blocks.push({
            id: `legacy-side-${blocks.length}`,
            type: 'image',
            url,
            format: 'portrait',
            placement: 'sidebar',
            link: '',
            alt: '',
        });
    }
    return blocks;
}

const contentBlocks = computed(() => {
    const appearanceValue = appearance.value;
    const raw = appearanceValue.content_blocks;
    if (Array.isArray(raw)) {
        const normalize = isPreviewIframe.value
            ? normalizeContentBlocksForPreview
            : normalizeContentBlocks;

        return dedupeContentBlocks(normalize(raw));
    }

    return buildLegacyContentBlocks(appearanceValue);
});

const hasHeroBlocks = computed(() =>
    contentBlocks.value.some((b) => b.type === 'image' && b.format === 'hero' && b.placement !== 'sidebar')
);
const hasSalesPageBlocks = computed(() =>
    contentBlocks.value.some(
        (block) =>
            block.type === 'text'
            || (block.type === 'image' && block.placement === 'main' && block.format !== 'hero')
    )
);
const hasSidebarBlocks = computed(() =>
    contentBlocks.value.some((b) => b.type === 'image' && b.placement === 'sidebar')
);

const previewRootClass = computed(() => {
    if (!isPreviewIframe.value) {
        return '';
    }
    return previewViewport.value === 'mobile'
        ? 'checkout-preview--mobile'
        : 'checkout-preview--desktop';
});
const timerConfig = computed(() => effectiveConfig.value?.timer ?? {});
const salesNotificationConfig = computed(() => effectiveConfig.value?.sales_notification ?? {});

/** Sentinel quando o backend não detecta país (localhost / headers ausentes). */
const CHECKOUT_GEO_UNKNOWN = '__UNKNOWN__';

function onUserSetLocale(v) {
    try {
        if (typeof window !== 'undefined') {
            localStorage.setItem(`checkout_locale_manual_${storageKey.value}`, '1');
        }
    } catch (_) {}
    setLocale(v);
}

function onUserSetCurrency(v) {
    try {
        if (typeof window !== 'undefined') {
            localStorage.setItem(`checkout_locale_manual_${storageKey.value}`, '1');
        }
    } catch (_) {}
    setCurrency(v);
}

function applyCurrencyFromGeo(code) {
    const available = currencyList.value.map((c) => String(c.code).toUpperCase());
    const target = String(code || '').toUpperCase();
    if (available.includes(target)) {
        setCurrency(target);
    }
}

function applyGeoLocaleFromServer() {
    if (typeof window === 'undefined' || isPreviewIframe.value) {
        return;
    }
    try {
        const slug = storageKey.value;
        const manualKey = `checkout_locale_manual_${slug}`;
        const geoKey = `checkout_last_geo_country_${slug}`;
        const force = props.product?.checkout_config?.checkout_force;

        if (isCheckoutCurrencyFixed.value) {
            applyCurrencyFromGeo(checkoutCurrencyConfig.value.currency);
        }

        if (localStorage.getItem(manualKey)) {
            return;
        }

        if (force?.enabled) {
            if (force.locale) {
                setLocale(force.locale);
            }

            return;
        }

        const normalized = props.suggested_country_code
            ? String(props.suggested_country_code).toUpperCase().trim()
            : CHECKOUT_GEO_UNKNOWN;
        const last = localStorage.getItem(geoKey);
        const isBrazil = normalized === 'BR';
        if (isBrazil) {
            setLocale(props.suggested_locale || 'pt_BR');
            if (!isCheckoutCurrencyFixed.value) {
                applyCurrencyFromGeo('BRL');
            }
            if (last !== normalized) {
                localStorage.setItem(geoKey, normalized);
            }
            return;
        }
        if (last === normalized) {
            return;
        }
        setLocale(props.suggested_locale);
        if (!isCheckoutCurrencyFixed.value) {
            applyCurrencyFromGeo(props.suggested_currency);
        }
        localStorage.setItem(geoKey, normalized);
    } catch (_) {}
}

function persistSessionCountryFromClient() {
    const token = String(props.checkout_session_token || '').trim();
    if (!token) return;
    const code = String(props.suggested_country_code || 'BR').toUpperCase().trim().slice(0, 2);
    if (code.length !== 2) return;
    axios.post('/api/checkout/track-country', {
        session_token: token,
        country_code: code,
    }).catch(() => {});
}

onMounted(() => {
    registerPreviewBridge();
    if (isPreviewIframe.value && typeof document !== 'undefined') {
        document.documentElement.classList.add('checkout-builder-preview-mode');
    }
    applyGeoLocaleFromServer();
    persistSessionCountryFromClient();
    registerPluginCheckoutComponents(page.props.plugin_ui, props.available_payment_methods);
});

if (typeof window !== 'undefined' && (props.checkout_builder_preview || isCheckoutBuilderPreviewUrl())) {
    registerPreviewBridge();
}

watch(
    () => [props.suggested_country_code, props.suggested_currency],
    () => {
        applyGeoLocaleFromServer();
    }
);

const seo = computed(() => effectiveConfig.value?.seo ?? {});
/** Título da aba do navegador e para compartilhamento (Open Graph). Vem do "Título para compartilhamento" no Builder. */
const pageTitle = computed(() => (seo.value.title || '').trim() || props.product?.name || 'Checkout');

watch(pageTitle, (title) => {
    if (typeof document !== 'undefined' && title) {
        document.title = title;
    }
}, { immediate: true });

const pageDescription = computed(() => seo.value.description || props.product?.description || '');
const ogImage = computed(() => {
    const url = seo.value.og_image || props.product?.image_url;
    if (!url) return null;
    if (typeof window !== 'undefined' && url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
});

/** URL absoluta da imagem do produto (LCP) para preload no HTML; mesma lógica que ogImage para paths relativos. */
const lcpPreloadImageUrl = computed(() => {
    const url = props.product?.image_url;
    if (!url) return null;
    if (typeof window !== 'undefined' && url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
});
const faviconHref = computed(() => {
    const custom = seo.value.favicon?.trim();
    if (custom) return custom;
    return page.props.public_branding?.favicon_url || '/favicon.ico';
});

const productImageUrlForNotification = computed(() => {
    const url = props.product?.image_url;
    if (!url) return '';
    if (typeof window !== 'undefined' && url.startsWith('/')) {
        return `${window.location.origin}${url}`;
    }
    return url;
});

const exitPopupAcceptedCoupon = ref('');
function onExitPopupAccept(code) {
    exitPopupAcceptedCoupon.value = code || '';
}

function onCouponApplied(data) {
    appliedCoupon.value = data;
}
function onCouponCleared() {
    appliedCoupon.value = null;
}

const selectedOrderBumpIds = ref([]);
const selectedOrderBumpsList = computed(() => {
    const ids = new Set(selectedOrderBumpIds.value);
    return (props.order_bumps || []).filter((b) => ids.has(b.id));
});
const orderBumpsTotalBrl = computed(() =>
    selectedOrderBumpsList.value.reduce((sum, b) => sum + (Number(b.amount_brl) || 0), 0)
);

const checkoutTotalBrl = computed(() => {
    const base = appliedCoupon.value?.final_price ?? props.product?.price_brl ?? props.product?.price ?? 0;
    return Number(base) + orderBumpsTotalBrl.value;
});

const checkoutTotalInCurrency = computed(() => {
    const base = appliedCoupon.value?.final_price ?? props.product?.price_brl ?? props.product?.price ?? 0;
    const main = priceInCurrency(Number(base));
    const bumps = selectedOrderBumpsList.value.reduce(
        (sum, b) => sum + priceInCurrency(Number(b.amount_brl) || 0),
        0
    );
    return Math.round((main + bumps) * 100) / 100;
});

/** Preço da linha principal (sem order bumps), para contents do pixel Meta. */
const mainLinePriceBrl = computed(() => {
    const c = appliedCoupon.value;
    if (c && typeof c.final_price === 'number') {
        return Number(c.final_price);
    }
    return Number(props.product?.price_brl ?? props.product?.price ?? 0);
});

const conversionPixels = computed(() => props.conversion_pixels || {});

const pixelTrackingContext = computed(() => ({
    checkout_slug: props.product?.checkout_slug ?? '',
    product_name: props.product?.name ?? '',
    page_path: typeof window !== 'undefined' ? window.location.pathname : '',
}));

const conversionPixelsRef = ref(null);
let initiateCheckoutFiredForLoad = false;
let browserPixelsFingerprintAtFire = '';
const pixelsReady = ref(false);
const pendingPurchase = ref(null);
/** Evita InitiateCheckout duplicado com o mesmo valor (Meta). */
let lastInitiateCheckoutTotal = null;
let initiateCheckoutDebounceTimer = null;

const pixelCurrency = computed(() =>
    typeof displayCurrency.value === 'string' && displayCurrency.value.trim()
        ? displayCurrency.value.trim().toUpperCase()
        : 'BRL'
);

function pixelCheckoutTotal() {
    const code = pixelCurrency.value;
    if (code !== 'BRL') {
        const foreign = Math.round((Number(checkoutTotalInCurrency.value) || 0) * 100) / 100;
        if (foreign > 0) {
            return foreign;
        }
    }
    return Math.round((Number(checkoutTotalBrl.value) || 0) * 100) / 100;
}

function linePriceInPixelCurrency(amountBrl) {
    const code = pixelCurrency.value;
    const brl = Number(amountBrl) || 0;
    if (code === 'BRL') {
        return Math.round(brl * 100) / 100;
    }
    return Math.round((Number(priceInCurrency(brl)) || 0) * 100) / 100;
}

function pixelCheckoutItems() {
    const items = [];
    const mainId = String(props.product?.id ?? props.product?.checkout_slug ?? '').trim();
    const mainName = String(props.product?.name ?? '').trim();
    const mainPrice = linePriceInPixelCurrency(mainLinePriceBrl.value);
    if (mainId) {
        items.push({
            item_id: mainId,
            item_name: mainName || undefined,
            price: mainPrice,
            quantity: 1,
        });
    }
    selectedOrderBumpsList.value.forEach((b) => {
        const id = String(b.target_product_id ?? b.id ?? '').trim();
        if (!id) return;
        items.push({
            item_id: id,
            item_name: String(b.title ?? b.target_name ?? '').trim() || undefined,
            price: linePriceInPixelCurrency(Number(b.amount_brl) || 0),
            quantity: 1,
        });
    });
    return items;
}

function browserPixelsFingerprint(pixels) {
    try {
        return JSON.stringify({
            meta: (pixels?.meta?.entries ?? []).length,
            google_ads: (pixels?.google_ads?.entries ?? []).length,
            google_analytics: (pixels?.google_analytics?.entries ?? []).length,
            tiktok: (pixels?.tiktok?.entries ?? []).length,
        });
    } catch {
        return '';
    }
}

function pixelEventExtras() {
    return { items: pixelCheckoutItems() };
}

async function fireInitiateCheckoutIfNeeded() {
    const api = conversionPixelsRef.value;
    if (!pixelsReady.value || !api?.fireInitiateCheckout) return;
    const total = pixelCheckoutTotal();
    if (total <= 0) return;
    if (
        lastInitiateCheckoutTotal !== null &&
        Math.abs(lastInitiateCheckoutTotal - total) < 0.01
    ) {
        return;
    }
    if (pixelsNeedAnyBrowserSdk(conversionPixels.value)) {
        await waitForPixelSdks(conversionPixels.value, 5000);
    }
    lastInitiateCheckoutTotal = total;
    api.fireInitiateCheckout(total, pixelCurrency.value, pixelEventExtras());
}

async function tryFireInitiateCheckoutOnReady(force = false) {
    const fp = browserPixelsFingerprint(conversionPixels.value);
    const hasBrowserSdk = pixelsNeedAnyBrowserSdk(conversionPixels.value);
    if (!force && initiateCheckoutFiredForLoad && (!hasBrowserSdk || fp === browserPixelsFingerprintAtFire)) {
        return;
    }
    if (!pixelsReady.value) return;
    const api = conversionPixelsRef.value;
    if (!api?.fireInitiateCheckout) return;
    initiateCheckoutFiredForLoad = true;
    browserPixelsFingerprintAtFire = fp;
    if (!force) {
        lastInitiateCheckoutTotal = null;
    }
    await fireInitiateCheckoutIfNeeded();
}

async function tryFirePendingPurchase() {
    const api = conversionPixelsRef.value;
    if (!api?.firePurchase || !pendingPurchase.value) return;
    const p = pendingPurchase.value;
    const fired = await firePurchaseWhenReady(api, {
        order_id: p.order_id,
        amount: p.amount,
        currency: p.currency,
        meta_event_id: p.meta_event_id,
        purchase_contents: p.purchase_contents,
    }, { pixels: conversionPixels.value });
    if (fired) {
        pendingPurchase.value = null;
    }
}

function onConversionPixelsReady() {
    pixelsReady.value = true;
    const api = conversionPixelsRef.value;
    if (api?.firePageView) {
        api.firePageView(pixelCheckoutTotal(), pixelCurrency.value, pixelEventExtras());
    }
    if (pendingPurchase.value) {
        tryFirePendingPurchase();
    }
    tryFireInitiateCheckoutOnReady();
    tryFirePendingPurchase();
}

watch(
    () => browserPixelsFingerprint(conversionPixels.value),
    (fp) => {
        if (!pixelsReady.value) return;
        if (!pixelsNeedAnyBrowserSdk(conversionPixels.value)) return;
        if (fp === browserPixelsFingerprintAtFire) return;
        lastInitiateCheckoutTotal = null;
        tryFireInitiateCheckoutOnReady(true);
    },
);

watch([checkoutTotalBrl, checkoutTotalInCurrency, pixelCurrency], () => {
    if (!initiateCheckoutFiredForLoad || !pixelsReady.value) return;
    if (initiateCheckoutDebounceTimer) clearTimeout(initiateCheckoutDebounceTimer);
    initiateCheckoutDebounceTimer = setTimeout(() => {
        initiateCheckoutDebounceTimer = null;
        fireInitiateCheckoutIfNeeded();
    }, 500);
});

function visitPostCheckoutUrl(url) {
    if (typeof window === 'undefined' || !url || typeof url !== 'string') return;
    const trimmed = url.trim();
    if (trimmed === '') return;
    try {
        const abs = new URL(trimmed, window.location.href);
        if (abs.origin !== window.location.origin) {
            window.location.assign(abs.href);
            return;
        }
    } catch (_) {
        window.location.assign(trimmed);
        return;
    }
    router.visit(trimmed);
}

async function onPaymentApproved(payload) {
    if (!payload || typeof payload !== 'object') return;
    const orderId = payload.order_id;
    if (!orderId) return;
    const purchasePayload = {
        order_id: orderId,
        amount: Number(payload.amount) || 0,
        currency: typeof payload.currency === 'string' && payload.currency ? payload.currency : 'BRL',
        meta_event_id: typeof payload.meta_event_id === 'string' ? payload.meta_event_id : `getfy_purchase_${orderId}`,
        purchase_contents: Array.isArray(payload.purchase_contents) ? payload.purchase_contents : [],
    };
    pendingPurchase.value = purchasePayload;
    await firePurchaseWhenReady(conversionPixelsRef.value, purchasePayload, {
        maxWaitMs: 3000,
        pixels: conversionPixels.value,
    });
    pendingPurchase.value = null;

    const redirectUrl = typeof payload.redirect_url === 'string' ? payload.redirect_url.trim() : '';
    if (redirectUrl) {
        setTimeout(() => visitPostCheckoutUrl(redirectUrl), 450);
    }
}

const advancedForCustomCode = computed(() => effectiveConfig.value?.advanced ?? {});
useCheckoutCustomCode(advancedForCustomCode);

const customBodyStartHtml = computed(() => advancedForCustomCode.value?.custom_body_start_html ?? '');
const customBodyEndHtml = computed(() => advancedForCustomCode.value?.custom_body_end_html ?? '');
const hasCustomBodyStart = computed(() => String(customBodyStartHtml.value).trim() !== '');
const hasCustomBodyEnd = computed(() => String(customBodyEndHtml.value).trim() !== '');
</script>

<template>
    <ConversionPixels
        ref="conversionPixelsRef"
        :pixels="conversionPixels"
        :tracking-context="pixelTrackingContext"
        @ready="onConversionPixelsReady"
    />
    <Head>
        <title>{{ pageTitle }}</title>
        <meta v-if="pageDescription" name="description" :content="pageDescription" />
        <meta property="og:type" content="website" />
        <meta property="og:title" :content="pageTitle" />
        <meta v-if="pageDescription" property="og:description" :content="pageDescription" />
        <meta v-if="ogImage" property="og:image" :content="ogImage" />
        <meta v-if="ogImage" property="og:image:secure_url" :content="ogImage" />
        <meta v-if="ogImage" name="twitter:card" content="summary_large_image" />
        <meta v-if="ogImage" name="twitter:image" :content="ogImage" />
        <link
            v-if="lcpPreloadImageUrl"
            rel="preload"
            as="image"
            :href="lcpPreloadImageUrl"
            fetchpriority="high"
        />
        <link rel="icon" :href="faviconHref" />
    </Head>
    <div
        id="getfy-checkout-root"
        data-checkout="page"
        class="min-h-screen transition-colors duration-300"
        :class="previewRootClass"
        :style="{ backgroundColor }"
    >
        <CheckoutTimer :config="timerConfig" :storage-key="storageKey" :t="t" />

        <div
            v-if="hasCustomBodyStart"
            class="getfy-checkout-custom-body-start"
            data-checkout="custom-html-body-start"
            v-html="customBodyStartHtml"
        />

        <div class="mx-auto max-w-6xl px-4 py-6 sm:px-6 sm:py-8 lg:py-10" data-checkout="layout-inner">
            <!-- Flash -->
            <div
                v-if="flash?.error"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-red-200/80 bg-red-50/95 px-4 py-3.5 text-sm font-medium text-red-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-error"
                role="alert"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-red-100 text-red-600">
                    <AlertCircle class="h-4 w-4" />
                </span>
                {{ flash.error }}
            </div>
            <div
                v-if="flash?.success"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-emerald-200/80 bg-emerald-50/95 px-4 py-3.5 text-sm font-medium text-emerald-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-success"
                role="status"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-600">
                    <CheckCircle2 class="h-4 w-4" />
                </span>
                {{ flash.success }}
            </div>
            <div
                v-if="flash?.info"
                class="mb-6 flex items-center gap-3 rounded-2xl border border-sky-200/80 bg-sky-50/95 px-4 py-3.5 text-sm font-medium text-sky-800 shadow-sm backdrop-blur sm:px-5"
                data-checkout="flash-info"
                role="status"
            >
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-sky-100 text-sky-600">
                    <CheckCircle2 class="h-4 w-4" />
                </span>
                {{ flash.info }}
            </div>

            <CheckoutBanners
                v-if="hasHeroBlocks"
                :key="`preview-hero-${previewConfigSeq}`"
                :blocks="contentBlocks"
                placement="top"
            />
            <CheckoutSalesPage
                v-if="hasSalesPageBlocks"
                :key="`preview-sales-${previewConfigSeq}`"
                :blocks="contentBlocks"
                placement="main"
                exclude-hero
                class="mb-6"
            />
            <CheckoutYoutube v-if="(effectiveConfig?.youtube_position ?? 'top') !== 'bottom'" :url="effectiveConfig?.youtube_url" />

            <div class="checkout-layout-columns flex flex-col gap-8 lg:flex-row lg:gap-10" data-checkout="layout-columns">
                <!-- Coluna principal -->
                <div class="w-full lg:w-2/3" data-checkout="column-primary">
                    <div
                        class="overflow-hidden rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur sm:p-8"
                        data-checkout="card-main"
                    >
                        <CheckoutSummary
                            :product="product"
                            :subscription-plan="subscription_plan"
                            :config="effectiveConfig"
                            :primary-color="primaryColor"
                            :applied-coupon="appliedCoupon"
                            :t="t"
                            :display-currency="displayCurrency"
                            :price-in-currency="priceInCurrency"
                            :format-price="formatPrice"
                            :locale="locale"
                            :supported-locales="supportedLocales"
                            :locale-labels="localeLabels"
                            @set-locale="onUserSetLocale"
                        />
                        <hr class="my-8 border-0 border-t border-gray-100" data-checkout="divider-summary-form" />
                        <CheckoutForm
                            :product-id="product.id"
                            :product-offer-id="product.product_offer_id ?? null"
                            :subscription-plan-id="product.subscription_plan_id ?? null"
                            :checkout-session-token="checkout_session_token || ''"
                            :affiliate-ref="affiliate_ref || ''"
                            :order-bumps="order_bumps || []"
                            v-model:order-bump-ids="selectedOrderBumpIds"
                            :primary-color="primaryColor"
                            :config="effectiveConfig"
                            :available-payment-methods="available_payment_methods"
                            :prefill-coupon="exitPopupAcceptedCoupon"
                            :t="t"
                            :display-currency="displayCurrency"
                            :checkout-locale="locale"
                            :format-price="formatPrice"
                            :suggested-country-code="props.suggested_country_code"
                            :locale-storage-key="storageKey"
                            :card-payee-code="card_payee_code || ''"
                            :card-efi-sandbox="card_efi_sandbox"
                            :card-stripe-publishable-key="card_stripe_publishable_key || ''"
                            :card-stripe-sandbox="card_stripe_sandbox"
                            :card-stripe-link-enabled="card_stripe_link_enabled"
                            :card-installments-enabled="card_installments_enabled"
                            :card-max-installments="card_max_installments || 1"
                            :card-mercadopago-public-key="card_mercadopago_public_key || ''"
                            :card-mercadopago-sandbox="card_mercadopago_sandbox"
                            :card-gateway-keys="card_gateway_keys || {}"
                            :checkout-total-brl="checkoutTotalBrl"
                            :checkout-total-in-currency="checkoutTotalInCurrency"
                            :main-line-price-brl="mainLinePriceBrl"
                            :currency-list="currencyList"
                            :featured-currencies="featuredCurrencies"
                            :other-currencies="otherCurrencies"
                            :plugin-checkout-extensions="plugin_checkout_extensions"
                            :product-name="product.name || ''"
                            :cajupay-pay-account-id="cajupay_pay_account_id || ''"
                            :parcelado-sdk-options="parcelado_sdk_options || {}"
                            :price-in-currency="priceInCurrency"
                            @coupon-applied="onCouponApplied"
                            @coupon-cleared="onCouponCleared"
                            @payment-approved="onPaymentApproved"
                            @set-currency="onUserSetCurrency"
                        />
                    </div>
                </div>

                <!-- Coluna lateral: resumo + banners -->
                <CheckoutSidebar
                    :product="product"
                    :subscription-plan="subscription_plan"
                    :config="effectiveConfig"
                    :applied-coupon="appliedCoupon"
                    :selected-order-bumps="selectedOrderBumpsList"
                    :order-bumps-total-brl="orderBumpsTotalBrl"
                    :t="t"
                    :display-currency="displayCurrency"
                    :price-in-currency="priceInCurrency"
                    :format-price="formatPrice"
                />
            </div>

            <!-- Banners laterais: no mobile aparecem no final da página -->
            <div v-if="hasSidebarBlocks" class="checkout-sidebar-mobile mt-8 lg:hidden" data-checkout="banners-side-mobile">
                <CheckoutSalesPage :blocks="contentBlocks" placement="sidebar" />
            </div>

            <!-- Vídeo YouTube em baixo da página (quando a posição for "bottom") -->
            <CheckoutYoutube v-if="(effectiveConfig?.youtube_position ?? 'top') === 'bottom'" :url="effectiveConfig?.youtube_url" class="mt-8" />
        </div>

        <div
            v-if="hasCustomBodyEnd"
            class="getfy-checkout-custom-body-end"
            data-checkout="custom-html-body-end"
            v-html="customBodyEndHtml"
        />

        <SalesNotification
            :config="salesNotificationConfig"
            :product-name="product?.name"
            :product-image-url="productImageUrlForNotification"
        />

        <SupportButton :config="effectiveConfig?.support_button" :primary-color="primaryColor" />
        <ExitPopup
            :config="effectiveConfig"
            :primary-color="primaryColor"
            :exit-popup-coupon="exit_popup_coupon"
            :storage-key="storageKey"
            :t="t"
            @accept="onExitPopupAccept"
        />
    </div>
</template>

<style scoped>
.checkout-preview--mobile .checkout-layout-columns {
    flex-direction: column !important;
}

.checkout-preview--mobile .checkout-sidebar-desktop {
    display: none !important;
}

.checkout-preview--mobile .checkout-sidebar-mobile {
    display: block !important;
}

.checkout-preview--desktop .checkout-layout-columns {
    flex-direction: row !important;
}

.checkout-preview--desktop [data-checkout="column-primary"] {
    width: 66.666667% !important;
}

.checkout-preview--desktop [data-checkout="sidebar"] {
    display: block !important;
    width: 33.333333% !important;
}

.checkout-preview--desktop .checkout-sidebar-desktop {
    display: block !important;
}

.checkout-preview--desktop .checkout-sidebar-mobile {
    display: none !important;
}

.checkout-preview--desktop [data-checkout="layout-inner"],
.checkout-preview--mobile [data-checkout="layout-inner"] {
    padding-top: 0.75rem !important;
    padding-bottom: 0.75rem !important;
}
</style>

<style>
html.checkout-builder-preview-mode,
html.checkout-builder-preview-mode body {
    height: auto;
    min-height: 100%;
    overflow: auto;
}
</style>
