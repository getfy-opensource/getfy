<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { RotateCw } from 'lucide-vue-next';
import { createPullToRefresh, isStandaloneDisplay } from '@/lib/pullToRefresh';

/** Puxar para recarregar no PWA instalado. Fora do app instalado não faz nada (o navegador já tem o gesto). */
const props = defineProps({
    color: { type: String, default: 'var(--color-primary, #0ea5e9)' },
});

const THRESHOLD = 70;
const state = ref({ offset: 0, ready: false, refreshing: false });
const active = ref(false);

const visible = computed(() => state.value.offset > 0 || state.value.refreshing);
const indicatorStyle = computed(() => ({
    transform: `translate(-50%, ${state.value.offset - 48}px)`,
    opacity: Math.min(1, state.value.offset / THRESHOLD),
    color: props.color,
}));
const iconStyle = computed(() => ({
    transform: state.value.refreshing ? undefined : `rotate(${state.value.offset * 3}deg)`,
}));

let controller = null;
const onStart = (e) => controller?.start(e);
const onMove = (e) => controller?.move(e);
const onEnd = () => controller?.end();
const onCancel = () => controller?.cancel();

onMounted(() => {
    if (!isStandaloneDisplay(window)) return;
    active.value = true;
    controller = createPullToRefresh({
        win: window,
        threshold: THRESHOLD,
        isLocked: () => getComputedStyle(document.body).overflow === 'hidden',
        onChange: (next) => {
            state.value = next;
        },
        onRefresh: () => window.location.reload(),
    });
    document.addEventListener('touchstart', onStart, { passive: true });
    document.addEventListener('touchmove', onMove, { passive: false });
    document.addEventListener('touchend', onEnd, { passive: true });
    document.addEventListener('touchcancel', onCancel, { passive: true });
});

onUnmounted(() => {
    if (!active.value) return;
    document.removeEventListener('touchstart', onStart);
    document.removeEventListener('touchmove', onMove);
    document.removeEventListener('touchend', onEnd);
    document.removeEventListener('touchcancel', onCancel);
    controller = null;
});
</script>

<template>
    <div
        v-if="active && visible"
        class="pointer-events-none fixed left-1/2 z-[9999] flex h-10 w-10 items-center justify-center rounded-full bg-white shadow-lg ring-1 ring-black/5 dark:bg-zinc-800 dark:ring-white/10"
        style="top: env(safe-area-inset-top, 0px)"
        :style="indicatorStyle"
        role="status"
        :aria-label="state.refreshing ? 'Recarregando' : 'Puxe para recarregar'"
    >
        <RotateCw class="h-5 w-5" :class="state.refreshing ? 'animate-spin' : ''" :style="iconStyle" />
    </div>
</template>
