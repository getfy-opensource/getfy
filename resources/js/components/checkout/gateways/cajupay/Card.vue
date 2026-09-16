<script setup>
defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <span
        class="flex shrink-0 items-center justify-center overflow-hidden rounded-lg"
        :class="compact ? 'h-8 w-8 sm:h-9 sm:w-9' : 'h-11 w-11'"
        :style="{ backgroundColor: !compact && selected ? primaryColor + '20' : undefined }"
    >
        <!-- Mesmo asset do tile de cartão em DefaultMethodCard (evita Lucide sem cor = baixo contraste). -->
        <img
            src="/images/gateways/card-method.png"
            alt="Cartão"
            class="shrink-0 object-contain self-center"
            :class="compact ? 'h-5 w-5 sm:h-6 sm:w-6' : 'h-7 w-7'"
            width="28"
            height="28"
        />
    </span>
    <div class="min-w-0 flex-1">
        <span class="block font-medium text-gray-900" :class="compact ? 'text-[13px] leading-tight sm:text-sm' : ''">{{ method.label }}</span>
        <span v-if="!compact" class="block text-xs text-gray-500">Crédito</span>
    </div>
</template>
