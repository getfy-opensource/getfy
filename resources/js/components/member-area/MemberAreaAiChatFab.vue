<script setup>
import { computed, defineAsyncComponent } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { buildPluginUiIndex, ensurePluginUiLoaded } from '@/plugins/pluginUiLoader';

const page = usePage();

const widgetConfig = computed(() => page.props.ai_member_widget ?? { enabled: false });
const productId = computed(() => {
    const fromPage = page.props.product?.id;
    if (fromPage) {
        return String(fromPage);
    }
    const fromWidget = widgetConfig.value.product_id;
    return fromWidget ? String(fromWidget) : '';
});
const userName = computed(() => page.props.auth?.user?.name || '');
const themePrimary = computed(() =>
    page.props.config?.theme?.primary
    || widgetConfig.value.theme_primary
    || '#0ea5e9',
);

const chatWidget = computed(() => {
    if (!widgetConfig.value.enabled || !productId.value) {
        return null;
    }

    const meta = buildPluginUiIndex(page.props.plugin_ui)['ai-member'];
    if (!meta?.entry) {
        return null;
    }

    return defineAsyncComponent({
        loader: () => ensurePluginUiLoaded(meta, 'FloatingChatWidget'),
        timeout: 30000,
    });
});
</script>

<template>
    <component
        v-if="chatWidget"
        :is="chatWidget"
        :product_id="productId"
        :ai_member_widget="widgetConfig"
        :user_name="userName"
        :theme_primary="themePrimary"
    />
</template>
