<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue';
import { Sparkles } from 'lucide-vue-next';

const props = defineProps({
    config: { type: Object, default: () => ({}) },
    productName: { type: String, default: '' },
    /** URL da imagem do produto (exibida por padrão no lugar do ícone) */
    productImageUrl: { type: String, default: '' },
});

const enabled = computed(() => props.config?.enabled === true);
/** Texto após o nome (ex.: "acabou de comprar"). Exibido como "Nome, [title]" */
const titleSuffix = computed(() => props.config?.title?.trim() || 'acabou de comprar');
const names = computed(() => {
    const raw = props.config?.names || '';
    return raw
        .split('\n')
        .map((s) => s.trim())
        .filter(Boolean);
});
const productLabel = computed(() => props.config?.product_label || props.productName || '');
const displaySeconds = computed(() => Math.max(0, Number(props.config?.display_seconds) || 5) * 1000);
const intervalSeconds = computed(() => Math.max(0, Number(props.config?.interval_seconds) || 10) * 1000);

const visible = ref(false);
const currentName = ref('');
const currentProduct = ref('');
let showTimeout = null;
let intervalId = null;

function showOne() {
    if (names.value.length === 0) return;
    currentName.value = names.value[Math.floor(Math.random() * names.value.length)];
    currentProduct.value = productLabel.value;
    visible.value = true;
    if (showTimeout) clearTimeout(showTimeout);
    showTimeout = setTimeout(() => {
        visible.value = false;
        showTimeout = null;
    }, displaySeconds.value);
}

function startInterval() {
    if (intervalId) clearInterval(intervalId);
    if (!enabled.value || names.value.length === 0) return;
    showOne();
    intervalId = setInterval(showOne, intervalSeconds.value);
}

function stopInterval() {
    if (intervalId) {
        clearInterval(intervalId);
        intervalId = null;
    }
    if (showTimeout) {
        clearTimeout(showTimeout);
        showTimeout = null;
    }
    visible.value = false;
}

watch([enabled, names], () => {
    stopInterval();
    if (enabled.value && names.value.length > 0) startInterval();
});

onMounted(() => {
    if (enabled.value && names.value.length > 0) startInterval();
});

onUnmounted(stopInterval);
</script>

<template>
    <div
        v-if="enabled"
        data-checkout="sales-notification"
        role="status"
        aria-live="polite"
        aria-atomic="true"
        :class="[
            'fixed left-4 bottom-4 z-[9999] w-80 overflow-hidden rounded-2xl border border-gray-200/80 bg-white/95 p-4 shadow-xl shadow-black/10 backdrop-blur-md transition-all duration-500 lg:left-6 lg:bottom-6',
            visible ? 'translate-y-0 opacity-100' : 'translate-y-full opacity-0',
        ]"
    >
        <div class="flex items-center gap-4">
            <div class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-gray-100 object-cover">
                <img
                    v-if="productImageUrl"
                    :src="productImageUrl"
                    :alt="productName || 'Produto'"
                    class="h-full w-full object-cover"
                />
                <span
                    v-else
                    class="flex h-full w-full items-center justify-center bg-gradient-to-br from-violet-100 to-fuchsia-100 text-violet-600"
                >
                    <Sparkles class="h-5 w-5" aria-hidden="true" />
                </span>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-gray-900">
                    <span id="notification-name">{{ currentName }}</span>, {{ titleSuffix }}
                </p>
                <p v-if="currentProduct" class="mt-0.5 text-xs text-gray-500" id="notification-product">
                    {{ currentProduct }}
                </p>
            </div>
        </div>
    </div>
</template>
