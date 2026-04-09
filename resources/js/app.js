import './bootstrap';

// Registrar Service Worker do painel apenas fora da área de membros e do checkout (sem prompts/efeitos PWA no checkout)
let skipPanelPwa = false;
if (typeof window !== 'undefined') {
    const path = window.location.pathname;
    const isCheckout = path.startsWith('/c/') || path.startsWith('/checkout') || path.startsWith('/api-checkout');
    let isMemberArea = path.startsWith('/m/');
    if (!isMemberArea) {
        try {
            const appEl = document.getElementById('app');
            const data = appEl?.getAttribute('data-page');
            if (data) {
                const page = JSON.parse(data);
                const comp = page?.component;
                const url = page?.url ?? '';
                isMemberArea = (typeof comp === 'string' && comp.includes('MemberAreaApp')) || (typeof url === 'string' && url.startsWith('/m/'));
            }
        } catch (_) {}
    }
    skipPanelPwa = isMemberArea || isCheckout;
}
if (!skipPanelPwa && typeof navigator !== 'undefined' && navigator.serviceWorker) {
    // Scope restrito evita que o SW do painel intercepte o checkout e scripts de terceiros (pixels, gateways).
    navigator.serviceWorker.register('/painel-sw.js', { scope: '/painel/' }).catch((error) => {
        console.warn('[PWA] Falha ao registrar service worker:', error);
    });
}

// Vidstack Player 1.x (Web Components) – estilos e registros antes do Vue
import 'vidstack/player/styles/default/theme.css';
import 'vidstack/player/styles/default/layouts/audio.css';
import 'vidstack/player/styles/default/layouts/video.css';
import 'vidstack/player';
import 'vidstack/player/layouts';
import 'vidstack/player/ui';
import { createInertiaApp, usePage } from '@inertiajs/vue3';
import { createApp as createVueApp, h } from 'vue';
import { watchEffect } from 'vue';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createPinia } from 'pinia';

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
    const path = `./PluginPages/${name.slice(7).replace(/\//g, '/')}.vue`;
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
        vueApp.mount(el);
    },
    progress: false,
});
