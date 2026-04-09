<script setup>
import { computed } from 'vue';
import CheckoutBanners from './CheckoutBanners.vue';
import CheckoutReviews from './CheckoutReviews.vue';
import { Receipt, ShieldCheck } from 'lucide-vue-next';

const INTERVAL_LABELS = {
    weekly: 'Semanal',
    monthly: 'Mensal',
    quarterly: 'Trimestral',
    semi_annual: 'Semestral',
    annual: 'Anual',
    lifetime: 'Vitalício',
};
function intervalLabel(interval) {
    return INTERVAL_LABELS[interval] || interval || '';
}

const props = defineProps({
    product: { type: Object, required: true },
    subscriptionPlan: { type: Object, default: null },
    config: { type: Object, default: () => ({}) },
    /** Desconto aplicado pelo cupom: { discount_amount, final_price } */
    appliedCoupon: { type: Object, default: null },
    /** Order bumps selecionados (array de { id, title, amount_brl }) */
    selectedOrderBumps: { type: Array, default: () => [] },
    /** Soma dos valores em BRL dos bumps selecionados */
    orderBumpsTotalBrl: { type: Number, default: 0 },
    t: { type: Function, default: (k) => k },
    displayCurrency: { type: String, default: 'BRL' },
    priceInCurrency: { type: Function, default: (v) => v },
    formatPrice: { type: Function, default: (v, c) => String(v) },
});

const appearance = props.config?.appearance ?? {};
const primaryColor = appearance.primary_color || '#7427F1';
const sideBanners = appearance.side_banners ?? [];
const footerConfig = computed(() => props.config?.footer ?? {});
const footerEnabled = computed(() => footerConfig.value?.enabled === true);
const footerLogoUrl = computed(() => String(footerConfig.value?.logo_url ?? '').trim());
const footerText = computed(() => String(footerConfig.value?.text ?? '').trim());
const footerSupportEmail = computed(() => String(footerConfig.value?.support_email ?? '').trim());
const showFooterCustom = computed(
    () => footerEnabled.value && (footerLogoUrl.value !== '' || footerText.value !== '' || footerSupportEmail.value !== '')
);

const mainProductPriceBrl = computed(() => {
    const applied = props.appliedCoupon;
    if (applied != null && applied.final_price != null) return Number(applied.final_price);
    return Number(props.product?.price_brl ?? props.product?.price ?? 0);
});
const totalPriceBrl = computed(() => mainProductPriceBrl.value + (Number(props.orderBumpsTotalBrl) || 0));
const totalPrice = computed(() => props.priceInCurrency(totalPriceBrl.value));
const discountAmountBrl = computed(() =>
    props.appliedCoupon?.discount_amount != null ? Number(props.appliedCoupon.discount_amount) : 0
);
const discountAmount = computed(() => props.priceInCurrency(discountAmountBrl.value));
const showProductOriginalPrice = computed(() => discountAmountBrl.value > 0);
const productPriceBrl = computed(() => Number(props.product?.price_brl ?? props.product?.price ?? 0));
const productPriceDisplay = computed(() => props.priceInCurrency(productPriceBrl.value));
</script>

<template>
    <aside
        class="w-full space-y-6 lg:sticky lg:top-8 lg:block lg:w-1/3 lg:self-start"
        data-checkout="sidebar"
        data-checkout-column="secondary"
    >
        <div
            class="overflow-hidden rounded-3xl border border-white/20 bg-white/95 p-6 shadow-xl shadow-black/5 backdrop-blur sm:p-7"
            data-id="final_summary"
            data-checkout="sidebar-summary-card"
        >
            <div class="flex items-center gap-3 pb-4" data-checkout="sidebar-summary-header">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-600">
                    <Receipt class="h-5 w-5" />
                </span>
                <h2 class="text-lg font-bold tracking-tight text-gray-900">{{ t('checkout.summary_title') }}</h2>
            </div>
            <div class="space-y-3" data-checkout="sidebar-line-items">
                <div class="flex justify-between gap-3 text-sm">
                    <span class="truncate font-medium text-gray-700">{{ product.name }}</span>
                    <span class="shrink-0 font-semibold text-gray-900">
                        <span v-if="showProductOriginalPrice" class="text-gray-400 line-through mr-1">{{ formatPrice(productPriceDisplay, displayCurrency) }}</span>
                        {{ formatPrice(priceInCurrency(mainProductPriceBrl), displayCurrency) }}<span v-if="subscriptionPlan?.interval" class="text-xs font-medium text-gray-500 ml-1">{{ intervalLabel(subscriptionPlan.interval) }}</span>
                    </span>
                </div>
                <template v-for="bump in selectedOrderBumps" :key="bump.id">
                    <div class="flex justify-between gap-3 text-sm">
                        <span class="truncate font-medium text-gray-600">+ {{ bump.title }}</span>
                        <span class="shrink-0 font-semibold text-gray-900">{{ formatPrice(priceInCurrency(bump.amount_brl), displayCurrency) }}</span>
                    </div>
                </template>
                <div v-if="discountAmountBrl > 0" class="flex justify-between gap-3 text-sm text-emerald-600">
                    <span class="font-medium">{{ t('checkout.discount_coupon') }}</span>
                    <span class="font-semibold">-{{ formatPrice(discountAmount, displayCurrency) }}</span>
                </div>
            </div>
            <hr class="my-5 border-0 border-t border-gray-100" />
            <div class="flex items-center justify-between" data-checkout="sidebar-total">
                <span class="text-base font-bold text-gray-900">{{ t('checkout.total_a_pagar') || t('checkout.total') }}</span>
                <span class="text-2xl font-bold tracking-tight" :style="{ color: primaryColor }">
                    {{ formatPrice(totalPrice, displayCurrency) }}<span v-if="subscriptionPlan?.interval" class="text-sm font-medium text-gray-500 ml-1 align-baseline">{{ intervalLabel(subscriptionPlan.interval) }}</span>
                </span>
            </div>
            <div
                class="mt-5 flex items-center justify-center gap-2 rounded-xl bg-gray-50 py-3 text-sm font-medium text-gray-600"
                data-checkout="sidebar-trust-badge"
            >
                <ShieldCheck class="h-4 w-4 text-emerald-500" aria-hidden="true" />
                {{ t('checkout.secure_purchase') }}
            </div>
            <!-- Mobile: reCAPTCHA e copyright (no desktop já aparecem no rodapé do formulário) -->
            <div class="mt-5 border-t border-gray-100 pt-4 lg:hidden" data-checkout="sidebar-footer-mobile">
                <div v-if="showFooterCustom" class="mb-4 text-center">
                    <img
                        v-if="footerLogoUrl"
                        :src="footerLogoUrl"
                        alt=""
                        class="mx-auto h-8 w-auto object-contain"
                        loading="lazy"
                    />
                    <p v-if="footerText" class="mt-2 text-sm font-medium text-gray-700">
                        {{ footerText }}
                    </p>
                    <a
                        v-if="footerSupportEmail"
                        :href="`mailto:${footerSupportEmail}`"
                        class="mt-1 inline-block text-sm font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-gray-800"
                    >
                        {{ footerSupportEmail }}
                    </a>
                </div>
                <p class="text-center text-xs text-gray-400">
                    Este site é protegido pelo reCAPTCHA do Google
                </p>
                <p class="mt-2 text-center text-xs text-gray-400">
                    Copyright © {{ new Date().getFullYear() }}. Todos os direitos reservados.
                </p>
            </div>
        </div>
        <!-- Banners laterais: apenas no desktop (abaixo do resumo). No mobile aparecem no final da página. -->
        <div v-if="sideBanners.filter(Boolean).length" class="hidden lg:block" data-checkout="sidebar-banners-wrap">
            <CheckoutBanners
                :urls="sideBanners"
                placement="side"
                class-img="w-full h-auto object-cover rounded-2xl shadow-lg"
            />
        </div>
        <CheckoutReviews
            v-if="(config?.reviews ?? []).length"
            :reviews="config.reviews"
            :primary-color="primaryColor"
        />
    </aside>
</template>
