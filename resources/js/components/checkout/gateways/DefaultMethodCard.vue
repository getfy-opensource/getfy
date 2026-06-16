<script setup>
import { CreditCard } from 'lucide-vue-next';

defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
});
</script>

<template>
    <span
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg overflow-hidden"
        :style="{ backgroundColor: selected && method.id !== 'pix' && method.id !== 'pix_auto' ? primaryColor + '18' : undefined }"
    >
        <img
            v-if="method.id === 'pix'"
            src="/images/gateways/pix.svg"
            alt="PIX"
            class="h-7 w-7 shrink-0 object-contain self-center"
        />
        <img
            v-else-if="method.id === 'pix_auto'"
            src="/images/gateways/pix-automatico-checkout.png"
            alt="PIX automático"
            class="h-7 w-7 shrink-0 object-contain self-center"
        />
        <img
            v-else-if="method.id === 'card'"
            src="/images/gateways/card-method.png"
            alt="Cartão"
            class="h-7 w-7 shrink-0 object-contain self-center"
        />
        <img
            v-else-if="method.id === 'boleto'"
            src="/images/gateways/boleto.png"
            alt="Boleto"
            class="h-7 w-7 shrink-0 object-contain self-center"
        />
        <CreditCard v-else class="h-5 w-5" />
    </span>
    <div class="min-w-0 flex-1">
        <span class="block font-medium text-gray-900">{{ method.label }}</span>
    </div>
</template>
