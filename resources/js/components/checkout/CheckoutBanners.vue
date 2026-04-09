<script setup>
defineProps({
    urls: { type: Array, default: () => [] },
    classImg: { type: String, default: 'w-full h-auto md:h-[320px] object-cover rounded-2xl shadow-xl' },
    /** Alvo para CSS: top (acima do conteúdo) ou side (sidebar desktop). */
    placement: { type: String, default: 'top' },
});

function hideImageOnError(e) {
    const el = e?.target;
    if (el && el.style) el.style.display = 'none';
}
</script>

<template>
    <div
        v-if="urls && urls.length"
        class="mb-6 space-y-5"
        :data-checkout="placement === 'side' ? 'banners-side' : 'banners-top'"
    >
        <img
            v-for="(url, i) in urls.filter(Boolean)"
            :key="i"
            :src="url"
            :alt="`Banner ${i + 1}`"
            :class="classImg"
            class="w-full"
            @error="hideImageOnError"
        />
    </div>
</template>
