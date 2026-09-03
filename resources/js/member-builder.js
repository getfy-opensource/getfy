import './bootstrap';
import { createApp } from 'vue';
import MemberBuilderStandalone from './Pages/Produtos/MemberBuilder/Standalone.vue';

// Aplicar tema (dark/light) igual ao restante do app — localStorage ou prefers-color-scheme
(function applyTheme() {
    try {
        const stored = localStorage.getItem('theme');
        const isDark = stored !== 'light';
        document.documentElement.classList.toggle('dark', isDark);
    } catch (_) {}
})();

const data = window.__MEMBER_BUILDER__;
if (data?.produto) {
    createApp(MemberBuilderStandalone, {
        produto: data.produto,
        tenant_products: data.tenant_products ?? [],
        app_url: data.app_url ?? '',
        dns_target_host: data.dns_target_host ?? null,
        dns_target_ip: data.dns_target_ip ?? null,
        upload_limits: data.upload_limits ?? { image_max_mb: 10, badge_max_mb: 5, pdf_max_mb: 50 },
        plugin_member_builder_tabs: data.plugin_member_builder_tabs ?? [],
        plugin_ui: data.plugin_ui ?? { plugins: [] },
        vue_bridge_url: data.vue_bridge_url ?? '',
    }).mount('#member-builder-app');
}
