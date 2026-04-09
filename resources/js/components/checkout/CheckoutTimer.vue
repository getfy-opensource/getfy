<script setup>
import { ref, onMounted, onUnmounted, computed } from 'vue';
import { Timer } from 'lucide-vue-next';

const props = defineProps({
    config: { type: Object, default: () => ({}) },
    storageKey: { type: String, default: 'default' },
    t: { type: Function, default: (k) => k },
});

const enabled = computed(() => props.config?.enabled === true);
const text = computed(() => props.config?.text || props.t('checkout.timer_text'));
const minutes = computed(() => Math.max(1, parseInt(props.config?.minutes, 10) || 15));
const bgColor = computed(() => props.config?.background_color || '#000000');
const textColor = computed(() => props.config?.text_color || '#FFFFFF');
const sticky = computed(() => props.config?.sticky !== false);

const display = ref(`${minutes.value}:00`);
const intervalId = ref(null);

const storageKey = computed(() => `checkoutTimer_${props.storageKey}`);

function updateDisplay() {
    const key = storageKey.value;
    let endTime = null;
    try {
        endTime = parseInt(localStorage.getItem(key), 10);
    } catch (_) {}
    if (!endTime || Number.isNaN(endTime)) {
        endTime = Date.now() + minutes.value * 60 * 1000;
        try {
            localStorage.setItem(key, String(endTime));
        } catch (_) {}
    }
    const left = Math.max(0, Math.floor((endTime - Date.now()) / 1000));
    if (left <= 0) {
        display.value = '00:00';
        if (intervalId.value) {
            clearInterval(intervalId.value);
            intervalId.value = null;
        }
        try {
            localStorage.removeItem(key);
        } catch (_) {}
        return;
    }
    const m = Math.floor(left / 60);
    const s = left % 60;
    display.value = `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

onMounted(() => {
    if (!enabled.value) return;
    updateDisplay();
    intervalId.value = setInterval(updateDisplay, 1000);
});

onUnmounted(() => {
    if (intervalId.value) clearInterval(intervalId.value);
});
</script>

<template>
    <div
        v-if="enabled"
        data-checkout="timer"
        :style="{
            backgroundColor: bgColor,
            color: textColor,
            position: sticky ? 'sticky' : 'relative',
            top: sticky ? 0 : undefined,
            zIndex: sticky ? 1000 : undefined,
            boxShadow: sticky
                ? '0 1px 0 rgba(0,0,0,0.06), 0 4px 12px rgba(0,0,0,0.12), 0 0 0 1px rgba(0,0,0,0.04)'
                : undefined,
        }"
        :class="[
            'flex items-center justify-center gap-3 px-4 py-3.5 text-center w-full',
        ]"
    >
        <Timer class="h-5 w-5 shrink-0 opacity-90" aria-hidden="true" />
        <span class="text-sm font-semibold tracking-tight">{{ text }}</span>
        <span class="font-mono text-xl font-bold tabular-nums tracking-tight" aria-live="polite">{{ display }}</span>
    </div>
</template>
