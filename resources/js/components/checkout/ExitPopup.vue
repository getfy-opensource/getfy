<script setup>
import { ref, computed, watch, onUnmounted } from 'vue';
import { X } from 'lucide-vue-next';

const emit = defineEmits(['accept']);

const props = defineProps({
    /** Objeto exit_popup ou config completo (usa .exit_popup) */
    config: { type: Object, default: () => ({}) },
    primaryColor: { type: String, default: '#7427F1' },
    exitPopupCoupon: { type: Object, default: null },
    storageKey: { type: String, default: 'default' },
    t: { type: Function, default: (k) => k },
});

const visible = ref(false);
const timerId = ref(null);

const cfg = computed(() => (props.config?.exit_popup != null ? props.config.exit_popup : props.config));
const enabled = computed(() => Boolean(cfg.value && (cfg.value.enabled === true || cfg.value.enabled === 'true')));
const triggers = computed(() => cfg.value?.triggers ?? {});
const frequency = computed(() => Math.max(1, Number(cfg.value?.frequency_per_session) || 1));
const storageCountKey = computed(() => `exit_popup_${String(props.storageKey)}`);

const canShow = () => {
    if (!enabled.value) return false;
    try {
        const n = parseInt(sessionStorage.getItem(storageCountKey.value) || '0', 10);
        return n < frequency.value;
    } catch {
        return true;
    }
};

const recordShown = () => {
    try {
        const n = parseInt(sessionStorage.getItem(storageCountKey.value) || '0', 10);
        sessionStorage.setItem(storageCountKey.value, String(n + 1));
    } catch (_) {}
};

const show = () => {
    if (!canShow()) return;
    visible.value = true;
    recordShown();
};

const hide = () => {
    visible.value = false;
};

const accept = async () => {
    const code = props.exitPopupCoupon?.code;
    if (code) {
        try {
            await navigator.clipboard.writeText(code);
        } catch (_) {}
        emit('accept', code);
    }
    hide();
};

const onPopState = () => show();
const onVisibilityChange = () => {
    if (document.visibilityState === 'hidden') show();
};
const onMouseOut = (e) => {
    if (e.clientY <= 0) show();
};

function startTimer() {
    if (timerId.value) clearTimeout(timerId.value);
    const sec = triggers.value.timer_seconds;
    const num = sec !== undefined && sec !== null && sec !== '' ? Number(sec) : NaN;
    if (!(num > 0)) return;
    timerId.value = window.setTimeout(show, num * 1000);
}

function attachListeners() {
    const t = triggers.value;
    if (t.back_button !== false) window.addEventListener('popstate', onPopState);
    if (t.tab_switch !== false) document.addEventListener('visibilitychange', onVisibilityChange);
    if (t.mouse_leave_top === true) document.addEventListener('mouseout', onMouseOut);
    startTimer();
}

function detachListeners() {
    if (timerId.value) clearTimeout(timerId.value);
    timerId.value = null;
    window.removeEventListener('popstate', onPopState);
    document.removeEventListener('visibilitychange', onVisibilityChange);
    document.removeEventListener('mouseout', onMouseOut);
}

watch(
    () => [enabled.value, triggers.value],
    ([isEnabled]) => {
        detachListeners();
        if (isEnabled) attachListeners();
    },
    { immediate: true }
);

onUnmounted(() => {
    detachListeners();
});

const title = computed(() => cfg.value?.title || props.t('exit_popup.title'));
const description = computed(() => cfg.value?.description || props.t('exit_popup.description'));
const buttonAccept = computed(() => cfg.value?.button_accept || props.t('exit_popup.button_accept'));
const buttonDecline = computed(() => cfg.value?.button_decline || props.t('exit_popup.button_decline'));
const imageUrl = computed(() => cfg.value?.image || null);
</script>

<template>
    <Teleport to="body">
        <div
            v-if="enabled && visible"
            data-checkout="exit-popup"
            class="fixed inset-0 z-[9999] flex items-center justify-center p-4"
            role="dialog"
            aria-modal="true"
            aria-labelledby="exit-popup-title"
        >
            <div
                class="absolute inset-0 bg-black/50 backdrop-blur-sm"
                aria-hidden="true"
                @click="hide"
            />
            <div
                class="relative max-h-[90vh] w-full max-w-md overflow-auto rounded-3xl border border-white/20 bg-white p-6 shadow-2xl"
            >
                <button
                    type="button"
                    class="absolute right-4 top-4 rounded-full p-1 text-gray-400 transition hover:bg-gray-100 hover:text-gray-600"
                    :aria-label="t('exit_popup.close')"
                    @click="hide"
                >
                    <X class="h-5 w-5" />
                </button>
                <img
                    v-if="imageUrl"
                    :src="imageUrl"
                    alt=""
                    class="mb-4 w-full rounded-xl object-cover"
                    @error="(e) => e?.target && (e.target.style.display = 'none')"
                />
                <h2 id="exit-popup-title" class="pr-8 text-lg font-bold text-gray-900">
                    {{ title }}
                </h2>
                <p class="mt-2 text-sm text-gray-600">
                    {{ description }}
                </p>
                <div
                    v-if="exitPopupCoupon?.code"
                    class="mt-4 rounded-xl bg-gray-100 px-4 py-3 text-center font-mono text-lg font-bold tracking-wider text-gray-900"
                >
                    {{ exitPopupCoupon.code }}
                </div>
                <div class="mt-6 flex gap-3">
                    <button
                        type="button"
                        class="flex-1 rounded-xl px-4 py-3 font-semibold text-white transition hover:opacity-95"
                        :style="{ backgroundColor: primaryColor }"
                        @click="accept"
                    >
                        {{ buttonAccept }}
                    </button>
                    <button
                        type="button"
                        class="rounded-xl border-2 border-gray-200 px-4 py-3 font-semibold text-gray-700 transition hover:bg-gray-50"
                        @click="hide"
                    >
                        {{ buttonDecline }}
                    </button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
