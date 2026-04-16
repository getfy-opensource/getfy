<script setup>
import { ref, computed, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Puzzle, Power, PowerOff, ExternalLink, CreditCard, Package, Download, Upload, Trash2, FolderUp } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const TABS = [
    { id: 'installed', label: 'Instalados', icon: Puzzle },
    { id: 'store', label: 'Loja de plugins', icon: Package },
];

const CATEGORY_LABELS = {
    gateway: 'Gateway',
    integration: 'Integração',
    marketing: 'Marketing',
    outros: 'Outros',
    other: 'Outros',
};

const props = defineProps({
    plugins: { type: Array, default: () => [] },
    /** Lista de slugs instalados (registrados no servidor). */
    installedPluginSlugs: { type: Array, default: () => [] },
    /** Lista de nomes dos plugins instalados (para comparar com a loja por nome). */
    installedPluginNames: { type: Array, default: () => [] },
    storePlugins: { type: Array, default: () => [] },
    pluginStore: { type: Object, default: () => ({ store_url: '', submit_url: '' }) },
    pluginsPath: { type: String, default: '' },
    /** Pasta persistente para instalações (ZIP/loja). */
    plugins_install_path: { type: String, default: '' },
    /** Pasta versionada com o código (ex.: example-gateway). */
    plugins_bundled_path: { type: String, default: '' },
});

const page = usePage();
/** Normaliza slug para comparação (loja pode usar _ e pasta pode usar -). */
function normalizeSlug(s) {
    if (s == null || typeof s !== 'string') return '';
    return s.toLowerCase().replace(/_/g, '-').replace(/[^a-z0-9-]/g, '');
}
/** Normaliza nome para comparação (minúsculas, sem acentos, espaços colapsados). */
function normalizeName(s) {
    if (s == null || typeof s !== 'string') return '';
    const t = s.toLowerCase().trim().replace(/\s+/g, ' ');
    return t.normalize('NFD').replace(/\p{Diacritic}/gu, '');
}
/** Sets de slugs e nomes instalados (servidor), normalizados. */
const installedSlugsSet = computed(() => {
    const slugs = Array.isArray(props.installedPluginSlugs) ? props.installedPluginSlugs : [];
    return new Set(slugs.map((slug) => normalizeSlug(slug)));
});
const installedNamesSet = computed(() => {
    const names = Array.isArray(props.installedPluginNames) ? props.installedPluginNames : [];
    return new Set(names.map((name) => normalizeName(name)));
});
/** Verifica se o plugin da loja está instalado — por slug ou por nome. */
function isStorePluginInstalled(storePlugin) {
    const slug = storePlugin?.slug ?? storePlugin;
    const name = typeof storePlugin === 'object' ? storePlugin?.name : undefined;
    if (slug && installedSlugsSet.value.has(normalizeSlug(slug))) return true;
    if (name && installedNamesSet.value.has(normalizeName(name))) return true;
    return false;
}
const currentTab = computed(() => {
    const url = page.url;
    const idx = url.indexOf('?');
    const search = idx !== -1 ? url.slice(idx) : '';
    const q = new URLSearchParams(search);
    const t = q.get('tab');
    return TABS.some((tab) => tab.id === t) ? t : 'installed';
});

const storeDetail = ref(null);
const installingSlug = ref(null);
const storeBannerFailed = ref({});
const storePluginsList = ref([]);
const storePluginsLoading = ref(false);
const storePluginsError = ref(null);
const lastInstallDownloadUrl = ref(null);
const lastInstallSlug = ref(null);
const showZipUnavailableModal = ref(false);
const showManualInstallModal = ref(false);
const manualInstallFileInput = ref(null);
const manualInstallError = ref('');
const manualInstallProcessing = ref(false);
const downloadFallbackLoading = ref(false);
const downloadFallbackError = ref('');

function setTab(tabId) {
    router.get('/gerenciar-plugins', { tab: tabId }, { preserveState: true });
}

async function loadStorePlugins() {
    const baseUrl = props.pluginStore?.store_url;
    if (!baseUrl) {
        storePluginsError.value = 'Configure PLUGIN_STORE_URL no .env (ex.: http://plugins-getfy.test).';
        return;
    }
    storePluginsError.value = null;
    storePluginsLoading.value = true;
    try {
        // Busca direto na API da loja (navegador → plugins-getfy) para evitar requisição servidor→servidor que caía no vhost errado
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins';
        const r = await fetch(apiUrl);
        const json = await r.json();
        storePluginsList.value = Array.isArray(json?.data) ? json.data : [];
        if (json?.error) storePluginsError.value = json.error;
        if (!r.ok) storePluginsError.value = json?.error || `Loja retornou HTTP ${r.status}.`;
    } catch (e) {
        storePluginsList.value = [];
        storePluginsError.value = 'Não foi possível carregar a loja. Verifique a conexão e PLUGIN_STORE_URL.';
    } finally {
        storePluginsLoading.value = false;
    }
}

function categoryLabel(category) {
    return CATEGORY_LABELS[category] ?? category ?? 'Outros';
}

async function openStoreDetail(plugin) {
    storeDetail.value = { ...plugin };
    const baseUrl = props.pluginStore?.store_url;
    if (!baseUrl) return;
    try {
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins/' + encodeURIComponent(plugin.slug);
        const r = await fetch(apiUrl);
        if (r.ok) {
            const json = await r.json();
            if (json?.data) {
                storeDetail.value = { ...storeDetail.value, ...json.data };
            }
        }
    } catch (_) {}
}

function closeStoreDetail() {
    storeDetail.value = null;
}

function setStoreBannerFailed(slug) {
    storeBannerFailed.value = { ...storeBannerFailed.value, [slug]: true };
}

const returnUrl = computed(() => {
    const base = typeof window !== 'undefined' ? window.location.origin : '';
    return base + '/gerenciar-plugins?tab=installed&install=';
});

function checkoutUrl(slug) {
    const url = props.pluginStore?.store_url || '';
    if (!url) return '#';
    const base = url.replace(/\/$/, '');
    const targetCheckout = '/c/' + slug + '?return_url=' + encodeURIComponent(returnUrl.value + slug);
    return `${base}/login?next=${encodeURIComponent(targetCheckout)}`;
}

async function installStorePlugin(slug, purchaseToken = null) {
    const baseUrl = props.pluginStore?.store_url;
    if (!baseUrl) {
        storePluginsError.value = 'Loja não configurada.';
        return;
    }
    installingSlug.value = slug;
    storePluginsError.value = null;
    try {
        // 1) Obter link de download no navegador (evita requisição servidor→loja)
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins/' + encodeURIComponent(slug) + '/request-download';
        const body = purchaseToken ? { purchase_token: purchaseToken } : {};
        const r = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
            credentials: 'omit',
        });
        const json = await r.json().catch(() => ({}));
        const downloadUrl = json?.download_url;
        if (!r.ok || !downloadUrl) {
            storePluginsError.value = json?.message || json?.error || `Loja retornou HTTP ${r.status}.`;
            installingSlug.value = null;
            return;
        }
        // 2) Baixar o ZIP no navegador (evita que o servidor precise acessar a loja)
        const zipRes = await fetch(downloadUrl, { credentials: 'omit' });
        if (!zipRes.ok) {
            storePluginsError.value = 'Não foi possível baixar o arquivo do plugin.';
            installingSlug.value = null;
            return;
        }
        const blob = await zipRes.blob();
        const file = new File([blob], slug + '.zip', { type: 'application/zip' });
        lastInstallDownloadUrl.value = downloadUrl;
        lastInstallSlug.value = slug;
        // 3) Enviar via Inertia (CSRF e redirect tratados automaticamente)
        router.post(`/gerenciar-plugins/install/${slug}`, { plugin_zip: file }, {
            preserveScroll: true,
            forceFormData: true,
            onFinish: () => { installingSlug.value = null; },
            onError: (errors) => {
                storePluginsError.value = typeof errors === 'object' && errors?.plugin_zip
                    ? (Array.isArray(errors.plugin_zip) ? errors.plugin_zip[0] : errors.plugin_zip)
                    : 'Falha ao instalar. Tente novamente.';
            },
        });
    } catch (e) {
        storePluginsError.value = 'Não foi possível obter ou instalar o plugin. Verifique a conexão.';
        installingSlug.value = null;
    }
}

function enablePlugin(slug) {
    router.post(`/integracoes/plugins/${slug}/enable`, {}, { preserveScroll: true });
}

function disablePlugin(slug) {
    router.post(`/integracoes/plugins/${slug}/disable`, {}, { preserveScroll: true });
}

const registeringSlug = ref(null);
function registerPlugin(slug) {
    registeringSlug.value = slug;
    router.post(`/gerenciar-plugins/register-plugin/${slug}`, {}, {
        preserveScroll: true,
        onFinish: () => { registeringSlug.value = null; },
    });
}

const uninstallingSlug = ref(null);
function uninstallPlugin(plugin) {
    if (!window.confirm(`Excluir o plugin "${plugin.name}"? A pasta do plugin será removida e não será possível desfazer.`)) return;
    uninstallingSlug.value = plugin.slug;
    router.delete(`/integracoes/plugins/${plugin.slug}`, {
        preserveScroll: true,
        onFinish: () => { uninstallingSlug.value = null; },
    });
}

function goToGateways() {
    router.visit('/integracoes?tab=gateways');
}

const bannerLoadFailed = ref({});
function setBannerFailed(slug) {
    bannerLoadFailed.value = { ...bannerLoadFailed.value, [slug]: true };
}

const urlPurchaseToken = ref(null);
const urlInstallSlug = ref(null);
watch(() => page.url, () => {
    if (typeof window === 'undefined') return;
    const q = new URLSearchParams(window.location.search);
    urlPurchaseToken.value = q.get('purchase_token') || null;
    urlInstallSlug.value = q.get('install') || null;
}, { immediate: true });

watch([urlPurchaseToken, urlInstallSlug, currentTab], ([token, installSlug, tab]) => {
    if (tab !== 'installed' || !installSlug || !token) return;
    installStorePlugin(installSlug, token);
}, { immediate: true });

watch(() => page.props?.flash?.zip_unavailable, (v) => {
    if (v) showZipUnavailableModal.value = true;
});

function openZipUnavailableModal() {
    showZipUnavailableModal.value = true;
    downloadFallbackError.value = '';
}

function closeZipUnavailableModal() {
    showZipUnavailableModal.value = false;
}

async function downloadPluginFallback() {
    const slug = lastInstallSlug.value;
    const baseUrl = props.pluginStore?.store_url;
    if (!slug || !baseUrl) {
        if (lastInstallDownloadUrl.value) window.open(lastInstallDownloadUrl.value, '_blank');
        closeZipUnavailableModal();
        return;
    }
    downloadFallbackError.value = '';
    downloadFallbackLoading.value = true;
    try {
        const apiUrl = baseUrl.replace(/\/$/, '') + '/api/v1/plugins/' + encodeURIComponent(slug) + '/request-download';
        const body = urlPurchaseToken.value ? { purchase_token: urlPurchaseToken.value } : {};
        const r = await fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify(body),
            credentials: 'omit',
        });
        const json = await r.json().catch(() => ({}));
        const downloadUrl = json?.download_url;
        if (!r.ok || !downloadUrl) {
            downloadFallbackError.value = json?.message || json?.error || 'Não foi possível obter o link de download.';
            return;
        }
        window.open(downloadUrl, '_blank');
        closeZipUnavailableModal();
    } catch (e) {
        downloadFallbackError.value = 'Não foi possível obter o link. Tente instalar manualmente com o ZIP.';
    } finally {
        downloadFallbackLoading.value = false;
    }
}

function openManualInstallModal() {
    showManualInstallModal.value = true;
    manualInstallError.value = '';
    if (manualInstallFileInput.value) manualInstallFileInput.value.value = '';
    closeZipUnavailableModal();
}

function closeManualInstallModal() {
    showManualInstallModal.value = false;
    manualInstallError.value = '';
}

function submitManualInstall() {
    const file = manualInstallFileInput.value?.files?.[0];
    if (!file || !file.name.toLowerCase().endsWith('.zip')) {
        manualInstallError.value = 'Selecione um arquivo .zip do plugin.';
        return;
    }
    manualInstallError.value = '';
    manualInstallProcessing.value = true;
    router.post('/gerenciar-plugins/install-from-zip', { plugin_zip: file }, {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => { manualInstallProcessing.value = false; },
        onSuccess: () => { closeManualInstallModal(); },
        onError: (errors) => {
            manualInstallError.value = typeof errors?.plugin_zip === 'string'
                ? errors.plugin_zip
                : (errors?.plugin_zip?.[0] ?? 'Falha ao instalar. Tente novamente.');
        },
    });
}
</script>

<template>
    <div class="space-y-6">
        <nav
            class="inline-flex flex-wrap gap-1 rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
            aria-label="Abas de plugins"
        >
            <button
                v-for="tab in TABS"
                :key="tab.id"
                type="button"
                :class="[
                    'flex items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition-all duration-200',
                    currentTab === tab.id
                        ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                ]"
                @click="setTab(tab.id)"
            >
                <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ tab.label }}
            </button>
        </nav>

        <!-- Aba Instalados -->
        <template v-if="currentTab === 'installed'">
            <section>
                <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Plugins instalados
                    </h2>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                        @click="openManualInstallModal"
                    >
                        <FolderUp class="h-4 w-4" />
                        Instalar plugin (ZIP)
                    </button>
                </div>
                <p class="mb-6 text-sm text-zinc-600 dark:text-zinc-400">
                    Ative ou desative plugins. Plugins desativados não carregam rotas nem eventos. Envie um ZIP com uma pasta raiz contendo
                    <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">plugin.json</code>
                    para instalar manualmente.
                </p>
                <div
                    v-if="plugins.length === 0"
                    class="rounded-xl border border-zinc-200 bg-zinc-50 p-6 text-center text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-400"
                >
                    Nenhum plugin encontrado na pasta
                    <code class="rounded bg-zinc-200 px-1.5 py-0.5 text-sm dark:bg-zinc-700">plugins/</code>.
                </div>
                <div
                    v-else
                    class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                >
                    <div
                        v-for="plugin in plugins"
                        :key="plugin.slug"
                        class="flex flex-col overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm transition-all duration-200 hover:border-zinc-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600"
                    >
                        <!-- Banner do plugin (demonstração; não é o logo do gateway em Configurações) -->
                        <div
                            class="relative aspect-[2/1] w-full shrink-0 overflow-hidden rounded-t-2xl border-b border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/80"
                        >
                            <Puzzle
                                v-show="!plugin.banner_url || bannerLoadFailed[plugin.slug]"
                                class="absolute inset-0 m-auto h-14 w-14 text-zinc-400 dark:text-zinc-500"
                                aria-hidden="true"
                            />
                            <img
                                v-if="plugin.banner_url && !bannerLoadFailed[plugin.slug]"
                                :src="plugin.banner_url"
                                :alt="plugin.name"
                                class="absolute inset-0 h-full w-full object-cover object-center"
                                @error="setBannerFailed(plugin.slug)"
                            />
                        </div>
                        <div class="flex flex-1 flex-col gap-3 p-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="font-semibold text-zinc-900 dark:text-white">
                                    {{ plugin.name }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                    v{{ plugin.version }}
                                </span>
                                <span
                                    v-if="!plugin.is_registered"
                                    class="rounded-md px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300"
                                >
                                    Pendente de instalação
                                </span>
                            </div>
                            <p
                                v-if="plugin.description"
                                class="line-clamp-2 flex-1 text-sm text-zinc-600 dark:text-zinc-400"
                            >
                                {{ plugin.description }}
                            </p>
                            <span
                                class="inline-flex w-fit rounded-md px-2 py-0.5 text-xs font-medium bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300"
                            >
                                {{ categoryLabel(plugin.category) }}
                            </span>
                            <div class="flex flex-wrap items-center gap-2 pt-1">
                                <a
                                    v-if="plugin.settings_url"
                                    :href="plugin.settings_url"
                                    class="inline-flex items-center gap-1 text-xs text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                                >
                                    <ExternalLink class="h-3.5 w-3.5" />
                                    Configurar
                                </a>
                                <button
                                    v-if="plugin.type === 'gateway' && plugin.is_enabled"
                                    type="button"
                                    class="inline-flex items-center gap-1 text-xs text-[var(--color-primary)] hover:underline"
                                    @click="goToGateways"
                                >
                                    <CreditCard class="h-3.5 w-3.5" />
                                    Gateways
                                </button>
                            </div>
                            <div class="mt-auto flex flex-wrap items-center gap-2">
                                <template v-if="plugin.is_registered">
                                    <Button
                                        v-if="plugin.is_enabled"
                                        variant="outline"
                                        size="sm"
                                        class="w-full sm:w-auto"
                                        @click="disablePlugin(plugin.slug)"
                                    >
                                        <PowerOff class="mr-1 h-4 w-4" />
                                        Desativar
                                    </Button>
                                    <Button
                                        v-else
                                        size="sm"
                                        class="w-full sm:w-auto"
                                        @click="enablePlugin(plugin.slug)"
                                    >
                                        <Power class="mr-1 h-4 w-4" />
                                        Ativar
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="text-red-600 border-red-200 hover:bg-red-50 dark:border-red-800 dark:text-red-400 dark:hover:bg-red-900/20"
                                        :disabled="uninstallingSlug === plugin.slug"
                                        @click="uninstallPlugin(plugin)"
                                    >
                                        <Trash2 v-if="uninstallingSlug !== plugin.slug" class="mr-1 h-4 w-4" />
                                        <span v-else class="mr-1">...</span>
                                        {{ uninstallingSlug === plugin.slug ? 'Excluindo...' : 'Excluir' }}
                                    </Button>
                                </template>
                                <template v-else>
                                    <Button
                                        size="sm"
                                        class="w-full sm:w-auto"
                                        :disabled="registeringSlug === plugin.slug"
                                        @click="registerPlugin(plugin.slug)"
                                    >
                                        <Download v-if="registeringSlug !== plugin.slug" class="mr-1 h-4 w-4" />
                                        <span v-else class="mr-1 inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                                        {{ registeringSlug === plugin.slug ? 'Instalando...' : 'Instalar' }}
                                    </Button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </template>

        <!-- Aba Loja de plugins (catálogo em breve; use ZIP na aba Instalados) -->
        <template v-if="currentTab === 'store'">
            <section class="relative min-h-[280px] overflow-hidden rounded-2xl border border-zinc-200 bg-zinc-50/80 dark:border-zinc-700 dark:bg-zinc-900/40">
                <div
                    class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center bg-white/85 px-6 text-center backdrop-blur-[2px] dark:bg-zinc-950/80"
                >
                    <Package class="mb-4 h-14 w-14 text-zinc-400 dark:text-zinc-500" aria-hidden="true" />
                    <p class="text-2xl font-semibold text-zinc-800 dark:text-zinc-100">Em breve</p>
                    <p class="mt-2 max-w-md text-sm text-zinc-600 dark:text-zinc-400">
                        A loja de plugins estará disponível em breve. Enquanto isso, use a aba
                        <strong class="font-medium text-zinc-800 dark:text-zinc-200">Instalados</strong>
                        e o botão <strong class="font-medium text-zinc-800 dark:text-zinc-200">Instalar plugin (ZIP)</strong>.
                    </p>
                    <a
                        v-if="pluginStore?.submit_url"
                        :href="pluginStore.submit_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="pointer-events-auto mt-6 inline-flex items-center gap-2 text-sm text-[var(--color-primary)] hover:underline"
                    >
                        <Upload class="h-4 w-4" />
                        Subir meu plugin para loja
                    </a>
                </div>
            </section>
        </template>

        <!-- Modal: extensão Zip não disponível (fallback) -->
        <Teleport to="body">
            <div
                v-if="showZipUnavailableModal"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
                @click.self="closeZipUnavailableModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="zip-unavailable-title"
                >
                    <h3 id="zip-unavailable-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Extensão PHP Zip não disponível
                    </h3>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        A instalação automática precisa da extensão Zip no PHP. Use uma das opções abaixo.
                    </p>

                    <div class="mt-4 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                            Extrair manualmente no servidor
                        </p>
                        <ol class="mt-2 list-decimal space-y-1 pl-4 text-sm text-zinc-600 dark:text-zinc-400">
                            <li>Obtenha o ZIP do plugin (use o botão abaixo para gerar o link).</li>
                            <li>No painel da sua hospedagem (gerenciador de arquivos, FTP etc.), acesse a pasta de plugins do projeto.</li>
                            <li>Envie o ZIP para essa pasta ou baixe o arquivo direto do link para o servidor (muitos painéis têm “Baixar de URL”).</li>
                            <li>Extraia o ZIP nessa mesma pasta. O resultado deve ser uma pasta que contém o arquivo <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">plugin.json</code>.</li>
                            <li>
                                Instalações via painel ou ZIP — pasta persistente (não é apagada ao atualizar o código a partir do Git):
                                <code class="mt-1 block break-all rounded bg-zinc-200 px-2 py-1 text-xs dark:bg-zinc-700">{{ plugins_install_path || pluginsPath || 'plugins-installed/' }}</code>
                            </li>
                            <li>
                                Plugins incluídos no repositório (exemplo) ficam em:
                                <code class="mt-1 block break-all rounded bg-zinc-200 px-2 py-1 text-xs dark:bg-zinc-700">{{ plugins_bundled_path || 'plugins/' }}</code>
                            </li>
                            <li>Atualize esta página para o plugin aparecer.</li>
                        </ol>
                    </div>

                    <p v-if="downloadFallbackError" class="mt-2 text-sm text-red-600 dark:text-red-400">
                        {{ downloadFallbackError }}
                    </p>
                    <div class="mt-6 flex flex-wrap gap-2">
                        <Button
                            v-if="lastInstallSlug"
                            size="sm"
                            :disabled="downloadFallbackLoading"
                            @click="downloadPluginFallback"
                        >
                            <Download v-if="!downloadFallbackLoading" class="mr-1 h-4 w-4" />
                            <span v-else class="mr-1 inline-block h-4 w-4 animate-spin rounded-full border-2 border-current border-t-transparent" />
                            {{ downloadFallbackLoading ? 'Gerando link...' : 'Baixar plugin (ZIP)' }}
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="downloadFallbackLoading"
                            @click="openManualInstallModal"
                        >
                            <FolderUp class="mr-1 h-4 w-4" />
                            Instalar manualmente (enviar ZIP)
                        </Button>
                        <Button variant="outline" size="sm" @click="closeZipUnavailableModal">
                            Fechar
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal: instalar plugin manualmente (upload ZIP) -->
        <Teleport to="body">
            <div
                v-if="showManualInstallModal"
                class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4"
                @click.self="closeManualInstallModal"
            >
                <div
                    class="w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-800"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="manual-install-title"
                >
                    <h3 id="manual-install-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Instalar plugin manualmente
                    </h3>
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                        Envie o arquivo .zip do plugin. O nome da pasta do plugin será detectado automaticamente (pasta raiz dentro do ZIP).
                    </p>
                    <form @submit.prevent="submitManualInstall" class="mt-4 space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Arquivo ZIP</label>
                            <input
                                ref="manualInstallFileInput"
                                type="file"
                                accept=".zip"
                                class="mt-1 w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-900 dark:text-white file:mr-2 file:rounded file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:text-zinc-700 dark:file:bg-zinc-700 dark:file:text-zinc-300"
                                @change="manualInstallError = ''"
                            />
                        </div>
                        <p v-if="manualInstallError" class="text-sm text-red-600 dark:text-red-400">
                            {{ manualInstallError }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Button type="submit" size="sm" :disabled="manualInstallProcessing">
                                {{ manualInstallProcessing ? 'Instalando...' : 'Instalar' }}
                            </Button>
                            <Button type="button" variant="outline" size="sm" @click="closeManualInstallModal">
                                Cancelar
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
