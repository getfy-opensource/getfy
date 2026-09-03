<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue';
import { Loader2 } from 'lucide-vue-next';
import { buildPluginUiIndex, ensurePluginUiLoaded } from '@/plugins/pluginUiLoader';

const props = defineProps({
    panel: { type: Object, required: true },
    pluginUi: { type: Object, default: () => ({ plugins: [] }) },
    produto: { type: Object, default: null },
    vueBridgeUrl: { type: String, default: '' },
});

const host = ref(null);
const loading = ref(true);
const error = ref('');
let app = null;

function unmountPanel() {
    if (app) {
        try {
            app.unmount();
        } catch (_) {}
        app = null;
    }
    if (host.value) {
        host.value.innerHTML = '';
    }
}

async function mountPanel() {
    if (!host.value || !props.panel?.plugin_slug || !props.panel?.ui_export) {
        return;
    }

    loading.value = true;
    error.value = '';
    unmountPanel();

    try {
        const bySlug = buildPluginUiIndex(props.pluginUi);
        const meta = bySlug[props.panel.plugin_slug];
        if (!meta?.entry) {
            throw new Error('Bundle do plugin não configurado.');
        }

        const bridgeUrl = props.vueBridgeUrl?.trim();
        if (!bridgeUrl) {
            throw new Error('Bridge Vue do painel não configurado.');
        }

        const Comp = await ensurePluginUiLoaded(meta, props.panel.ui_export);
        const Vue = await import(/* @vite-ignore */ bridgeUrl);
        const createApp = Vue.createApp ?? Vue.default?.createApp;
        if (typeof createApp !== 'function') {
            throw new Error('Não foi possível inicializar Vue para o plugin.');
        }

        app = createApp(Comp, { produto: props.produto });
        app.mount(host.value);
    } catch (e) {
        error.value = e?.message || 'Não foi possível carregar este painel do plugin.';
    } finally {
        loading.value = false;
    }
}

onMounted(mountPanel);
onUnmounted(unmountPanel);
watch(
    () => [
        props.panel?.plugin_slug,
        props.panel?.ui_export,
        props.produto?.id,
        props.vueBridgeUrl,
    ],
    () => mountPanel(),
);
</script>

<template>
    <div class="min-h-[200px]">
        <div v-if="loading" class="flex justify-center py-16">
            <Loader2 class="h-8 w-8 animate-spin text-zinc-400" />
        </div>
        <div
            v-if="error"
            class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
            role="alert"
        >
            {{ error }}
        </div>
        <div ref="host" :class="{ hidden: loading || error }" />
    </div>
</template>
