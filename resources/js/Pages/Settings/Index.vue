<script setup>
import { ref, computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Mail, Languages, Banknote, HardDrive, Clock, AlertCircle, Trash2, RefreshCw, Upload, Download } from 'lucide-vue-next';
import IntegrationCard from '@/components/IntegrationCard.vue';
import EmailProviderSidebar from '@/components/EmailProviderSidebar.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    settings: {
        type: Object,
        required: true,
    },
    current_version: {
        type: String,
        default: '1.0.0',
    },
    updates_enabled: {
        type: Boolean,
        default: true,
    },
    git_available: {
        type: Boolean,
        default: false,
    },
    cloud_mode: {
        type: Boolean,
        default: false,
    },
    docker_mode: {
        type: Boolean,
        default: false,
    },
    app_url: {
        type: String,
        default: '',
    },
    base_path: {
        type: String,
        default: '',
    },
    cron_url: {
        type: String,
        default: null,
    },
});

const allowedTabs = ['email', 'storage', 'traducoes', 'moedas', 'cron', 'update'];
const activeTab = ref('email');
if (typeof window !== 'undefined') {
    const t = new URLSearchParams(window.location.search).get('tab');
    if (allowedTabs.includes(t)) activeTab.value = t;
}

const defaultTranslations = () => ({
    pt_BR: {},
    en: {},
    es: {},
    ...(props.settings.checkout_translations ?? {}),
});
const defaultCurrencies = () => [...(props.settings.currencies ?? [])];

const form = useForm({
    smtp_host: props.settings.smtp_host ?? '',
    smtp_port: props.settings.smtp_port ?? '587',
    smtp_username: props.settings.smtp_username ?? '',
    smtp_encryption: props.settings.smtp_encryption ?? 'tls',
    smtp_password: '', // never pre-fill password
    mail_from_address: props.settings.mail_from_address ?? '',
    mail_from_name: props.settings.mail_from_name ?? '',
    reply_to: props.settings.reply_to ?? '',
    email_provider: props.settings.email_provider ?? 'smtp',
    hostinger_smtp_username: props.settings.hostinger_smtp_username ?? '',
    hostinger_smtp_password: '', // never pre-fill password
    hostinger_mail_from_address: props.settings.hostinger_mail_from_address ?? '',
    hostinger_mail_from_name: props.settings.hostinger_mail_from_name ?? '',
    hostinger_reply_to: props.settings.hostinger_reply_to ?? '',
    sendgrid_api_key: '', // never pre-fill API key
    sendgrid_mail_from_address: props.settings.sendgrid_mail_from_address ?? '',
    sendgrid_mail_from_name: props.settings.sendgrid_mail_from_name ?? '',
    checkout_translations: defaultTranslations(),
    currencies: defaultCurrencies(),
    storage_provider: props.settings.storage_provider ?? 'local',
    storage_s3_key: props.settings.storage_s3_key ?? '',
    storage_s3_secret: '', // never pre-fill
    storage_s3_bucket: props.settings.storage_s3_bucket ?? '',
    storage_s3_region: props.settings.storage_provider === 'r2' ? 'auto' : (props.settings.storage_s3_region ?? 'us-east-1'),
    storage_s3_endpoint: props.settings.storage_s3_endpoint ?? '',
    storage_s3_url: props.settings.storage_s3_url ?? '',
});

const testForm = useForm({
    test_to: '',
});

import { ref as vueRef } from 'vue';
const connectionResult = vueRef({ status: null, message: '' });
const sendResult = vueRef({ status: null, message: '' });
const connectionTesting = vueRef(false);
const sendTestSending = vueRef(false);

const tabs = [
    { id: 'email', label: 'E‑MAIL', icon: Mail },
    { id: 'storage', label: 'Storage', icon: HardDrive },
    { id: 'traducoes', label: 'Traduções', icon: Languages },
    { id: 'moedas', label: 'Moedas', icon: Banknote },
    { id: 'cron', label: 'Cron', icon: Clock },
    { id: 'update', label: 'Update', icon: Download },
];

const updateCheckLoading = ref(false);
const updateCheckResult = ref(null);
const updateRunLoading = ref(false);
const integrityLoading = ref(false);
const integrityResult = ref(null);

async function checkForUpdate() {
    updateCheckLoading.value = true;
    updateCheckResult.value = null;
    try {
        const res = await window.axios.get('/configuracoes/update/check');
        updateCheckResult.value = res.data;
    } catch (e) {
        updateCheckResult.value = {
            current: props.current_version,
            latest: null,
            available: false,
            error: e?.response?.data?.message || 'Erro ao verificar atualizações.',
            changelog_remote: null,
        };
    } finally {
        updateCheckLoading.value = false;
    }
}

async function runUpdate() {
    updateRunLoading.value = true;
    try {
        const res = await window.axios.post('/configuracoes/update/run', {}, {
            headers: { Accept: 'application/json' },
            maxRedirects: 0,
            validateStatus: (status) => status >= 200 && status < 400,
        });
        if (res.data?.success) {
            window.location.href = res.data.redirect || '/configuracoes?tab=update';
            return;
        }
        const msg = res.data?.message || 'Falha na atualização.';
        if (typeof window.router !== 'undefined') {
            window.router.visit('/configuracoes?tab=update', { preserveState: false });
        } else {
            window.location.href = '/configuracoes?tab=update';
        }
        setTimeout(() => { alert(msg); }, 100);
    } catch (e) {
        const status = e?.response?.status;
        const msg =
            status === 429
                ? 'Muitas tentativas em pouco tempo. Aguarde e tente novamente.'
                : (e?.response?.data?.message || e?.response?.data?.error || e?.message || 'Falha na atualização.');
        alert(msg);
    } finally {
        updateRunLoading.value = false;
    }
}

async function checkIntegrity() {
    integrityLoading.value = true;
    integrityResult.value = null;
    try {
        const res = await window.axios.get('/configuracoes/update/integrity');
        integrityResult.value = res.data;
    } catch (e) {
        integrityResult.value = {
            repository_exists: null,
            total_migrations: 0,
            ran_count: 0,
            pending_count: 0,
            pending: [],
            pending_truncated: false,
            error: e?.response?.data?.message || e?.response?.data?.error || 'Erro ao verificar integridade.',
        };
    } finally {
        integrityLoading.value = false;
    }
}

const translationKeys = computed(() => {
    const t = form.checkout_translations ?? {};
    const keys = new Set([
        ...Object.keys(t.pt_BR ?? {}),
        ...Object.keys(t.en ?? {}),
        ...Object.keys(t.es ?? {}),
    ]);
    return [...keys].sort();
});

const localeLabels = { pt_BR: 'Português (BR)', en: 'English', es: 'Español' };

function ensureTranslationKey(key) {
    if (!form.checkout_translations.pt_BR) form.checkout_translations.pt_BR = {};
    if (!form.checkout_translations.en) form.checkout_translations.en = {};
    if (!form.checkout_translations.es) form.checkout_translations.es = {};
    if (form.checkout_translations.pt_BR[key] === undefined) form.checkout_translations.pt_BR[key] = '';
    if (form.checkout_translations.en[key] === undefined) form.checkout_translations.en[key] = '';
    if (form.checkout_translations.es[key] === undefined) form.checkout_translations.es[key] = '';
}

const CURRENCY_PRESETS = {
    BRL: { symbol: 'R$', label: 'Real brasileiro' },
    USD: { symbol: 'US$', label: 'Dólar americano' },
    EUR: { symbol: '€', label: 'Euro' },
    GBP: { symbol: '£', label: 'Libra esterlina' },
    ARS: { symbol: '$', label: 'Peso argentino' },
    CAD: { symbol: 'C$', label: 'Dólar canadense' },
    CLP: { symbol: '$', label: 'Peso chileno' },
    COP: { symbol: '$', label: 'Peso colombiano' },
    MXN: { symbol: '$', label: 'Peso mexicano' },
    PYG: { symbol: '₲', label: 'Guarani paraguaio' },
    UYU: { symbol: '$', label: 'Peso uruguaio' },
};

const rateModeByIndex = ref({});
const refreshLoadingByIndex = ref({});
const rateFetchError = ref(null);

function getRateMode(index) {
    return rateModeByIndex.value[index] ?? 'brl_to';
}

function setRateMode(index, mode) {
    rateModeByIndex.value = { ...rateModeByIndex.value, [index]: mode };
}

function inverseRate(rate) {
    const r = Number(rate);
    return r > 0 ? (1 / r) : '';
}

function setRateFromInverse(curr, value) {
    const v = parseFloat(String(value).replace(',', '.'));
    curr.rate_to_brl = v > 0 ? 1 / v : 0;
}

function applyPreset(curr) {
    const code = String(curr.code || '').trim().toUpperCase();
    const preset = CURRENCY_PRESETS[code];
    if (preset) {
        if (!curr.symbol) curr.symbol = preset.symbol;
        if (!curr.label) curr.label = preset.label;
    }
}

function onCurrencyCodeChange(curr, index) {
    curr.code = String(curr.code || '').toUpperCase();
    applyPreset(curr);
}

async function fetchRate(curr, index) {
    const code = String(curr.code || '').trim().toUpperCase();
    if (!code || code === 'BRL') return;
    refreshLoadingByIndex.value = { ...refreshLoadingByIndex.value, [index]: true };
    rateFetchError.value = null;
    try {
        const res = await fetch(`https://api.frankfurter.app/latest?from=BRL&to=${code}`);
        const data = await res.json();
        if (data.rates && typeof data.rates[code] === 'number') {
            curr.rate_to_brl = data.rates[code];
        } else {
            rateFetchError.value = 'Moeda não suportada pela API.';
        }
    } catch (e) {
        rateFetchError.value = 'Erro ao buscar taxa. Verifique a conexão.';
    } finally {
        refreshLoadingByIndex.value = { ...refreshLoadingByIndex.value, [index]: false };
    }
}

function canFetchRate(curr) {
    const code = String(curr.code || '').trim().toUpperCase();
    return code && code !== 'BRL';
}

function addCurrency() {
    form.currencies.push({ code: '', symbol: '', label: '', rate_to_brl: 1 });
}

function removeCurrency(index) {
    form.currencies.splice(index, 1);
    const next = { ...rateModeByIndex.value };
    delete next[index];
    rateModeByIndex.value = next;
}

async function testConnection() {
    testForm.clearErrors();
    connectionResult.value.status = null;
    connectionResult.value.message = '';
    connectionTesting.value = true;
    const provider = form.email_provider || 'smtp';
    const payload = { email_provider: provider };
    if (provider === 'hostinger') {
        payload.hostinger_smtp_username = form.hostinger_smtp_username;
        payload.hostinger_smtp_password = form.hostinger_smtp_password;
    } else if (provider === 'sendgrid') {
        payload.sendgrid_api_key = form.sendgrid_api_key;
        payload.sendgrid_mail_from_address = form.sendgrid_mail_from_address;
        payload.sendgrid_mail_from_name = form.sendgrid_mail_from_name;
    } else {
        payload.smtp_host = form.smtp_host;
        payload.smtp_port = form.smtp_port;
        payload.smtp_username = form.smtp_username;
        payload.smtp_password = form.smtp_password;
        payload.smtp_encryption = form.smtp_encryption;
    }
    try {
        await window.axios.post('/configuracoes/email/connection-test', payload);
        connectionResult.value.status = 'success';
        connectionResult.value.message = 'Conexão estabelecida com sucesso.';
    } catch (e) {
        connectionResult.value.status = 'error';
        let msg = 'Erro ao testar conexão.';
        if (testForm.errors && Object.keys(testForm.errors).length) {
            msg = Object.values(testForm.errors).flat().join(' ');
        } else if (e && e.response && e.response.data && e.response.data.error) {
            msg = e.response.data.error;
        }
        connectionResult.value.message = msg;
    } finally {
        connectionTesting.value = false;
    }
}

async function sendTestEmail() {
    testForm.clearErrors();
    sendTestSending.value = true;
    const provider = form.email_provider || 'smtp';
    const payload = { test_to: testForm.test_to, email_provider: provider };
    if (provider === 'hostinger') {
        payload.hostinger_smtp_username = form.hostinger_smtp_username;
        payload.hostinger_smtp_password = form.hostinger_smtp_password;
    } else if (provider === 'sendgrid') {
        payload.sendgrid_api_key = form.sendgrid_api_key;
        payload.sendgrid_mail_from_address = form.sendgrid_mail_from_address;
        payload.sendgrid_mail_from_name = form.sendgrid_mail_from_name;
    } else {
        payload.smtp_host = form.smtp_host;
        payload.smtp_port = form.smtp_port;
        payload.smtp_username = form.smtp_username;
        payload.smtp_password = form.smtp_password;
        payload.smtp_encryption = form.smtp_encryption;
    }
    try {
        await window.axios.post('/configuracoes/email/send-test', payload);
        sendResult.value.status = 'success';
        sendResult.value.message = 'E‑mail de teste enviado com sucesso.';
        setTimeout(() => {
            sendResult.value.status = null;
            sendResult.value.message = '';
        }, 4000);
    } catch (e) {
        sendResult.value.status = 'error';
        let msg = 'Erro ao enviar e‑mail de teste.';
        if (e && e.response && e.response.data && e.response.data.error) {
            msg = e.response.data.error;
        }
        sendResult.value.message = msg;
        setTimeout(() => {
            sendResult.value.status = null;
            sendResult.value.message = '';
        }, 6000);
    } finally {
        sendTestSending.value = false;
    }
}

const storageProviders = [
    { id: 'local', label: 'Local', description: 'Arquivos em storage/app/public (padrão)' },
    { id: 's3', label: 'AWS S3', description: 'Amazon Simple Storage Service', endpoint: '' },
    { id: 'wasabi', label: 'Wasabi', description: 'S3-compatível', endpoint: 'https://s3.wasabisys.com' },
    { id: 'r2', label: 'Cloudflare R2', description: 'S3-compatível sem egress', endpoint: 'https://ACCOUNT_ID.r2.cloudflarestorage.com' },
];

const storageTestResult = vueRef({ status: null, message: '' });
const storageTestLoading = vueRef(false);
const storageMigrateLoading = vueRef(false);

async function testStorageConnection() {
    storageTestResult.value = { status: null, message: '' };
    const provider = form.storage_provider;
    if (provider !== 'local') {
        const key = (form.storage_s3_key ?? '').trim();
        const bucket = (form.storage_s3_bucket ?? '').trim();
        if (!key || !bucket) {
            storageTestResult.value = {
                status: 'error',
                message: 'Preencha Access Key e Bucket para testar a conexão. O Secret Key pode ficar em branco se já tiver sido salvo antes.',
            };
            return;
        }
    }
    storageTestLoading.value = true;
    const region =
        provider === 'r2' ? 'auto' : (form.storage_s3_region && form.storage_s3_region.trim()) || 'us-east-1';
    const payload = {
        storage_provider: provider,
        storage_s3_key: form.storage_s3_key ?? '',
        storage_s3_secret: form.storage_s3_secret ?? '',
        storage_s3_bucket: form.storage_s3_bucket ?? '',
        storage_s3_region: region,
        storage_s3_endpoint: form.storage_s3_endpoint ?? '',
    };
    try {
        const res = await window.axios.post('/configuracoes/storage/test', payload);
        storageTestResult.value = { status: 'success', message: res.data.message || 'Conexão estabelecida com sucesso.' };
    } catch (e) {
        const data = e?.response?.data;
        let message = data?.message || data?.error || 'Erro ao testar conexão.';
        if (data?.errors && typeof data.errors === 'object') {
            const firstError = Object.values(data.errors).flat().find(Boolean);
            if (firstError) message = firstError;
        }
        storageTestResult.value = { status: 'error', message };
    } finally {
        storageTestLoading.value = false;
    }
}

function onStorageProviderChange(providerId) {
    form.storage_provider = providerId;
    const prov = storageProviders.find((p) => p.id === providerId);
    if (prov?.endpoint && !form.storage_s3_endpoint) {
        form.storage_s3_endpoint = prov.endpoint;
    }
    if (providerId === 'r2') {
        form.storage_s3_region = 'auto';
    }
}

const isStorageRemote = computed(
    () =>
        form.storage_provider === 's3' ||
        form.storage_provider === 'wasabi' ||
        form.storage_provider === 'r2',
);
const canMigrateStorage = computed(
    () =>
        isStorageRemote.value &&
        (form.storage_s3_key ?? '').trim() !== '' &&
        (form.storage_s3_bucket ?? '').trim() !== '',
);

async function migrateStorageToRemote() {
    storageTestResult.value = { status: null, message: '' };
    storageMigrateLoading.value = true;
    try {
        const res = await window.axios.post('/configuracoes/storage/migrate');
        const d = res.data;
        storageTestResult.value = {
            status: 'success',
            message: d.message || `${d.transferred ?? 0} arquivo(s) transferido(s) com sucesso.`,
        };
    } catch (e) {
        const data = e?.response?.data;
        let message = data?.message || data?.error || 'Erro ao transferir arquivos.';
        if (data?.errors && Array.isArray(data.errors) && data.errors[0]?.message) {
            message += ' ' + data.errors[0].message;
        }
        storageTestResult.value = { status: 'error', message };
    } finally {
        storageMigrateLoading.value = false;
    }
}

const providers = [
    {
        id: 'smtp',
        title: 'SMTP',
        logo: '/images/integrations/smtp.svg',
        description: 'Configuração SMTP',
    },
    {
        id: 'hostinger',
        title: 'Hostinger Mail',
        logo: '/images/integrations/hostinger.webp',
        description: 'Configuração Hostinger',
        defaults: { smtp_host: 'smtp.hostinger.com', smtp_port: '465', smtp_encryption: 'ssl' },
    },
    {
        id: 'sendgrid',
        title: 'SendGrid',
        logo: '/images/integrations/twillio-sendgrid.jpg',
        description: 'Envio via API Key SendGrid',
    },
];

const selectedProviderId = ref(form.email_provider || 'smtp');
const sidebarOpen = ref(false);
const selectedProvider = ref(null);

function selectProvider(provider) {
    selectedProviderId.value = provider.id;
    form.email_provider = provider.id;
}

function openProviderConfig(provider) {
    selectedProvider.value = provider;
    sidebarOpen.value = true;
}

function closeSidebar() {
    sidebarOpen.value = false;
}

function saveFromSidebar() {
    form.put('/configuracoes', {
        preserveScroll: true,
        onSuccess: () => closeSidebar(),
    });
}

function isProviderConfigured(providerId) {
    if (providerId === 'smtp') {
        return !!(form.smtp_host && form.smtp_username);
    }
    if (providerId === 'hostinger') {
        return !!form.hostinger_smtp_username;
    }
    if (providerId === 'sendgrid') {
        return !!form.sendgrid_mail_from_address;
    }
    return false;
}

function copyToClipboard(text) {
    try {
        navigator.clipboard?.writeText(text);
    } catch (_) {}
}

const cronLinuxLine = computed(() => {
    const path = props.base_path && typeof props.base_path === 'string' ? props.base_path : '/caminho/do/projeto';
    return `* * * * * cd ${path} && php artisan schedule:run >> /dev/null 2>&1`;
});

const cronCurlLine = computed(() => {
    if (!props.cron_url) return '';
    return `* * * * * curl -fsS "${props.cron_url}" > /dev/null 2>&1`;
});

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';
const selectClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';
</script>

<template>
    <div class="space-y-6">
        <!-- Header -->
        <div>
            <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Configurações</h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Gerencie e-mail, traduções do checkout e moedas disponíveis.
            </p>
        </div>

        <!-- Tabs pill style -->
        <nav
            class="inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
            aria-label="Abas de configurações"
        >
            <button
                v-for="tab in tabs"
                :key="tab.id"
                type="button"
                :aria-current="activeTab === tab.id ? 'page' : undefined"
                :class="[
                    'flex items-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                    activeTab === tab.id
                        ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                        : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                ]"
                @click="activeTab = tab.id"
            >
                <component :is="tab.icon" class="h-4 w-4 shrink-0" aria-hidden="true" />
                {{ tab.label }}
            </button>
        </nav>

        <form v-show="activeTab !== 'update' && activeTab !== 'cron'" class="w-full max-w-full space-y-6" @submit.prevent="form.put('/configuracoes')">
            <!-- Aba E-MAIL -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'email'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <h2 class="mb-2 text-base font-semibold text-zinc-900 dark:text-white">Provedores de e-mail</h2>
                        <p class="mb-5 text-sm text-zinc-600 dark:text-zinc-400">
                            Escolha o provedor de e-mail para envio de acessos, notificações e recuperação de senha.
                        </p>
                        <div class="grid gap-4 sm:grid-cols-2 md:grid-cols-3">
                            <IntegrationCard
                                v-for="prov in providers"
                                :key="prov.id"
                                :title="prov.title"
                                :logo="prov.logo"
                                :description="prov.description"
                                :selected="prov.id === selectedProviderId"
                                :configured="isProviderConfigured(prov.id)"
                                @select="selectProvider(prov)"
                                @configure="openProviderConfig(prov)"
                            />
                        </div>
                        <div
                            v-if="selectedProviderId && !isProviderConfigured(selectedProviderId)"
                            class="mt-5 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-900/20"
                        >
                            <AlertCircle class="h-5 w-5 shrink-0 text-amber-600 dark:text-amber-400" />
                            <p class="text-sm text-amber-800 dark:text-amber-200">
                                Clique no ícone de engrenagem para configurar o provedor selecionado.
                            </p>
                        </div>
                    </section>
                </div>
            </Transition>

            <!-- Aba Storage -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'storage'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Storage de arquivos</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Configure onde as imagens da plataforma serão armazenadas (produtos, checkout, área de membros, avatares).
                            </p>
                        </div>
                        <div class="space-y-6 p-6">
                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Provedor</label>
                                <div class="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                                    <button
                                        v-for="prov in storageProviders"
                                        :key="prov.id"
                                        type="button"
                                        :class="[
                                            'rounded-xl border-2 p-4 text-left transition',
                                            form.storage_provider === prov.id
                                                ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 dark:bg-[var(--color-primary)]/10'
                                                : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500',
                                        ]"
                                        @click="onStorageProviderChange(prov.id)"
                                    >
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ prov.label }}</p>
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ prov.description }}</p>
                                    </button>
                                </div>
                            </div>

                            <div v-if="form.storage_provider !== 'local'" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-600 dark:bg-zinc-800/50">
                                <h3 class="text-sm font-medium text-zinc-900 dark:text-white">Credenciais S3</h3>
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Access Key</label>
                                        <input
                                            v-model="form.storage_s3_key"
                                            type="text"
                                            :class="inputClass"
                                            placeholder="AKIA..."
                                            autocomplete="off"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Secret Key</label>
                                        <input
                                            v-model="form.storage_s3_secret"
                                            type="password"
                                            :class="inputClass"
                                            placeholder="••••••••"
                                            autocomplete="new-password"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Bucket</label>
                                        <input
                                            v-model="form.storage_s3_bucket"
                                            type="text"
                                            :class="inputClass"
                                            placeholder="meu-bucket"
                                        />
                                    </div>
                                    <div v-if="form.storage_provider !== 'r2'">
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Region</label>
                                        <input
                                            v-model="form.storage_s3_region"
                                            type="text"
                                            :class="inputClass"
                                            placeholder="us-east-1"
                                        />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Endpoint (R2: https://ACCOUNT_ID.r2.cloudflarestorage.com)</label>
                                        <input
                                            v-model="form.storage_s3_endpoint"
                                            type="text"
                                            :class="inputClass"
                                            placeholder="https://s3.wasabisys.com ou vazio para AWS"
                                        />
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">URL pública opcional (CDN ou domínio customizado)</label>
                                        <input
                                            v-model="form.storage_s3_url"
                                            type="text"
                                            :class="inputClass"
                                            placeholder="https://cdn.exemplo.com"
                                        />
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-3 pt-2">
                                    <button
                                        type="button"
                                        :disabled="storageTestLoading"
                                        class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)]"
                                        @click="testStorageConnection"
                                    >
                                        <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': storageTestLoading }" />
                                        {{ storageTestLoading ? 'Testando...' : 'Testar conexão' }}
                                    </button>
                                    <button
                                        v-if="isStorageRemote"
                                        type="button"
                                        :disabled="storageMigrateLoading || !canMigrateStorage"
                                        class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)]"
                                        title="Salve as configurações antes de transferir."
                                        @click="migrateStorageToRemote"
                                    >
                                        <Upload class="h-4 w-4" :class="{ 'animate-pulse': storageMigrateLoading }" />
                                        {{ storageMigrateLoading ? 'Transferindo...' : 'Transferir arquivos do storage local para o S3/R2' }}
                                    </button>
                                    <p
                                        v-if="storageTestResult.status"
                                        :class="[
                                            'text-sm',
                                            storageTestResult.status === 'success'
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : 'text-red-600 dark:text-red-400',
                                        ]"
                                    >
                                        {{ storageTestResult.message }}
                                    </p>
                                </div>
                            </div>
                            <div v-else class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    Os arquivos serão salvos em <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-700">storage/app/public</code> e servidos via <code class="rounded bg-zinc-200 px-1 py-0.5 text-xs dark:bg-zinc-700">/storage</code>.
                                </p>
                            </div>
                        </div>
                    </section>
                </div>
            </Transition>

            <!-- Aba Traduções -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'traducoes'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Checkout – textos por idioma</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Edite os textos exibidos no checkout. Português (BR), English, Español.
                            </p>
                        </div>
                        <div class="overflow-x-auto p-6 pt-0">
                            <div
                                v-if="translationKeys.length === 0"
                                class="rounded-xl border border-zinc-200 border-dashed bg-zinc-50 px-8 py-12 text-center dark:border-zinc-600 dark:bg-zinc-800/50"
                            >
                                <Languages class="mx-auto h-12 w-12 text-zinc-400 dark:text-zinc-500" />
                                <p class="mt-3 text-sm font-medium text-zinc-600 dark:text-zinc-400">Nenhuma chave de tradução</p>
                                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-500">
                                    As chaves padrão são carregadas automaticamente ao acessar o checkout.
                                </p>
                            </div>
                            <div v-else class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700">
                                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                                        <tr>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Chave</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Português (BR)</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">English</th>
                                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Español</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                        <tr
                                            v-for="key in translationKeys"
                                            :key="key"
                                            class="bg-white transition hover:bg-zinc-50 dark:bg-zinc-800/60 dark:hover:bg-zinc-700/80"
                                        >
                                            <td class="whitespace-nowrap px-4 py-3 font-mono text-xs text-zinc-500 dark:text-zinc-400 align-top">{{ key }}</td>
                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    v-model="form.checkout_translations.pt_BR[key]"
                                                    type="text"
                                                    :class="inputClass + ' text-sm py-2'"
                                                    @focus="ensureTranslationKey(key)"
                                                />
                                            </td>
                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    v-model="form.checkout_translations.en[key]"
                                                    type="text"
                                                    :class="inputClass + ' text-sm py-2'"
                                                    @focus="ensureTranslationKey(key)"
                                                />
                                            </td>
                                            <td class="px-4 py-3 align-top">
                                                <input
                                                    v-model="form.checkout_translations.es[key]"
                                                    type="text"
                                                    :class="inputClass + ' text-sm py-2'"
                                                    @focus="ensureTranslationKey(key)"
                                                />
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </section>
                </div>
            </Transition>

            <!-- Aba Moedas -->
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="opacity-0"
                enter-to-class="opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="opacity-100"
                leave-to-class="opacity-0"
            >
                <div v-show="activeTab === 'moedas'" class="space-y-6">
                    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                            <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Moedas disponíveis no checkout</h2>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Configure as moedas e suas taxas de conversão. Use o botão "Buscar taxa" para atualizar automaticamente.
                            </p>
                        </div>
                        <div v-if="rateFetchError" class="mx-6 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-800 dark:border-amber-800/50 dark:bg-amber-900/20 dark:text-amber-200">
                            {{ rateFetchError }}
                        </div>
                        <div class="space-y-4 p-6">
                            <div class="grid gap-4 sm:grid-cols-1 md:grid-cols-2">
                                <div
                                    v-for="(curr, index) in form.currencies"
                                    :key="index"
                                    class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
                                >
                                    <div class="flex flex-wrap items-end gap-3">
                                        <div class="w-24 shrink-0">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Código</label>
                                            <input
                                                v-model="curr.code"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="BRL"
                                                maxlength="10"
                                                @blur="onCurrencyCodeChange(curr, index)"
                                            />
                                        </div>
                                        <div class="w-20 shrink-0">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Símbolo</label>
                                            <input
                                                v-model="curr.symbol"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="R$"
                                            />
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome</label>
                                            <input
                                                v-model="curr.label"
                                                type="text"
                                                :class="inputClass"
                                                placeholder="Real brasileiro"
                                            />
                                        </div>
                                        <button
                                            type="button"
                                            class="ml-auto shrink-0 rounded-lg p-2 text-zinc-500 transition hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-500/20 dark:hover:bg-red-900/20 dark:hover:text-red-400"
                                            :aria-label="'Remover moeda ' + (curr.code || 'sem código')"
                                            @click="removeCurrency(index)"
                                        >
                                            <Trash2 class="h-4 w-4" />
                                        </button>
                                    </div>
                                    <div class="space-y-2 border-t border-zinc-200 pt-3 dark:border-zinc-600">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Formato:</span>
                                            <button
                                                type="button"
                                                :class="[
                                                    'rounded-lg px-2.5 py-1 text-xs font-medium transition',
                                                    getRateMode(index) === 'brl_to'
                                                        ? 'bg-[var(--color-primary)] text-white'
                                                        : 'bg-zinc-200 text-zinc-600 hover:bg-zinc-300 dark:bg-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-500',
                                                ]"
                                                @click="setRateMode(index, 'brl_to')"
                                            >
                                                1 BRL = X {{ curr.code || 'moeda' }}
                                            </button>
                                            <button
                                                type="button"
                                                :class="[
                                                    'rounded-lg px-2.5 py-1 text-xs font-medium transition',
                                                    getRateMode(index) === 'foreign_to_brl'
                                                        ? 'bg-[var(--color-primary)] text-white'
                                                        : 'bg-zinc-200 text-zinc-600 hover:bg-zinc-300 dark:bg-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-500',
                                                ]"
                                                @click="setRateMode(index, 'foreign_to_brl')"
                                            >
                                                1 {{ curr.code || 'moeda' }} = X BRL
                                            </button>
                                        </div>
                                        <div class="flex items-end gap-2">
                                            <div class="min-w-0 flex-1">
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                    {{ getRateMode(index) === 'brl_to' ? `1 BRL = X ${curr.code || 'moeda'}` : `1 ${curr.code || 'moeda'} = X BRL` }}
                                                </label>
                                                <input
                                                    v-if="getRateMode(index) === 'brl_to'"
                                                    v-model.number="curr.rate_to_brl"
                                                    type="number"
                                                    step="0.0001"
                                                    min="0"
                                                    :class="inputClass"
                                                    :placeholder="curr.code === 'BRL' ? '1' : '0,18'"
                                                />
                                                <input
                                                    v-else
                                                    type="number"
                                                    step="0.01"
                                                    min="0"
                                                    :class="inputClass"
                                                    :placeholder="curr.code === 'BRL' ? '1' : '5,55'"
                                                    :value="inverseRate(curr.rate_to_brl)"
                                                    @input="(e) => setRateFromInverse(curr, e.target.value)"
                                                />
                                            </div>
                                            <button
                                                v-if="canFetchRate(curr)"
                                                type="button"
                                                :disabled="refreshLoadingByIndex[index]"
                                                class="shrink-0 rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)]"
                                                title="Buscar taxa atual da API Frankfurter"
                                                @click="fetchRate(curr, index)"
                                            >
                                                <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': refreshLoadingByIndex[index] }" />
                                            </button>
                                        </div>
                                        <p v-if="curr.code !== 'BRL' && curr.rate_to_brl > 0" class="text-xs text-zinc-500 dark:text-zinc-450">
                                            Ex.: 1 {{ curr.code }} ≈ {{ (1 / curr.rate_to_brl).toFixed(2) }} BRL
                                        </p>
                                        <p v-else class="text-xs text-zinc-500 dark:text-zinc-450">
                                            Ex: 0,18 = 1 BRL equivale a 0,18 USD (ou 1 USD ≈ 5,55 BRL)
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <button
                                type="button"
                                class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-dashed border-zinc-300 bg-white px-4 py-3 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-[var(--color-primary)]"
                                @click="addCurrency"
                            >
                                <Banknote class="h-4 w-4" />
                                + Adicionar moeda
                            </button>
                        </div>
                    </section>
                </div>
            </Transition>

            <div
                class="flex items-center gap-3 pt-4 sm:pt-2 md:pt-4 sticky bottom-4 z-10 -mx-2 rounded-xl border border-zinc-200 bg-white/95 px-4 py-3 shadow-lg backdrop-blur sm:static sm:mx-0 sm:rounded-none sm:border-0 sm:bg-transparent sm:px-0 sm:py-0 sm:shadow-none dark:border-zinc-700 dark:bg-zinc-800/95 sm:dark:bg-transparent sm:dark:border-0"
            >
                <Button type="submit" :disabled="form.processing">Salvar alterações</Button>
            </div>
        </form>

        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'cron'" class="w-full max-w-full space-y-6">
                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Cron (agendador)</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            Importante para o funcionamento geral da plataforma (envios em lote, tarefas automáticas, reconciliação de pagamentos, carrinho abandonado e outros).
                        </p>
                    </div>
                    <div class="space-y-6 p-6">
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                            <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                Aviso importante
                            </p>
                            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                Se você estiver usando o modo Cloud ou instalou via Docker, você não precisa configurar o cron manualmente. Só é necessário configurar em hospedagem compartilhada.
                            </p>
                        </div>
                        <div
                            v-if="cloud_mode || docker_mode"
                            class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800/50 dark:bg-emerald-950/30"
                        >
                            <p class="text-sm font-medium text-emerald-800 dark:text-emerald-200">
                                Modo Cloud / Docker
                            </p>
                            <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                                Se você estiver usando o modo Cloud ou instalou via Docker, o agendador normalmente já fica configurado automaticamente. Só configure manualmente se estiver em hospedagem compartilhada.
                            </p>
                        </div>
                        <div
                            v-else
                            class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-950/30"
                        >
                            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                                Hospedagem compartilhada
                            </p>
                            <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                                Se você instalou em hospedagem compartilhada, configure um cron job chamando o agendador a cada minuto para manter as rotinas automáticas funcionando.
                            </p>
                        </div>

                        <div class="grid gap-4 lg:grid-cols-2">
                            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/40">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Cron por URL</h3>
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    Use em serviços externos (cron-job.org, EasyCron etc.) quando você não tem acesso a SSH/Terminal.
                                </p>

                                <template v-if="cron_url">
                                    <div class="mt-4 flex flex-wrap items-center gap-2">
                                        <code class="break-all rounded-lg bg-zinc-100 px-3 py-2 font-mono text-sm text-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-200">
                                            {{ cron_url }}
                                        </code>
                                        <button
                                            type="button"
                                            class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                            @click="copyToClipboard(cron_url)"
                                        >
                                            Copiar
                                        </button>
                                    </div>
                                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        Configure a URL para ser chamada a cada minuto.
                                    </p>
                                </template>
                                <p v-else class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                                    Para gerar a URL, defina <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">CRON_SECRET</code> no arquivo .env.
                                </p>
                            </div>

                            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/40">
                                <h3 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Cron no Linux (crontab)</h3>
                                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                                    Se você tem acesso ao servidor, adicione uma linha no <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">crontab -e</code>.
                                </p>
                                <pre class="mt-3 overflow-x-auto rounded-lg bg-zinc-100 p-4 text-left font-mono text-sm text-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-200">{{ cronLinuxLine }}</pre>
                                <template v-if="cron_url">
                                    <p class="mt-4 text-xs font-medium text-zinc-500 dark:text-zinc-400">Alternativa (chamando a URL):</p>
                                    <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-100 p-4 text-left font-mono text-sm text-zinc-800 dark:bg-zinc-950/60 dark:text-zinc-200">{{ cronCurlLine }}</pre>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>
            </div>
        </Transition>

        <!-- Aba Update (fora do form) -->
        <Transition
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div v-show="activeTab === 'update'" class="w-full max-w-full space-y-6">
                <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                    <div class="border-b border-zinc-200 bg-zinc-50 px-6 py-5 dark:border-zinc-700 dark:bg-zinc-800">
                        <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Versão e atualizações</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                            Versão atual instalada e verificação de atualizações a partir do repositório oficial.
                        </p>
                    </div>
                    <div class="space-y-6 p-6">
                        <div class="flex flex-wrap items-center gap-4">
                            <div>
                                <span class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Versão atual</span>
                                <p class="mt-0.5 text-lg font-semibold text-zinc-900 dark:text-white">{{ current_version }}</p>
                            </div>
                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    type="button"
                                    :disabled="updateCheckLoading"
                                    class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)]"
                                    @click="checkForUpdate"
                                >
                                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': updateCheckLoading }" />
                                    {{ updateCheckLoading ? 'Verificando...' : 'Verificar atualização' }}
                                </button>
                                <button
                                    v-if="updateCheckResult?.available && updates_enabled"
                                    type="button"
                                    :disabled="updateRunLoading"
                                    class="inline-flex items-center gap-2 rounded-xl bg-[var(--color-primary)] px-4 py-2.5 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-60"
                                    @click="runUpdate"
                                >
                                    <Download class="h-4 w-4" :class="{ 'animate-pulse': updateRunLoading }" />
                                    {{ updateRunLoading ? 'Atualizando... Aguarde.' : 'Atualizar' }}
                                </button>
                            </div>
                        </div>
                        <div
                            v-if="!git_available"
                            class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-900/20"
                        >
                            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">Git não detectado</p>
                            <p class="mt-1 text-sm text-amber-700 dark:text-amber-300">
                                O painel tentará atualizar baixando um pacote do GitHub e aplicando os arquivos por cima, preservando <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/40">.env</code>, <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/40">storage/</code>, <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/40">database/</code> e <code class="rounded bg-amber-100 px-1 dark:bg-amber-900/40">plugins/</code>.
                            </p>
                        </div>
                        <div v-if="updateCheckResult" class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                            <p v-if="updateCheckResult.error" class="text-sm text-amber-600 dark:text-amber-400">{{ updateCheckResult.error }}</p>
                            <p v-else-if="updateCheckResult.available" class="text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                Nova versão disponível: {{ updateCheckResult.latest }}
                            </p>
                            <p v-else-if="updateCheckResult.latest" class="text-sm text-zinc-600 dark:text-zinc-400">
                                Você está na versão mais recente ({{ updateCheckResult.latest }}).
                            </p>
                            <p v-else class="text-sm text-zinc-600 dark:text-zinc-400">
                                Nenhuma release encontrada no repositório.
                            </p>
                            <div
                                v-if="updateCheckResult.changelog_remote"
                                class="mt-3 rounded-lg border border-zinc-200 bg-white p-3 text-sm text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                            >
                                <p class="mb-2 font-medium">O que há de novo na versão {{ updateCheckResult.latest }}</p>
                                <pre class="whitespace-pre-wrap font-sans">{{ updateCheckResult.changelog_remote }}</pre>
                            </div>
                        </div>
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">Integridade</p>
                                <button
                                    type="button"
                                    :disabled="integrityLoading"
                                    class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-600 transition hover:border-[var(--color-primary)] hover:text-[var(--color-primary)] disabled:opacity-60 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:border-[var(--color-primary)]"
                                    @click="checkIntegrity"
                                >
                                    <AlertCircle class="h-4 w-4" :class="{ 'animate-spin': integrityLoading }" />
                                    {{ integrityLoading ? 'Verificando...' : 'Verificar integridade' }}
                                </button>
                            </div>
                            <div v-if="integrityResult" class="mt-3 text-sm">
                                <p v-if="integrityResult.error" class="text-amber-600 dark:text-amber-400">{{ integrityResult.error }}</p>
                                <template v-else>
                                    <p v-if="integrityResult.pending_count > 0" class="text-amber-700 dark:text-amber-300">
                                        Existem {{ integrityResult.pending_count }} migrations pendentes para rodar.
                                    </p>
                                    <p v-else class="text-emerald-700 dark:text-emerald-400">
                                        Nenhuma migration pendente.
                                    </p>
                                    <div v-if="(integrityResult.pending ?? []).length" class="mt-2 rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900/30">
                                        <pre class="whitespace-pre-wrap font-mono text-xs text-zinc-700 dark:text-zinc-300">{{ (integrityResult.pending ?? []).join('\n') }}</pre>
                                        <p v-if="integrityResult.pending_truncated" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Lista truncada.</p>
                                    </div>
                                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">
                                        Observação: ao atualizar pelo painel, o sistema já tenta rodar as migrations automaticamente.
                                    </p>
                                </template>
                            </div>
                        </div>
                        <div v-if="!updates_enabled" class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800/50 dark:bg-amber-900/20">
                            <p class="text-sm text-amber-800 dark:text-amber-200">Atualizações pela interface estão desativadas (GETFY_UPDATES_ENABLED).</p>
                        </div>
                    </div>
                </section>
            </div>
        </Transition>

        <!-- Email Provider Sidebar (Teleport para ficar por cima do layout) -->
        <Teleport to="body">
            <EmailProviderSidebar
                :open="sidebarOpen"
                :provider="selectedProvider"
                :form="form"
                :connection-result="connectionResult"
                :send-result="sendResult"
                :connection-testing="connectionTesting"
                :send-test-sending="sendTestSending"
                @close="closeSidebar"
                @test-connection="testConnection"
                @send-test="(email) => { testForm.test_to = email; sendTestEmail(); }"
                @save="saveFromSidebar"
            />
        </Teleport>
    </div>
</template>
