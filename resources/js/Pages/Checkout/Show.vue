<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { Head } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2 } from 'lucide-vue-next';
import { useCheckoutLocale } from '@/composables/useCheckoutLocale';
import { useCheckoutCustomCode } from '@/composables/useCheckoutCustomCode';
import CheckoutTimer from '@/components/checkout/CheckoutTimer.vue';
import CheckoutBanners from '@/components/checkout/CheckoutBanners.vue';
import CheckoutYoutube from '@/components/checkout/CheckoutYoutube.vue';
import CheckoutSummary from '@/components/checkout/CheckoutSummary.vue';
import CheckoutForm from '@/components/checkout/CheckoutForm.vue';
import CheckoutSidebar from '@/components/checkout/CheckoutSidebar.vue';
import SalesNotification from '@/components/checkout/SalesNotification.vue';
import SupportButton from '@/components/checkout/SupportButton.vue';
import ExitPopup from '@/components/checkout/ExitPopup.vue';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';

defineOptions({ layout: null });

const PREVIEW_MESSAGE_TYPE = 'checkout-builder-preview-config';

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    checkout_session_token: { type: String, default: '' },
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
});

const previewConfig = ref(null);

function onPreviewMessage(event) {
    if (!props.checkout_builder_preview) return;
    if (event.origin !== window.location.origin) return;
    if (event?.data?.type !== PREVIEW_MESSAGE_TYPE || event.data.config == null) return;
    previewConfig.value = event.data.config;
}

/** Config ao vivo do Builder (postMessage); antes da primeira mensagem usa o config do servidor. */
const effectiveConfig = computed(() => {
    if (previewConfig.value != null) {
        return previewConfig.value;
    }
    return props.config;
});

/** Listener no setup (não só no onMounted) para não perder postMessage se o parent disparar no @load do iframe antes do mount. */
if (typeof window !== 'undefined' && props.checkout_builder_preview) {
    window.addEventListener('message', onPreviewMessage);
}
onUnmounted(() => {
    if (typeof window !== 'undefined' && props.checkout_builder_preview) {
        window.removeEventListener('message', onPreviewMessage);
    }
});

const {
    locale,
    setLocale,
    currency: displayCurrency,
    setCurrency,
    t,
    currencies: currencyList,
    priceInCurrency,
    formatPrice,
    supportedLocales,
} = useCheckoutLocale({
    translations: props.checkout_translations,
    currencies: props.currencies,
    suggestedLocale: props.suggested_locale,
    suggestedCurrency: props.suggested_currency,
    storageKey: props.product?.checkout_slug || 'default',
});

const localeLabels = { pt_BR: 'PT', en: 'EN', es: 'ES' };
const appearance = computed(() => effectiveConfig.value?.appearance ?? {});
const backgroundColor = computed(() => appearance.value.background_color || '#E3E3E3');
const primaryColor = computed(() => appearance.value.primary_color || '#0ea5e9');
const banners = computed(() => appearance.value.banners ?? []);
const sideBannersFiltered = computed(() => (appearance.value.side_banners ?? []).filter(Boolean));
const timerConfig = computed(() => effectiveConfig.value?.timer ?? {});
const salesNotificationConfig = computed(() => effectiveConfig.value?.sales_notification ?? {});
const storageKey = computed(() => props.product?.checkout_slug || 'default');

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
const faviconHref = computed(() => seo.value.favicon || '/favicon.ico');

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

const appliedCoupon = ref(null);
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

const conversionPixels = computed(() => props.conversion_pixels || {});

const advancedForCustomCode = computed(() => effectiveConfig.value?.advanced ?? {});
useCheckoutCustomCode(advancedForCustomCode);

const customBodyStartHtml = computed(() => advancedForCustomCode.value?.custom_body_start_html ?? '');
const customBodyEndHtml = computed(() => advancedForCustomCode.value?.custom_body_end_html ?? '');
const hasCustomBodyStart = computed(() => String(customBodyStartHtml.value).trim() !== '');
const hasCustomBodyEnd = computed(() => String(customBodyEndHtml.value).trim() !== '');
</script>

<template>
    <ConversionPixels :pixels="conversionPixels" />
    <Head>
        <title>{{ pageTitle }}</title>
        <meta v-if="pageDescription" name="description" :content="pageDescription" />
        <meta property="og:title" :content="pageTitle" />
        <meta v-if="pageDescription" property="og:description" :content="pageDescription" />
        <meta v-if="ogImage" property="og:image" :content="ogImage" />
        <link rel="icon" :href="faviconHref" />
    </Head>
    <div
        id="getfy-checkout-root"
        data-checkout="page"
        class="min-h-screen transition-colors duration-300"
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

            <CheckoutBanners v-if="banners.length" :urls="banners" />
            <CheckoutYoutube v-if="(effectiveConfig?.youtube_position ?? 'top') !== 'bottom'" :url="effectiveConfig?.youtube_url" />

            <div class="flex flex-col gap-8 lg:flex-row lg:gap-10" data-checkout="layout-columns">
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
                            :currency-list="currencyList"
                            :locale-labels="localeLabels"
                            @set-locale="setLocale"
                            @set-currency="setCurrency"
                        />
                        <hr class="my-8 border-0 border-t border-gray-100" data-checkout="divider-summary-form" />
                        <CheckoutForm
                            :product-id="product.id"
                            :product-offer-id="product.product_offer_id ?? null"
                            :subscription-plan-id="product.subscription_plan_id ?? null"
                            :checkout-session-token="checkout_session_token || ''"
                            :order-bumps="order_bumps || []"
                            v-model:order-bump-ids="selectedOrderBumpIds"
                            :primary-color="primaryColor"
                            :config="effectiveConfig"
                            :available-payment-methods="available_payment_methods"
                            :prefill-coupon="exitPopupAcceptedCoupon"
                            :t="t"
                            :display-currency="displayCurrency"
                            :format-price="formatPrice"
                            :suggested-country-code="props.suggested_country_code"
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
                            @coupon-applied="onCouponApplied"
                            @coupon-cleared="onCouponCleared"
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
            <div
                v-if="sideBannersFiltered.length"
                class="mt-8 space-y-4 lg:hidden"
                data-checkout="banners-side-mobile"
            >
                <img
                    v-for="(url, i) in sideBannersFiltered"
                    :key="i"
                    :src="url"
                    alt="Banner"
                    class="w-full rounded-2xl object-cover shadow-lg"
                    @error="(e) => e?.target && (e.target.style.display = 'none')"
                />
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
