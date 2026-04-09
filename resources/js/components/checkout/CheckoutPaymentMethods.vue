<script setup>
import { computed } from 'vue';
import { Check } from 'lucide-vue-next';
import { getMethodCardComponent } from './gateways/registry';

const props = defineProps({
    availablePaymentMethods: { type: Array, default: () => [] },
    modelValue: { type: String, default: '' },
    primaryColor: { type: String, default: '#7427F1' },
    t: { type: Function, default: (k) => k },
});

const emit = defineEmits(['update:modelValue']);

function select(methodId) {
    emit('update:modelValue', methodId);
}

function getComponent(method) {
    return getMethodCardComponent(method);
}

const count = computed(() => (props.availablePaymentMethods || []).length);
const gridClass = computed(() => {
    if (count.value <= 1) return 'grid-cols-1';
    return 'grid-cols-2 sm:grid-cols-3';
});
const isFirstOfThree = (index) => count.value === 3 && index === 0;
</script>

<template>
    <div
        v-if="availablePaymentMethods && availablePaymentMethods.length > 0"
        class="space-y-4"
        data-checkout="payment-methods"
    >
        <div class="flex items-center gap-3" data-checkout="payment-methods-header">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-600" aria-hidden="true">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
                </svg>
            </span>
            <h2 class="text-lg font-semibold tracking-tight text-gray-900">{{ t('checkout.forma_pagamento') }}</h2>
        </div>
        <div class="grid gap-3" :class="gridClass" data-checkout="payment-methods-grid">
            <button
                v-for="(method, index) in availablePaymentMethods"
                :key="method.id"
                type="button"
                :data-payment-method="method.id"
                class="relative flex cursor-pointer items-center gap-3 rounded-xl border p-4 text-left transition focus:outline-none focus:ring-1 focus:ring-inset focus:ring-gray-300"
                :class="[
                    modelValue === method.id ? 'border-gray-300 bg-gray-50/80' : 'border-gray-200 bg-white hover:border-gray-300 hover:bg-gray-50/50',
                    isFirstOfThree(index) ? 'col-span-2 sm:col-span-1' : ''
                ]"
                :style="modelValue === method.id ? { borderColor: primaryColor, backgroundColor: primaryColor + '12' } : {}"
                @click="select(method.id)"
            >
                <component
                    :is="getComponent(method)"
                    :method="method"
                    :selected="modelValue === method.id"
                    :primary-color="primaryColor"
                />
                <span
                    v-if="modelValue === method.id"
                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-white"
                    :style="{ backgroundColor: primaryColor }"
                >
                    <Check class="h-3 w-3" stroke-width="3" />
                </span>
            </button>
        </div>
    </div>
</template>
