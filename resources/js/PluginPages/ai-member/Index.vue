<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { ensurePluginUiLoaded, buildPluginUiIndex } from '@/plugins/pluginUiLoader';

defineOptions({ layout: LayoutInfoprodutor });

defineProps({
    pluginSlug: { type: String, default: 'ai-member' },
    pluginPage: { type: String, default: 'Index' },
    plugin_ui_page: { type: Object, default: null },
});

const page = usePage();
const pluginMeta = computed(() => {
    const bySlug = buildPluginUiIndex(page.props.plugin_ui ?? null);
    if (bySlug['ai-member']?.entry) {
        return bySlug['ai-member'];
    }
    // Fallback quando ui.manifest.json estava ausente no dist/
    return {
        slug: 'ai-member',
        entry: '/plugins/ai-member/assets/dist/plugin-ui.js',
        frontend_pages: { Index: 'SettingsPage' },
    };
});

const SettingsPage = defineAsyncComponent({
    loader: async () => {
        const bySlug = buildPluginUiIndex(usePage().props.plugin_ui ?? null);
        const meta = bySlug['ai-member'];
        if (!meta?.entry) {
            throw new Error('Plugin AI Member não carregado');
        }
        return ensurePluginUiLoaded(meta, 'SettingsPage');
    },
    timeout: 30000,
});
</script>

<template>
    <SettingsPage v-if="pluginMeta?.entry" />
    <div
        v-else
        class="rounded-xl border border-amber-200 bg-amber-50 p-6 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
    >
        <p class="font-medium">Plugin AI Member indisponível</p>
        <p class="mt-2 text-amber-800 dark:text-amber-200">
            Verifique se o plugin está <strong>ativo</strong> em Gerenciar plugins e se o bundle
            <code class="rounded bg-amber-100 px-1 dark:bg-amber-900">dist/plugin-ui.js</code> foi compilado.
        </p>
    </div>
</template>
