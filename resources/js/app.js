import './bootstrap';
import { config as inertiaConfig } from '@inertiajs/core';

inertiaConfig.set('prefetch.hoverDelay', 40);

// Service worker do painel e push: registrados em usePanelPushSubscribe (com migração ordenada do scope legado).

import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { createApp as createVueApp, h } from 'vue';
import { watchEffect } from 'vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';
import { installPluginUiBridge, resolvePluginPageComponent, getPluginUiPayloadFromDom } from './plugins/pluginUiLoader';

// Sincroniza a meta csrf-token com o token da página atual (evita 419 em gateways e outras requisições axios)
const CsrfSync = {
    setup() {
        const page = usePage();
        watchEffect(() => {
            const token = page.props.csrf_token;
            if (token && typeof document !== 'undefined') {
                const meta = document.querySelector('meta[name="csrf-token"]');
                if (meta && meta.getAttribute('content') !== token) meta.setAttribute('content', token);
            }
        });
        return () => null;
    },
};

const appName = import.meta.env.VITE_APP_NAME || 'Infoprodutor';

const el = document.getElementById('app');
const dataPage = el?.getAttribute('data-page');
let initialPage = null;
try {
    initialPage = dataPage ? JSON.parse(dataPage) : null;
} catch (_) {}
const defaultProps = {
    auth: { user: null },
    flash: { success: null, error: null },
    platform: null,
};
if (!initialPage?.component) {
    initialPage = {
        component: 'Welcome',
        props: { ...defaultProps },
        url: '/',
        version: null,
    };
} else if (initialPage.props) {
    initialPage.props = { ...defaultProps, ...initialPage.props };
    if (!initialPage.props.flash || typeof initialPage.props.flash !== 'object') {
        initialPage.props.flash = { success: null, error: null };
    }
}

const pluginPagesGlob = import.meta.glob('./PluginPages/**/*.vue');

// Criar primeiro admin: em bundle principal para não depender de chunk (evita 404 em deploy sem build novo)
const createFirstAdminPage = import.meta.glob('./Pages/Auth/CreateFirstAdmin.vue', { eager: true })['./Pages/Auth/CreateFirstAdmin.vue'];

function resolvePluginPage(name) {
    if (!name.startsWith('Plugin/')) return null;
    const pluginUi =
        getPluginUiPayloadFromDom()
        ?? initialPage?.props?.plugin_ui
        ?? null;
    const runtime = resolvePluginPageComponent(name, pluginUi);
    if (runtime) {
        return Promise.resolve({ default: runtime });
    }
    const path = `./PluginPages/${name.slice(7)}.vue`;
    const loader = pluginPagesGlob[path];
    return loader ? loader() : null;
}

createInertiaApp({
    id: 'app',
    page: initialPage,
    title: (title) => title || appName,
    resolve: (name) => {
        const pluginPage = resolvePluginPage(name);
        if (pluginPage) return pluginPage;
        if (name === 'Auth/CreateFirstAdmin' && createFirstAdminPage) {
            return Promise.resolve(createFirstAdminPage);
        }
        return resolvePageComponent(
            `./Pages/${name}.vue`,
            import.meta.glob('./Pages/**/*.vue')
        );
    },
    setup({ el, App, props, plugin }) {
        const vueApp = createVueApp({
            render: () => h('div', { class: 'contents' }, [h(App, props), h(CsrfSync)]),
        });
        vueApp.use(plugin);
        vueApp.use(createPinia());
        installPluginUiBridge(vueApp);
        vueApp.mount(el);
    },
    progress: {
        delay: 200,
        color: '#0ea5e9',
    },
});
