<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Toggle from '@/components/ui/Toggle.vue';
import WebhookKpiStrip from '@/components/integrations/WebhookKpiStrip.vue';
import WebhookPayloadDocsModal from '@/components/integrations/WebhookPayloadDocsModal.vue';
import {
    X,
    Plus,
    Trash2,
    Send,
    ArrowLeft,
    Loader2,
    BookOpen,
    Settings,
    Clock,
} from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    webhooks: { type: Array, default: () => [] },
    webhookEvents: { type: Object, default: () => ({}) },
    webhookEventCatalog: {
        type: Object,
        default: () => ({ groups: [], events: [] }),
    },
    products: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const editingWebhook = ref(null);
const isCreating = ref(false);

const showingForm = computed(
    () => editingWebhook.value !== null || isCreating.value
);

const currentView = computed(() => {
    if (showingForm.value) {
        return 'form';
    }
    if (logsWebhook.value) {
        return 'logs';
    }
    return 'hub';
});

const headerTitle = computed(() => {
    if (currentView.value === 'form') {
        return editingWebhook.value ? 'Editar webhook' : 'Novo webhook';
    }
    if (currentView.value === 'logs' && logsWebhook.value) {
        return `Logs — ${logsWebhook.value.name}`;
    }
    return 'Webhooks';
});

const statsByWebhookId = computed(() => {
    const map = {};
    for (const row of dashboardData.value?.webhooks || []) {
        map[row.id] = row.stats;
    }
    return map;
});

const filteredLogs = computed(() => {
    const id = logsWebhook.value?.id;
    if (!id) {
        return [];
    }
    let list = logsByWebhookId.value[id] || [];
    if (logFilterStatus.value === 'success') {
        list = list.filter((l) => l.success);
    } else if (logFilterStatus.value === 'failed') {
        list = list.filter((l) => !l.success);
    }
    const q = logSearchQuery.value.trim().toLowerCase();
    if (q) {
        list = list.filter(
            (l) =>
                (l.event || '').toLowerCase().includes(q) ||
                (l.event_label || '').toLowerCase().includes(q),
        );
    }
    return list;
});

const form = ref({
    name: '',
    url: '',
    bearer_token: '',
    events: [],
    is_active: true,
    product_ids: [],
});
const saving = ref(false);
const deleting = ref(null);
const confirmingDeleteId = ref(null);
const testing = ref(null);
const testMessage = ref(null);
const testSuccess = ref(null);
const errorMessage = ref(null);

const showTestModal = ref(false);
const testTargetWebhook = ref(null);
const selectedTestEvent = ref('');

const logsByWebhookId = ref({});
const loadingLogs = ref(null);
const logsWebhook = ref(null);
const logFilterStatus = ref('all');
const logSearchQuery = ref('');

const dashboardData = ref(null);
const loadingDashboard = ref(false);
const showPayloadDocsModal = ref(false);
const testWebhookAfterDocs = ref(null);

const logDetailModal = ref(false);
const selectedLogDetail = ref(null);
const loadingLogDetail = ref(false);
const logCopyFeedback = ref('');
const logRequestPreRef = ref(null);
const logResponsePreRef = ref(null);
const resendingLog = ref(false);
const resendMessage = ref('');
const resendSuccess = ref(null);

const eventEntries = ref([]);

watch(
    () => props.webhookEvents,
    (events) => {
        eventEntries.value = Object.entries(events || {});
    },
    { immediate: true }
);

watch(
    () => [props.open, props.webhooks],
    () => {
        if (!props.open) {
            resetForm();
            logsWebhook.value = null;
            showPayloadDocsModal.value = false;
        } else {
            fetchDashboard();
        }
    }
);

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            fetchDashboard();
        }
    },
);

async function fetchDashboard() {
    loadingDashboard.value = true;
    try {
        const { data } = await axios.get('/integracoes/webhooks/dashboard-stats');
        dashboardData.value = data;
    } catch {
        dashboardData.value = null;
    } finally {
        loadingDashboard.value = false;
    }
}

function statsForWebhook(w) {
    return (
        statsByWebhookId.value[w.id] || {
            sent: 0,
            delivered: 0,
            failed: 0,
            success_rate: 0,
            last_sent_at: null,
        }
    );
}

function formatRelativeTime(iso) {
    if (!iso) {
        return 'Nenhum envio nas últimas 24h';
    }
    const d = new Date(iso);
    const diffMs = Date.now() - d.getTime();
    const mins = Math.floor(diffMs / 60000);
    if (mins < 1) {
        return 'Último envio: agora';
    }
    if (mins < 60) {
        return `Último envio: há ${mins} min`;
    }
    const hours = Math.floor(mins / 60);
    if (hours < 24) {
        return `Último envio: há ${hours}h`;
    }
    return `Último envio: ${formatLogDate(iso)}`;
}

function openLogsView(w) {
    logsWebhook.value = w;
    logFilterStatus.value = 'all';
    logSearchQuery.value = '';
    if (!logsByWebhookId.value[w.id]) {
        fetchLogs(w.id);
    }
}

function backToHub() {
    logsWebhook.value = null;
}

function openPayloadDocs() {
    showPayloadDocsModal.value = true;
}

function onPayloadDocsSendTest() {
    const active = props.webhooks.find((w) => w.is_active) || props.webhooks[0];
    if (active) {
        openTestModal(active);
    }
}

function resetForm() {
    editingWebhook.value = null;
    isCreating.value = false;
    logsWebhook.value = null;
    confirmingDeleteId.value = null;
    form.value = {
        name: '',
        url: '',
        bearer_token: '',
        events: [],
        is_active: true,
        product_ids: [],
    };
    errorMessage.value = null;
    testMessage.value = null;
}

function startNew() {
    logsWebhook.value = null;
    editingWebhook.value = null;
    isCreating.value = true;
    form.value = {
        name: '',
        url: '',
        bearer_token: '',
        events: [],
        is_active: true,
        product_ids: [],
    };
    errorMessage.value = null;
}

function editWebhook(w) {
    logsWebhook.value = null;
    isCreating.value = false;
    editingWebhook.value = w;
    form.value = {
        name: w.name,
        url: w.url,
        bearer_token: '',
        events: [...(w.events || [])],
        is_active: w.is_active ?? true,
        product_ids: (w.products || []).map(p => p.id),
    };
    errorMessage.value = null;
}

function cancelEdit() {
    resetForm();
}

function toggleEvent(eventClass) {
    const idx = form.value.events.indexOf(eventClass);
    if (idx >= 0) {
        form.value.events.splice(idx, 1);
    } else {
        form.value.events.push(eventClass);
    }
}

function isEventSelected(eventClass) {
    return form.value.events.includes(eventClass);
}

function toggleProduct(productId) {
    const idx = form.value.product_ids.indexOf(productId);
    if (idx >= 0) {
        form.value.product_ids.splice(idx, 1);
    } else {
        form.value.product_ids.push(productId);
    }
}

function isProductSelected(productId) {
    return form.value.product_ids.includes(productId);
}

async function save() {
    errorMessage.value = null;
    if (!form.value.name?.trim()) {
        errorMessage.value = 'Informe o nome do webhook.';
        return;
    }
    if (!form.value.url?.trim()) {
        errorMessage.value = 'Informe a URL do webhook.';
        return;
    }
    if (form.value.events.length === 0) {
        errorMessage.value = 'Selecione pelo menos um evento.';
        return;
    }

    saving.value = true;
    try {
        const payload = {
            name: form.value.name.trim(),
            url: form.value.url.trim(),
            events: form.value.events,
            is_active: form.value.is_active,
            product_ids: form.value.product_ids,
        };
        if (form.value.bearer_token?.trim()) {
            payload.bearer_token = form.value.bearer_token.trim();
        }

        if (editingWebhook.value) {
            await axios.put(
                `/integracoes/webhooks/${editingWebhook.value.id}`,
                payload
            );
        } else {
            await axios.post('/integracoes/webhooks', payload);
        }
        emit('saved');
        resetForm();
        await fetchDashboard();
    } catch (err) {
        errorMessage.value =
            err.response?.data?.message || 'Erro ao salvar webhook.';
    } finally {
        saving.value = false;
    }
}

function openTestModal(w) {
    testTargetWebhook.value = w;
    selectedTestEvent.value = eventEntries.value.length ? eventEntries.value[0][0] : '';
    testMessage.value = null;
    showTestModal.value = true;
}

function closeTestModal() {
    showTestModal.value = false;
    testTargetWebhook.value = null;
}

async function confirmTestSend() {
    if (!testTargetWebhook.value) return;
    const w = testTargetWebhook.value;
    testing.value = w.id;
    testMessage.value = null;
    closeTestModal();
    try {
        const { data } = await axios.post(`/integracoes/webhooks/${w.id}/test`, {
            event: selectedTestEvent.value || undefined,
        });
        testSuccess.value = data.success;
        testMessage.value = data.message || (data.success ? 'Evento enviado com sucesso!' : 'Falha ao enviar.');
        await fetchLogs(w.id);
        await fetchDashboard();
    } catch (err) {
        testSuccess.value = false;
        testMessage.value =
            err.response?.data?.message || 'Erro ao disparar evento de teste.';
    } finally {
        testing.value = null;
    }
}

async function fetchLogs(webhookId) {
    loadingLogs.value = webhookId;
    try {
        const { data } = await axios.get(`/integracoes/webhooks/${webhookId}/logs`);
        logsByWebhookId.value[webhookId] = data.logs || [];
    } catch {
        logsByWebhookId.value[webhookId] = [];
    } finally {
        loadingLogs.value = null;
    }
}

function formatLogDate(iso) {
    if (!iso) return '–';
    const d = new Date(iso);
    return d.toLocaleString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

async function openLogDetail(webhookId, logId) {
    loadingLogDetail.value = true;
    selectedLogDetail.value = null;
    resendMessage.value = '';
    resendSuccess.value = null;
    logDetailModal.value = true;
    try {
        const { data } = await axios.get(
            `/integracoes/webhooks/${webhookId}/logs/${logId}`
        );
        selectedLogDetail.value = data.log;
    } catch {
        selectedLogDetail.value = null;
    } finally {
        loadingLogDetail.value = false;
    }
}

function closeLogDetail() {
    logDetailModal.value = false;
    selectedLogDetail.value = null;
    logCopyFeedback.value = '';
    resendMessage.value = '';
    resendSuccess.value = null;
}

function formatLogSource(source) {
    if (source === 'test') return 'Teste manual';
    if (source === 'resend') return 'Reenvio manual';
    return 'Automático';
}

async function resendSelectedLog() {
    const webhookId = logsWebhook.value?.id;
    const logId = selectedLogDetail.value?.id;
    if (!webhookId || !logId || resendingLog.value) return;

    resendingLog.value = true;
    resendMessage.value = '';
    resendSuccess.value = null;

    try {
        const { data } = await axios.post(
            `/integracoes/webhooks/${webhookId}/logs/${logId}/resend`,
        );
        resendSuccess.value = true;
        resendMessage.value = data.message || 'Webhook reenviado com sucesso.';
    } catch (err) {
        resendSuccess.value = false;
        resendMessage.value =
            err.response?.data?.message || 'Não foi possível reenviar o webhook.';
    } finally {
        await Promise.all([fetchLogs(webhookId), fetchDashboard()]);
        resendingLog.value = false;
    }
}

function formatPayload(obj) {
    if (obj == null) {
        return '–';
    }
    try {
        if (typeof obj === 'string') {
            const trimmed = obj.trim();
            if (trimmed === '') {
                return '–';
            }
            if (
                (trimmed.startsWith('{') && trimmed.endsWith('}'))
                || (trimmed.startsWith('[') && trimmed.endsWith(']'))
            ) {
                return JSON.stringify(JSON.parse(trimmed), null, 2);
            }
            return obj;
        }
        return JSON.stringify(obj, null, 2);
    } catch {
        return String(obj);
    }
}

function fallbackCopy(text) {
    try {
        const el = document.createElement('textarea');
        el.value = text;
        el.setAttribute('readonly', '');
        el.style.position = 'fixed';
        el.style.top = '0';
        el.style.left = '-9999px';
        el.style.width = '1px';
        el.style.height = '1px';
        el.style.padding = '0';
        el.style.border = 'none';
        el.style.outline = 'none';
        el.style.opacity = '0';
        document.body.appendChild(el);
        el.focus({ preventScroll: true });
        el.select();
        el.setSelectionRange(0, text.length);
        const ok = document.execCommand('copy');
        document.body.removeChild(el);
        return ok;
    } catch {
        return false;
    }
}

function showLogCopyFeedback(feedbackKey) {
    logCopyFeedback.value = feedbackKey;
    setTimeout(() => {
        if (logCopyFeedback.value === feedbackKey) {
            logCopyFeedback.value = '';
        }
    }, 2000);
}

function copyLogText(text, feedbackKey) {
    const s = (text ?? '').trim();
    if (!s || s === '–') {
        return false;
    }

    if (fallbackCopy(s)) {
        showLogCopyFeedback(feedbackKey);
        return true;
    }

    if (navigator.clipboard?.writeText) {
        navigator.clipboard
            .writeText(s)
            .then(() => showLogCopyFeedback(feedbackKey))
            .catch(() => {
                if (fallbackCopy(s)) {
                    showLogCopyFeedback(feedbackKey);
                }
            });
        return true;
    }

    return false;
}

function textFromPre(preRef) {
    const el = preRef.value;
    if (!el) {
        return '';
    }
    return (el.innerText || el.textContent || '').trim();
}

function copyLogRequest() {
    let text = textFromPre(logRequestPreRef);
    if (!text || text === '–') {
        text = formatPayload(selectedLogDetail.value?.request_payload);
        if (text === '–') {
            text = '';
        }
    }
    copyLogText(text, 'payload');
}

function copyLogResponse() {
    const detail = selectedLogDetail.value;
    const parts = [];

    if (detail?.response_status != null && detail.response_status !== '') {
        parts.push(`HTTP ${detail.response_status}`);
    }

    let body = textFromPre(logResponsePreRef);
    if (!body || body === '–') {
        const raw = detail?.response_body;
        if (raw != null && String(raw).trim() !== '') {
            body = formatPayload(raw);
            if (body === '–') {
                body = String(raw).trim();
            }
        }
    }
    if (body && body !== '–') {
        parts.push(body);
    }

    if (parts.length === 0 && detail?.error_message) {
        parts.push(String(detail.error_message).trim());
    }

    copyLogText(parts.join('\n\n'), 'response');
}

function requestDelete(w) {
    confirmingDeleteId.value = w.id;
}

function cancelDelete() {
    confirmingDeleteId.value = null;
}

async function confirmRemoveWebhook(w) {
    deleting.value = w.id;
    confirmingDeleteId.value = null;
    try {
        await axios.delete(`/integracoes/webhooks/${w.id}`);
        emit('saved');
        if (logsWebhook.value?.id === w.id) {
            logsWebhook.value = null;
        }
        if (editingWebhook.value?.id === w.id) {
            resetForm();
        }
        await fetchDashboard();
    } catch (err) {
        errorMessage.value =
            err.response?.data?.message || 'Erro ao excluir webhook.';
    } finally {
        deleting.value = null;
    }
}

function close() {
    emit('close');
}

function truncateUrl(url, max = 40) {
    if (!url) return '';
    if (url.length <= max) return url;
    return url.slice(0, max) + '…';
}
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                aria-hidden="true"
                @click="close"
            />
            <aside
                class="relative flex h-full w-full max-w-4xl flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900"
            >
                <div
                    class="flex items-center justify-between gap-3 rounded-tl-2xl bg-zinc-50/80 px-5 py-4 dark:bg-zinc-800/50"
                >
                    <div class="flex min-w-0 items-center gap-2">
                        <button
                            v-if="currentView !== 'hub'"
                            type="button"
                            class="shrink-0 rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 dark:hover:bg-zinc-700"
                            title="Voltar"
                            @click="currentView === 'logs' ? backToHub() : cancelEdit()"
                        >
                            <ArrowLeft class="h-5 w-5" />
                        </button>
                        <div class="min-w-0">
                            <h2 class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                                {{ headerTitle }}
                            </h2>
                            <p
                                v-if="currentView === 'hub'"
                                class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <Clock class="h-3 w-3" />
                                Dados das últimas 24 horas
                            </p>
                            <p
                                v-else-if="currentView === 'logs' && logsWebhook"
                                class="truncate text-xs text-zinc-500 dark:text-zinc-400"
                                :title="logsWebhook.url"
                            >
                                {{ truncateUrl(logsWebhook.url, 56) }}
                            </p>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex flex-1 flex-col overflow-y-auto">
                    <!-- Hub: dashboard + lista -->
                    <template v-if="currentView === 'hub'">
                        <div class="space-y-4 p-4">
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                Envie eventos da plataforma para a URL configurada. O POST inclui
                                <code class="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">event</code>,
                                <code class="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">payload</code>
                                (pedido, produto, oferta, cliente em texto claro) e
                                <code class="rounded bg-zinc-100 px-1 text-xs dark:bg-zinc-800">timestamp</code>.
                            </p>

                            <WebhookKpiStrip
                                :summary="dashboardData?.summary || {}"
                                :sparkline="dashboardData?.sparkline || {}"
                                :loading="loadingDashboard"
                            />

                            <div class="flex flex-wrap gap-2">
                                <Button class="bg-emerald-600 hover:bg-emerald-700" @click="startNew">
                                    <Plus class="mr-2 h-4 w-4" />
                                    Novo webhook
                                </Button>
                                <Button variant="outline" @click="openPayloadDocs">
                                    <BookOpen class="mr-2 h-4 w-4" />
                                    Ver payloads
                                </Button>
                            </div>
                        </div>

                        <div class="flex-1 px-4 pb-6">
                            <h3 class="mb-3 text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                Meus webhooks ({{ webhooks.length }})
                            </h3>
                            <ul v-if="webhooks.length > 0" class="space-y-3">
                                <li
                                    v-for="w in webhooks"
                                    :key="w.id"
                                    class="rounded-2xl border border-zinc-200/80 bg-zinc-50/80 shadow-sm transition-shadow hover:shadow dark:border-zinc-700/60 dark:bg-zinc-800/60"
                                >
                                    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-zinc-900 dark:text-white">
                                                    {{ w.name }}
                                                </span>
                                                <span
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                                    :class="
                                                        w.is_active
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
                                                    "
                                                >
                                                    {{ w.is_active ? 'Ativo' : 'Inativo' }}
                                                </span>
                                            </div>
                                            <div
                                                class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400"
                                                :title="w.url"
                                            >
                                                {{ truncateUrl(w.url, 52) }}
                                            </div>
                                            <div class="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                <span>{{ formatRelativeTime(statsForWebhook(w).last_sent_at) }}</span>
                                                <span>
                                                    Taxa de sucesso:
                                                    <strong
                                                        :class="
                                                            statsForWebhook(w).success_rate >= 80
                                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                                : statsForWebhook(w).sent > 0
                                                                  ? 'text-red-600 dark:text-red-400'
                                                                  : ''
                                                        "
                                                    >{{ statsForWebhook(w).success_rate }}%</strong>
                                                    ({{ statsForWebhook(w).sent }} envios)
                                                </span>
                                                <span>{{ (w.events || []).length }} evento(s)</span>
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                                            <template v-if="confirmingDeleteId === w.id">
                                                <span class="mr-1 text-xs font-medium text-zinc-600 dark:text-zinc-400">Excluir?</span>
                                                <button
                                                    type="button"
                                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-200/80 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                                    @click.stop="cancelDelete()"
                                                >
                                                    Cancelar
                                                </button>
                                                <button
                                                    type="button"
                                                    class="flex items-center gap-1 rounded-lg bg-red-100 px-2.5 py-1.5 text-xs font-medium text-red-700 hover:bg-red-200 dark:bg-red-900/40 dark:text-red-300 dark:hover:bg-red-900/60"
                                                    :disabled="deleting === w.id"
                                                    @click.stop="confirmRemoveWebhook(w)"
                                                >
                                                    <Loader2 v-if="deleting === w.id" class="h-3 w-3 animate-spin" />
                                                    <Trash2 v-else class="h-3 w-3" />
                                                    {{ deleting === w.id ? 'Excluindo...' : 'Excluir' }}
                                                </button>
                                            </template>
                                            <template v-else>
                                                <button
                                                    type="button"
                                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-200/80 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                                    @click.stop="openLogsView(w)"
                                                >
                                                    Ver logs
                                                </button>
                                                <button
                                                    type="button"
                                                    class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/30"
                                                    title="Disparar evento de teste"
                                                    :disabled="testing === w.id"
                                                    @click.stop="openTestModal(w)"
                                                >
                                                    <Loader2
                                                        v-if="testing === w.id"
                                                        class="h-3.5 w-3.5 animate-spin"
                                                    />
                                                    <Send v-else class="h-3.5 w-3.5" />
                                                    Testar
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 dark:hover:bg-zinc-700"
                                                    title="Configurar"
                                                    @click.stop="editWebhook(w)"
                                                >
                                                    <Settings class="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-lg p-2 text-zinc-500 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                    title="Excluir"
                                                    :disabled="deleting === w.id"
                                                    @click.stop="requestDelete(w)"
                                                >
                                                    <Trash2 class="h-4 w-4" />
                                                </button>
                                            </template>
                                        </div>
                                    </div>
                                </li>
                            </ul>
                            <p
                                v-else
                                class="rounded-2xl bg-zinc-50 py-8 text-center text-sm text-zinc-500 dark:bg-zinc-800/40 dark:text-zinc-400"
                            >
                                Nenhum webhook configurado. Clique em "Novo webhook"
                                para criar.
                            </p>
                            <p
                                v-if="testMessage"
                                :class="[
                                    'mt-3 rounded-lg px-3 py-2 text-sm',
                                    testSuccess
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                                        : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                                ]"
                            >
                                {{ testMessage }}
                            </p>
                        </div>
                    </template>

                    <!-- Logs de um webhook -->
                    <template v-else-if="currentView === 'logs' && logsWebhook">
                        <div class="space-y-4 p-4">
                            <div
                                v-if="statsForWebhook(logsWebhook).sent > 0"
                                class="grid grid-cols-3 gap-2 text-center text-xs"
                            >
                                <div class="rounded-xl bg-zinc-100 px-2 py-2 dark:bg-zinc-800">
                                    <p class="font-bold text-zinc-900 dark:text-white">{{ statsForWebhook(logsWebhook).sent }}</p>
                                    <p class="text-zinc-500">Enviados</p>
                                </div>
                                <div class="rounded-xl bg-emerald-50 px-2 py-2 dark:bg-emerald-900/20">
                                    <p class="font-bold text-emerald-700 dark:text-emerald-300">{{ statsForWebhook(logsWebhook).delivered }}</p>
                                    <p class="text-zinc-500">OK</p>
                                </div>
                                <div class="rounded-xl bg-red-50 px-2 py-2 dark:bg-red-900/20">
                                    <p class="font-bold text-red-700 dark:text-red-300">{{ statsForWebhook(logsWebhook).failed }}</p>
                                    <p class="text-zinc-500">Falhas</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <select
                                    v-model="logFilterStatus"
                                    class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                >
                                    <option value="all">Todos</option>
                                    <option value="success">Sucesso</option>
                                    <option value="failed">Falha</option>
                                </select>
                                <input
                                    v-model="logSearchQuery"
                                    type="search"
                                    placeholder="Buscar evento..."
                                    class="min-w-[140px] flex-1 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-xs dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                                />
                            </div>
                        </div>
                        <div class="flex-1 px-4 pb-6">
                            <div
                                v-if="loadingLogs === logsWebhook.id"
                                class="flex items-center justify-center gap-2 py-12 text-sm text-zinc-500"
                            >
                                <Loader2 class="h-5 w-5 animate-spin" />
                                Carregando logs...
                            </div>
                            <div
                                v-else-if="filteredLogs.length === 0"
                                class="rounded-2xl border border-dashed border-zinc-300 py-12 text-center text-sm text-zinc-500 dark:border-zinc-600"
                            >
                                Nenhum registro encontrado.
                            </div>
                            <div v-else class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        <tr>
                                            <th class="px-3 py-2 font-medium">Horário</th>
                                            <th class="px-3 py-2 font-medium">Evento</th>
                                            <th class="px-3 py-2 font-medium">Status</th>
                                            <th class="px-3 py-2 font-medium">Origem</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="log in filteredLogs"
                                            :key="log.id"
                                            class="cursor-pointer border-t border-zinc-100 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/80"
                                            @click="openLogDetail(logsWebhook.id, log.id)"
                                        >
                                            <td class="whitespace-nowrap px-3 py-2.5 text-zinc-600 dark:text-zinc-400">
                                                {{ formatLogDate(log.created_at) }}
                                            </td>
                                            <td class="px-3 py-2.5 font-medium text-zinc-800 dark:text-zinc-200">
                                                {{ log.event_label || log.event }}
                                            </td>
                                            <td class="px-3 py-2.5">
                                                <span
                                                    class="rounded px-1.5 py-0.5 font-medium"
                                                    :class="
                                                        log.success
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                            : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'
                                                    "
                                                >
                                                    {{ log.success ? (log.response_status || 'OK') : (log.response_status || 'Erro') }}
                                                </span>
                                            </td>
                                            <td class="px-3 py-2.5 text-zinc-500">
                                                {{ formatLogSource(log.source) }}
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <!-- Formulário (criar/editar) -->
                    <div
                        v-else-if="currentView === 'form'"
                        class="flex flex-1 flex-col bg-zinc-50/50 p-4 dark:bg-zinc-800/30"
                    >
                        <div class="space-y-4">
                            <div>
                                <label
                                    class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Nome
                                </label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    placeholder="Ex: Minha integração"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                            </div>
                            <div>
                                <label
                                    class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    URL
                                </label>
                                <input
                                    v-model="form.url"
                                    type="url"
                                    placeholder="https://seu-endpoint.com/webhook"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                            </div>
                            <div>
                                <label
                                    class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Bearer token
                                    <span class="font-normal text-zinc-500"
                                        >(opcional)</span
                                    >
                                </label>
                                <p
                                    class="mb-1.5 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Por segurança, o valor do token salvo não é
                                    exibido neste campo.
                                </p>
                                <input
                                    v-model="form.bearer_token"
                                    type="password"
                                    :placeholder="
                                        editingWebhook ? 'Deixe em branco para manter' : 'Token de autenticação'
                                    "
                                    autocomplete="new-password"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                                <p
                                    v-if="editingWebhook?.has_bearer_token && !form.bearer_token"
                                    class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    Token já está salvo. Deixe em branco para manter.
                                </p>
                            </div>
                            <div>
                                <label
                                    class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Eventos
                                </label>
                                <div
                                    class="max-h-40 space-y-2 overflow-y-auto rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200/80 dark:ring-zinc-600 dark:bg-zinc-800/50"
                                >
                                    <Checkbox
                                        v-for="[eventClass, label] in eventEntries"
                                        :key="eventClass"
                                        :model-value="isEventSelected(eventClass)"
                                        :label="label"
                                        class="block"
                                        @update:model-value="
                                            toggleEvent(eventClass)
                                        "
                                    />
                                </div>
                            </div>
                            <div>
                                <label
                                    class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    Produtos
                                    <span class="font-normal text-zinc-500">
                                        (opcional - deixe vazio para todos)
                                    </span>
                                </label>
                                <div
                                    class="max-h-40 space-y-2 overflow-y-auto rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200/80 dark:ring-zinc-600 dark:bg-zinc-800/50"
                                >
                                    <template v-if="products.length > 0">
                                        <Checkbox
                                            v-for="product in products"
                                            :key="product.id"
                                            :model-value="isProductSelected(product.id)"
                                            :label="product.name"
                                            class="block"
                                            @update:model-value="toggleProduct(product.id)"
                                        />
                                    </template>
                                    <p
                                        v-else
                                        class="py-2 text-center text-xs text-zinc-500 dark:text-zinc-400"
                                    >
                                        Nenhum produto cadastrado
                                    </p>
                                </div>
                            </div>
                            <div>
                                <Toggle
                                    v-model="form.is_active"
                                    label="Ativo"
                                />
                            </div>
                        </div>

                        <p
                            v-if="errorMessage"
                            class="mt-4 rounded-lg bg-red-100 px-3 py-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-300"
                        >
                            {{ errorMessage }}
                        </p>

                        <div class="mt-4 flex gap-2">
                            <Button
                                variant="outline"
                                :disabled="saving"
                                @click="cancelEdit"
                            >
                                Cancelar
                            </Button>
                            <Button :disabled="saving" @click="save">
                                {{ saving ? 'Salvando...' : 'Salvar' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Modal: escolher evento para teste -->
                <div
                    v-if="showTestModal && testTargetWebhook"
                    class="absolute inset-0 z-10 flex items-center justify-center bg-zinc-900/40 p-4 dark:bg-zinc-950/60"
                    @click.self="closeTestModal"
                >
                    <div
                        class="w-full max-w-sm rounded-2xl bg-white p-5 shadow-2xl dark:bg-zinc-800"
                        role="dialog"
                        aria-labelledby="test-modal-title"
                    >
                        <h3 id="test-modal-title" class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">
                            Enviar evento de teste
                        </h3>
                        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ testTargetWebhook.name }}
                        </p>
                        <div class="mb-4">
                            <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-300">
                                Evento
                            </label>
                            <select
                                v-model="selectedTestEvent"
                                class="block w-full rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-900 ring-1 ring-zinc-200/80 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 dark:bg-zinc-700 dark:ring-zinc-600 dark:text-white"
                            >
                                <option
                                    v-for="[eventClass, label] in eventEntries"
                                    :key="eventClass"
                                    :value="eventClass"
                                >
                                    {{ label }}
                                </option>
                            </select>
                        </div>
                        <div class="flex justify-end gap-2">
                            <Button variant="outline" size="sm" @click="closeTestModal">
                                Cancelar
                            </Button>
                            <Button size="sm" @click="confirmTestSend">
                                Enviar
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Modal: detalhe do log (payload, resposta, etc.) -->
                <div
                    v-if="logDetailModal"
                    class="absolute inset-0 z-10 flex items-center justify-center bg-zinc-900/40 p-4 dark:bg-zinc-950/60"
                    @click.self="closeLogDetail"
                >
                    <div
                        class="flex max-h-[90vh] w-full max-w-2xl flex-col rounded-2xl bg-white shadow-2xl dark:bg-zinc-800"
                        role="dialog"
                        aria-labelledby="log-detail-title"
                    >
                        <div class="flex items-center justify-between bg-zinc-50/80 px-5 py-3 dark:bg-zinc-800/80">
                            <h3 id="log-detail-title" class="text-sm font-semibold text-zinc-900 dark:text-white">
                                Detalhe do envio
                            </h3>
                            <button
                                type="button"
                                class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                aria-label="Fechar"
                                @click="closeLogDetail"
                            >
                                <X class="h-5 w-5" />
                            </button>
                        </div>
                        <div class="flex-1 overflow-y-auto p-4">
                            <div v-if="loadingLogDetail" class="flex items-center justify-center py-12">
                                <Loader2 class="h-8 w-8 animate-spin text-zinc-400" />
                            </div>
                            <template v-else-if="selectedLogDetail">
                                <div class="mb-4 flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ selectedLogDetail.event_label || selectedLogDetail.event }}
                                    </span>
                                    <span
                                        :class="[
                                            'rounded px-2 py-0.5 text-xs font-medium',
                                            selectedLogDetail.success
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                        ]"
                                    >
                                        {{ selectedLogDetail.success ? 'Sucesso' : 'Falha' }}
                                        <span v-if="selectedLogDetail.response_status != null">
                                            (HTTP {{ selectedLogDetail.response_status }})
                                        </span>
                                    </span>
                                    <span class="rounded bg-zinc-200 px-2 py-0.5 text-xs dark:bg-zinc-600">
                                        {{ formatLogSource(selectedLogDetail.source) }}
                                    </span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ formatLogDate(selectedLogDetail.created_at) }}
                                    </span>
                                </div>
                                <p
                                    v-if="selectedLogDetail.error_message"
                                    class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300"
                                >
                                    {{ selectedLogDetail.error_message }}
                                </p>
                                <p
                                    v-if="resendMessage"
                                    class="mb-4 rounded-lg px-3 py-2 text-sm"
                                    :class="
                                        resendSuccess
                                            ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-300'
                                            : 'bg-red-50 text-red-700 dark:bg-red-900/20 dark:text-red-300'
                                    "
                                >
                                    {{ resendMessage }}
                                </p>
                                <div class="space-y-4">
                                    <div>
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                Payload enviado (request)
                                            </span>
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1 text-xs font-medium transition"
                                                :class="
                                                    logCopyFeedback === 'payload'
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-600 dark:hover:text-zinc-300'
                                                "
                                                @click.stop="copyLogRequest"
                                            >
                                                {{ logCopyFeedback === 'payload' ? 'Copiado!' : 'Copiar' }}
                                            </button>
                                        </div>
                                        <pre
                                            ref="logRequestPreRef"
                                            class="max-h-64 overflow-auto rounded-xl bg-zinc-50 p-4 text-xs leading-relaxed text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200"
                                        >{{ formatPayload(selectedLogDetail.request_payload) }}</pre>
                                    </div>
                                    <div>
                                        <div class="mb-1 flex items-center justify-between">
                                            <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                Resposta do servidor (response)
                                            </span>
                                            <button
                                                type="button"
                                                class="rounded px-2 py-1 text-xs font-medium transition"
                                                :class="
                                                    logCopyFeedback === 'response'
                                                        ? 'text-emerald-600 dark:text-emerald-400'
                                                        : 'text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-600 dark:hover:text-zinc-300'
                                                "
                                                @click.stop="copyLogResponse"
                                            >
                                                {{ logCopyFeedback === 'response' ? 'Copiado!' : 'Copiar' }}
                                            </button>
                                        </div>
                                        <p v-if="selectedLogDetail.response_status != null" class="mb-1 text-xs text-zinc-600 dark:text-zinc-400">
                                            Status: {{ selectedLogDetail.response_status }}
                                        </p>
                                        <pre
                                            ref="logResponsePreRef"
                                            class="max-h-64 overflow-auto rounded-xl bg-zinc-50 p-4 text-xs leading-relaxed text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200"
                                        >{{ formatPayload(selectedLogDetail.response_body) }}</pre>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="flex flex-col-reverse gap-2 bg-zinc-50/80 px-5 py-3 sm:flex-row sm:justify-end dark:bg-zinc-800/80">
                            <Button variant="outline" size="sm" class="w-full sm:w-auto" :disabled="resendingLog" @click="closeLogDetail">
                                Fechar
                            </Button>
                            <Button size="sm" class="w-full sm:w-auto" :disabled="resendingLog || !selectedLogDetail" @click="resendSelectedLog">
                                <Loader2 v-if="resendingLog" class="mr-2 h-4 w-4 animate-spin" />
                                <Send v-else class="mr-2 h-4 w-4" />
                                {{ resendingLog ? 'Reenviando…' : 'Reenviar webhook' }}
                            </Button>
                        </div>
                    </div>
                </div>
            </aside>

            <WebhookPayloadDocsModal
                :open="showPayloadDocsModal"
                :catalog="webhookEventCatalog"
                @close="showPayloadDocsModal = false"
                @send-test="onPayloadDocsSendTest"
            />
        </div>
    </Teleport>
</template>
