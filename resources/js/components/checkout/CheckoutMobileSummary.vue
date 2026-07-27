<script setup>
import { computed, ref } from 'vue';
import { ChevronUp, Package, ShoppingBag, Tag, X } from 'lucide-vue-next';

const props = defineProps({
    product: { type: Object, required: true },
    selectedOrderBumps: { type: Array, default: () => [] },
    appliedCoupon: { type: Object, default: null },
    orderBumpsTotalBrl: { type: Number, default: 0 },
    primaryColor: { type: String, default: '#0ea5e9' },
    iconUrl: { type: String, default: '' },
    t: { type: Function, default: (k) => k },
    displayCurrency: { type: String, default: 'BRL' },
    priceInCurrency: { type: Function, default: (v) => v },
    formatPrice: { type: Function, default: (v) => String(v) },
});

const expanded = ref(false);

const customIconUrl = computed(() => String(props.iconUrl || '').trim());

const mainProductPriceBrl = computed(() => {
    const applied = props.appliedCoupon;
    if (applied != null && applied.final_price != null) {
        return Number(applied.final_price);
    }
    return Number(props.product?.price_brl ?? props.product?.price ?? 0);
});

const productPriceBrl = computed(() => Number(props.product?.price_brl ?? props.product?.price ?? 0));
const discountAmountBrl = computed(() =>
    props.appliedCoupon?.discount_amount != null ? Number(props.appliedCoupon.discount_amount) : 0
);
const totalPriceBrl = computed(() => mainProductPriceBrl.value + (Number(props.orderBumpsTotalBrl) || 0));
const totalDisplay = computed(() => props.formatPrice(props.priceInCurrency(totalPriceBrl.value), props.displayCurrency));

const itemCount = computed(() => 1 + (props.selectedOrderBumps?.length || 0));

function toggle() {
    expanded.value = !expanded.value;
}

function close() {
    expanded.value = false;
}
</script>

<template>
    <div class="checkout-mobile-summary" data-checkout="mobile-summary">
        <div
            class="fixed inset-x-0 bottom-0 z-[56]"
            data-checkout="mobile-summary-sheet"
        >
            <div
                class="overflow-hidden rounded-t-2xl bg-white/95 shadow-[0_-10px_36px_rgba(15,23,42,0.16)] backdrop-blur-xl"
                style="padding-bottom: env(safe-area-inset-bottom, 0px)"
            >
                <div class="flex justify-center pt-2.5" aria-hidden="true">
                    <span
                        class="h-1 w-10 rounded-full"
                        :style="{ backgroundColor: primaryColor + '55' }"
                    />
                </div>

                <Transition
                    enter-active-class="transition-all duration-200 ease-out"
                    enter-from-class="opacity-0 max-h-0"
                    enter-to-class="opacity-100 max-h-80"
                    leave-active-class="transition-all duration-200 ease-in"
                    leave-from-class="opacity-100 max-h-80"
                    leave-to-class="opacity-0 max-h-0"
                >
                    <div v-if="expanded" class="overflow-hidden border-b border-zinc-100">
                        <div class="flex items-center justify-between px-4 pb-1 pt-3">
                            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
                                {{ t('checkout.mobile_summary_items') || 'Itens do pedido' }}
                            </p>
                            <button
                                type="button"
                                class="rounded-full p-1 text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700"
                                aria-label="Fechar"
                                @click="close"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>
                        <div class="max-h-56 space-y-2 overflow-y-auto px-4 pb-3">
                            <div class="flex items-start justify-between gap-3 rounded-xl bg-zinc-50 px-3 py-2.5">
                                <div class="flex min-w-0 items-start gap-2.5">
                                    <span
                                        class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-lg"
                                        :style="{ backgroundColor: primaryColor + '18', color: primaryColor }"
                                    >
                                        <Package class="h-3.5 w-3.5" />
                                    </span>
                                    <span class="truncate text-sm font-medium text-zinc-800">{{ product.name }}</span>
                                </div>
                                <div class="shrink-0 text-right text-sm font-semibold text-zinc-900">
                                    <span
                                        v-if="discountAmountBrl > 0"
                                        class="mr-1 text-xs font-medium text-zinc-400 line-through"
                                    >
                                        {{ formatPrice(priceInCurrency(productPriceBrl), displayCurrency) }}
                                    </span>
                                    {{ formatPrice(priceInCurrency(mainProductPriceBrl), displayCurrency) }}
                                </div>
                            </div>

                            <div
                                v-for="bump in selectedOrderBumps"
                                :key="bump.id"
                                class="flex items-start justify-between gap-3 rounded-xl px-3 py-2.5"
                                :style="{ backgroundColor: primaryColor + '0d' }"
                            >
                                <span class="truncate text-sm font-medium" :style="{ color: primaryColor }">
                                    + {{ bump.title }}
                                </span>
                                <span class="shrink-0 text-sm font-semibold text-zinc-900">
                                    {{ formatPrice(priceInCurrency(bump.amount_brl), displayCurrency) }}
                                </span>
                            </div>

                            <div
                                v-if="discountAmountBrl > 0"
                                class="flex items-center justify-between gap-3 rounded-xl bg-emerald-50 px-3 py-2.5 text-sm text-emerald-700"
                            >
                                <span class="flex items-center gap-1.5 font-medium">
                                    <Tag class="h-3.5 w-3.5" />
                                    {{ t('checkout.discount_coupon') }}
                                </span>
                                <span class="font-semibold">
                                    -{{ formatPrice(priceInCurrency(discountAmountBrl), displayCurrency) }}
                                </span>
                            </div>
                        </div>
                    </div>
                </Transition>

                <button
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-3.5 text-left transition active:bg-zinc-50/80"
                    :aria-expanded="expanded"
                    @click="toggle"
                >
                    <span
                        class="flex h-10 w-10 shrink-0 items-center justify-center overflow-hidden rounded-xl shadow-sm"
                        :class="customIconUrl ? 'bg-white ring-1 ring-zinc-100' : 'text-white'"
                        :style="customIconUrl ? {} : { backgroundColor: primaryColor }"
                    >
                        <img
                            v-if="customIconUrl"
                            :src="customIconUrl"
                            alt=""
                            class="h-full w-full object-cover"
                        />
                        <ShoppingBag
                            v-else
                            class="h-4.5 w-4.5"
                            style="width: 1.1rem; height: 1.1rem"
                        />
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
                            {{ t('checkout.mobile_summary_label') || 'Seu pedido' }}
                        </p>
                        <p class="truncate text-sm font-medium text-zinc-700">
                            {{ itemCount }}
                            {{ itemCount === 1
                                ? (t('checkout.mobile_summary_item_singular') || 'item')
                                : (t('checkout.mobile_summary_item_plural') || 'itens')
                            }}
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="text-[10px] font-semibold uppercase tracking-wider text-zinc-400">
                            {{ t('checkout.total') || 'Total' }}
                        </p>
                        <p class="text-base font-bold tabular-nums leading-tight" :style="{ color: primaryColor }">
                            {{ totalDisplay }}
                        </p>
                    </div>
                    <span
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 transition-transform duration-200"
                        :class="expanded ? 'rotate-180' : ''"
                    >
                        <ChevronUp class="h-4 w-4" />
                    </span>
                </button>
            </div>
        </div>
    </div>
</template>
