<script setup>
import { computed } from 'vue';
import { Check } from 'lucide-vue-next';
import { getMethodCardComponent } from './gateways/registry';

const props = defineProps({
    availablePaymentMethods: { type: Array, default: () => [] },
    modelValue: { type: String, default: '' },
    primaryColor: { type: String, default: '#7427F1' },
    t: { type: Function, default: (k) => k },
    uiVariant: { type: String, default: 'default' },
    /** Method ids temporarily disabled (e.g. pix_parcelado while bootstrap loads). */
    disabledMethodIds: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:modelValue']);

function isDisabled(methodId) {
    return (props.disabledMethodIds || []).includes(methodId);
}

function select(methodId) {
    if (isDisabled(methodId)) return;
    emit('update:modelValue', methodId);
}

function getComponent(method) {
    return getMethodCardComponent(method);
}

const isTicto = computed(() => props.uiVariant === 'ticto');
const count = computed(() => (props.availablePaymentMethods || []).length);
const gridClass = computed(() => {
    if (isTicto.value) {
        // Sempre 2 colunas no estilo Ticto (1 método = linha única).
        return count.value <= 1 ? 'grid-cols-1' : 'grid-cols-2';
    }
    if (count.value <= 1) return 'grid-cols-1';
    return 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3';
});
/** Layout original: 1º de 3 métodos ocupa 2 cols no sm. Ticto: 1º de 3 ocupa a linha inteira. */
const methodSpanClass = (index) => {
    if (isTicto.value && count.value === 3 && index === 0) {
        return 'col-span-2';
    }
    if (!isTicto.value && count.value === 3 && index === 0) {
        return 'sm:col-span-2 lg:col-span-1';
    }
    return '';
};
</script>

<template>
    <div
        v-if="availablePaymentMethods && availablePaymentMethods.length > 0"
        class="space-y-4"
        data-checkout="payment-methods"
    >
        <div v-if="!isTicto" class="flex items-center gap-3" data-checkout="payment-methods-header">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-600" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
            </span>
            <h2 class="text-lg font-semibold tracking-tight text-gray-900">{{ t('checkout.forma_pagamento') }}</h2>
        </div>
        <div v-else class="space-y-1" data-checkout="payment-methods-header">
            <h2 class="text-[1.05rem] font-bold tracking-tight text-[#101828]">Pagamento</h2>
            <p class="text-sm text-[#667085]">Selecione a forma de pagamento desejada</p>
        </div>
        <slot name="after-header" />
        <div class="grid gap-3" :class="gridClass" data-checkout="payment-methods-grid">
            <button
                v-for="(method, index) in availablePaymentMethods"
                :key="method.id"
                type="button"
                :data-payment-method="method.id"
                :disabled="isDisabled(method.id)"
                class="relative flex min-w-0 items-center text-left transition focus:outline-none"
                :class="[
                    isDisabled(method.id) ? 'cursor-wait opacity-60' : 'cursor-pointer',
                    isTicto
                        ? 'gap-2.5 overflow-visible rounded-xl border-2 px-3 py-3.5'
                        : 'gap-3 rounded-xl border p-4 focus:ring-1 focus:ring-inset focus:ring-gray-300',
                    !isTicto && modelValue === method.id ? 'border-gray-300 bg-gray-50/80' : '',
                    !isTicto && modelValue !== method.id ? 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50/50' : '',
                    isTicto && modelValue !== method.id ? 'border-transparent bg-[#f3f4f6] hover:bg-[#eceef1]' : '',
                    isTicto && modelValue === method.id ? 'bg-white' : '',
                    methodSpanClass(index),
                ]"
                :style="modelValue === method.id
                    ? (isTicto
                        ? { borderColor: primaryColor, backgroundColor: '#fff' }
                        : { borderColor: primaryColor, backgroundColor: primaryColor + '12' })
                    : {}"
                :aria-busy="isDisabled(method.id) ? 'true' : undefined"
                @click="select(method.id)"
            >
                <component
                    :is="getComponent(method)"
                    :method="method"
                    :selected="modelValue === method.id"
                    :primary-color="primaryColor"
                    :compact="isTicto"
                />
                <span
                    v-if="isDisabled(method.id)"
                    class="pointer-events-none absolute inset-0 flex items-center justify-center rounded-xl bg-white/50 text-[11px] font-medium text-gray-600"
                >
                    …
                </span>
                <span
                    v-if="modelValue === method.id && !isTicto && !isDisabled(method.id)"
                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white"
                    :style="{ backgroundColor: primaryColor }"
                >
                    <Check class="h-3 w-3" stroke-width="3" />
                </span>
                <span
                    v-if="modelValue === method.id && isTicto && !isDisabled(method.id)"
                    class="absolute -right-1.5 -top-1.5 flex h-4 w-4 items-center justify-center rounded-[3px] text-white shadow-sm"
                    :style="{ backgroundColor: primaryColor }"
                >
                    <Check class="h-2.5 w-2.5" stroke-width="3" />
                </span>
            </button>
        </div>
    </div>
</template>
