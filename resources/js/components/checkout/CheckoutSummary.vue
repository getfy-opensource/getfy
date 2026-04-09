<script setup>
import { ref, computed } from 'vue';
import { ChevronDown, ChevronUp, Tag, Globe, Banknote, Check } from 'lucide-vue-next';
import CheckoutDropdown from './CheckoutDropdown.vue';

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
    primaryColor: { type: String, default: '#7427F1' },
    /** Desconto aplicado pelo cupom: { discount_amount, final_price } */
    appliedCoupon: { type: Object, default: null },
    t: { type: Function, default: (k) => k },
    displayCurrency: { type: String, default: 'BRL' },
    priceInCurrency: { type: Function, default: (v) => v },
    formatPrice: { type: Function, default: (v, c) => String(v) },
    locale: { type: String, default: 'pt_BR' },
    supportedLocales: { type: Array, default: () => ['pt_BR', 'en', 'es'] },
    currencyList: { type: Array, default: () => [] },
    localeLabels: { type: Object, default: () => ({ pt_BR: 'PT', en: 'EN', es: 'ES' }) },
});

const emit = defineEmits(['set-locale', 'set-currency']);

const summary = computed(() => props.config?.summary ?? {});
const showDescription = computed(() => summary.value.show_description !== false);
const previousPrice = computed(() => {
    const v = summary.value.previous_price;
    return v != null && v !== '' ? Number(v) : null;
});
const discountText = computed(() => summary.value.discount_text || '');

const priceToShowBrl = computed(() => {
    const applied = props.appliedCoupon;
    if (applied != null && applied.final_price != null) return Number(applied.final_price);
    const p = props.product?.price_brl ?? props.product?.price ?? 0;
    return Number(p);
});
const priceToShow = computed(() => props.priceInCurrency(priceToShowBrl.value));
const showOriginalPriceStrikethrough = computed(() => {
    if (props.appliedCoupon != null && props.product?.price != null) return true;
    return previousPrice.value != null;
});
const originalPriceForDisplayBrl = computed(() => {
    if (props.appliedCoupon != null && props.product?.price != null) return Number(props.product.price);
    return previousPrice.value;
});
const originalPriceForDisplay = computed(() =>
    originalPriceForDisplayBrl.value != null ? props.priceInCurrency(originalPriceForDisplayBrl.value) : null
);
const couponDiscountAmountBrl = computed(() =>
    props.appliedCoupon?.discount_amount != null ? Number(props.appliedCoupon.discount_amount) : 0
);
const couponDiscountAmount = computed(() => props.priceInCurrency(couponDiscountAmountBrl.value));
const description = computed(() => props.product?.description ?? '');
const shortDesc = computed(() => {
    const d = description.value.replace(/<[^>]+>/g, '').trim();
    return d.length > 120 ? d.slice(0, 120) + '…' : d;
});
const fullDesc = computed(() => description.value.replace(/<[^>]+>/g, '').trim());
const showVerMais = computed(() => fullDesc.value.length > 120);

const expanded = ref(false);
const displayDesc = computed(() => (expanded.value ? fullDesc.value : shortDesc.value));

const localeOpen = ref(false);
const currencyOpen = ref(false);

function selectLocale(loc) {
    emit('set-locale', loc);
    localeOpen.value = false;
}
function selectCurrency(code) {
    emit('set-currency', code);
    currencyOpen.value = false;
}
</script>

<template>
    <section class="flex flex-row items-start gap-5 sm:gap-6" data-id="summary" data-checkout="summary">
        <div class="relative flex-shrink-0" data-checkout="summary-product-image">
            <img
                :src="product.image_url || 'https://placehold.co/96x96/e2e8f0/334155?text=Produto'"
                :alt="product.name"
                class="h-24 w-24 rounded-2xl object-cover ring-2 ring-gray-100 shadow-lg sm:h-28 sm:w-28"
            />
        </div>
        <div class="min-w-0 flex-1" data-checkout="summary-main">
            <div class="flex items-start justify-between gap-3">
                <h1
                    class="min-w-0 flex-1 text-xl font-bold tracking-tight text-gray-900 sm:text-2xl"
                    data-checkout="summary-title"
                >
                    {{ product.name }}
                </h1>
                <div class="flex shrink-0 items-center gap-1.5" data-checkout="summary-locale-currency">
                    <CheckoutDropdown
                        v-model:open="localeOpen"
                        :icon="Globe"
                        aria-label="Idioma"
                        align="right"
                    >
                        <button
                            v-for="loc in supportedLocales"
                            :key="loc"
                            type="button"
                            role="option"
                            class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-gray-50"
                            :class="locale === loc ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-700'"
                            @click="selectLocale(loc)"
                        >
                            <span>{{ localeLabels[loc] || loc }}</span>
                            <Check v-if="locale === loc" class="h-4 w-4 shrink-0 text-gray-500" />
                        </button>
                    </CheckoutDropdown>
                    <CheckoutDropdown
                        v-model:open="currencyOpen"
                        :icon="Banknote"
                        aria-label="Moeda"
                        align="right"
                    >
                        <button
                            v-for="c in currencyList"
                            :key="c.code"
                            type="button"
                            role="option"
                            class="flex w-full items-center justify-between gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-gray-50"
                            :class="displayCurrency === c.code ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-700'"
                            @click="selectCurrency(c.code)"
                        >
                            <span>{{ c.code }} · {{ c.symbol }}</span>
                            <Check v-if="displayCurrency === c.code" class="h-4 w-4 shrink-0 text-gray-500" />
                        </button>
                    </CheckoutDropdown>
                </div>
            </div>
            <div class="mt-3 flex flex-wrap items-baseline gap-x-3 gap-y-1" data-checkout="summary-price-row">
                <span class="text-2xl font-bold tracking-tight sm:text-3xl" :style="{ color: primaryColor }">
                    {{ formatPrice(priceToShow, displayCurrency) }}
                    <span v-if="subscriptionPlan?.interval" class="text-sm font-medium text-gray-500 ml-1 align-baseline">{{ intervalLabel(subscriptionPlan.interval) }}</span>
                </span>
                <span v-if="showOriginalPriceStrikethrough && originalPriceForDisplay != null" class="text-lg font-medium text-gray-400 line-through">
                    {{ formatPrice(originalPriceForDisplay, displayCurrency) }}
                </span>
            </div>
            <p
                v-if="couponDiscountAmountBrl > 0"
                class="mt-1.5 text-sm font-medium text-emerald-600"
                data-checkout="summary-coupon-discount"
            >
                {{ t('checkout.discount_coupon') }}: -{{ formatPrice(couponDiscountAmount, displayCurrency) }}
            </p>
            <span
                v-if="discountText"
                class="mt-3 inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wide text-rose-700 bg-rose-50 border border-rose-100"
                data-checkout="summary-discount-badge"
            >
                <Tag class="h-3.5 w-3.5" />
                {{ discountText }}
            </span>
            <template v-if="showDescription && fullDesc">
                <p
                    class="mt-3 text-sm leading-relaxed text-gray-600"
                    data-checkout="summary-description"
                    :class="{ 'line-clamp-2': !expanded && showVerMais }"
                >
                    {{ displayDesc }}
                </p>
                <button
                    v-if="showVerMais"
                    type="button"
                    class="mt-2 inline-flex items-center gap-1 text-sm font-medium transition-colors focus:outline-none focus:ring-2 focus:ring-offset-2 rounded-lg"
                    :style="{ color: primaryColor }"
                    @click="expanded = !expanded"
                >
                    {{ expanded ? t('checkout.ver_menos') : t('checkout.ver_mais') }}
                    <ChevronDown v-if="!expanded" class="h-4 w-4" />
                    <ChevronUp v-else class="h-4 w-4" />
                </button>
            </template>
        </div>
    </section>
</template>
