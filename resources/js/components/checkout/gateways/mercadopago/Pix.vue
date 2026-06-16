<script setup>
defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
});
</script>

<template>
    <span
        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg overflow-hidden"
        :style="{ backgroundColor: selected ? primaryColor + '18' : undefined }"
    >
        <img
            src="/images/gateways/pix.svg"
            alt="PIX"
            class="h-6 w-6 shrink-0 object-contain self-center"
        />
    </span>
    <div class="min-w-0 flex-1">
        <span class="block font-medium text-gray-900">{{ method.label }}</span>
    </div>
</template>
