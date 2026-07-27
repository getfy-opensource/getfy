<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { Timer } from 'lucide-vue-next';

const props = defineProps({
    config: { type: Object, default: () => ({}) },
    storageKey: { type: String, default: 'default' },
    t: { type: Function, default: (k) => k },
});

const enabled = computed(() => props.config?.enabled === true);
const text = computed(() => props.config?.text || props.t('checkout.timer_text'));
const hours = computed(() => Math.max(0, parseInt(props.config?.hours, 10) || 0));
const minutes = computed(() => Math.max(0, parseInt(props.config?.minutes, 10) || 0));
/** Duração total em minutos (mínimo 1). */
const totalMinutes = computed(() => Math.max(1, hours.value * 60 + minutes.value));
const durationMs = computed(() => totalMinutes.value * 60 * 1000);
const bgColor = computed(() => props.config?.background_color || '#000000');
const textColor = computed(() => props.config?.text_color || '#FFFFFF');
const sticky = computed(() => props.config?.sticky !== false);

const display = ref('00:00');
const intervalId = ref(null);

const storageKey = computed(() => `checkoutTimer_${props.storageKey}`);

function readState() {
    try {
        const raw = localStorage.getItem(storageKey.value);
        if (!raw) {
            return null;
        }
        // Formato legado: só o timestamp numérico
        if (/^\d+$/.test(raw)) {
            return { end: parseInt(raw, 10), durationMs: null };
        }
        const parsed = JSON.parse(raw);
        if (!parsed || typeof parsed !== 'object') {
            return null;
        }
        const end = parseInt(parsed.end, 10);
        const dur = parseInt(parsed.durationMs, 10);
        if (!end || Number.isNaN(end)) {
            return null;
        }

        return {
            end,
            durationMs: Number.isNaN(dur) ? null : dur,
        };
    } catch (_) {
        return null;
    }
}

function writeState(end) {
    try {
        localStorage.setItem(
            storageKey.value,
            JSON.stringify({ end, durationMs: durationMs.value })
        );
    } catch (_) {}
}

function clearState() {
    try {
        localStorage.removeItem(storageKey.value);
    } catch (_) {}
}

function formatLeft(leftSeconds) {
    const h = Math.floor(leftSeconds / 3600);
    const m = Math.floor((leftSeconds % 3600) / 60);
    const s = leftSeconds % 60;
    if (h > 0 || hours.value > 0) {
        return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
    }

    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

function resolveEndTime() {
    const existing = readState();
    const configuredDuration = durationMs.value;

    // Se a duração configurada mudou, reinicia o cronômetro.
    if (
        existing?.end
        && existing.durationMs != null
        && existing.durationMs === configuredDuration
        && existing.end > Date.now()
    ) {
        return existing.end;
    }

    // Legado sem durationMs: só reaproveita se ainda não expirou E a duração atual
    // bate com o restante aproximado (tolerância de 2s) — caso contrário reinicia.
    if (existing?.end && existing.durationMs == null && existing.end > Date.now()) {
        const remaining = existing.end - Date.now();
        if (Math.abs(remaining - configuredDuration) <= 2000) {
            writeState(existing.end);
            return existing.end;
        }
    }

    const end = Date.now() + configuredDuration;
    writeState(end);
    return end;
}

function updateDisplay() {
    if (!enabled.value) {
        return;
    }
    const endTime = resolveEndTime();
    const left = Math.max(0, Math.floor((endTime - Date.now()) / 1000));
    if (left <= 0) {
        display.value = hours.value > 0 ? '00:00:00' : '00:00';
        if (intervalId.value) {
            clearInterval(intervalId.value);
            intervalId.value = null;
        }
        clearState();
        return;
    }
    display.value = formatLeft(left);
}

function startTimer() {
    if (intervalId.value) {
        clearInterval(intervalId.value);
        intervalId.value = null;
    }
    if (!enabled.value) {
        return;
    }
    updateDisplay();
    intervalId.value = setInterval(updateDisplay, 1000);
}

onMounted(() => {
    startTimer();
});

watch([enabled, durationMs], () => {
    startTimer();
});

onUnmounted(() => {
    if (intervalId.value) {
        clearInterval(intervalId.value);
    }
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
                ? '0 1px 0 rgba(0,0,0,0.06), 0 4px 12px rgba(15,23,42,0.12), 0 0 0 1px rgba(15,23,42,0.04)'
                : undefined,
        }"
        class="flex w-full items-center justify-center gap-3 px-4 py-3.5 text-center"
    >
        <Timer class="h-5 w-5 shrink-0 opacity-90" aria-hidden="true" />
        <span class="text-sm font-semibold tracking-tight">{{ text }}</span>
        <span class="font-mono text-xl font-bold tabular-nums tracking-tight" aria-live="polite">{{ display }}</span>
    </div>
</template>
