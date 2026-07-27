<script setup>
defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
});
</script>

<template>
    <span class="flex min-w-0 flex-1 items-center gap-3">
        <span
            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg overflow-hidden"
            :style="{ backgroundColor: selected ? primaryColor + '20' : undefined }"
        >
            <img
                src="/images/gateways/card-method.png"
                alt="Cartão"
                class="h-7 w-7 shrink-0 object-contain self-center"
            />
        </span>
        <span class="min-w-0 flex-1">
            <span class="block font-medium text-gray-900">{{ method.label || 'Cartão' }}</span>
        </span>
    </span>
</template>
