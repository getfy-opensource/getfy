<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import Toggle from '@/components/ui/Toggle.vue';
import {
    X,
    Plus,
    Trash2,
    Send,
    ArrowLeft,
    Loader2,
    Settings,
} from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    pixel_x_integrations: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const PIXEL_X_EVENTS = [
    { slug: 'pedido_pendente', label: 'Pedido pendente' },
    { slug: 'pedido_pago', label: 'Pedido pago' },
    { slug: 'pagamento_recusado', label: 'Pagamento recusado' },
    { slug: 'reembolso', label: 'Reembolso' },
    { slug: 'pix_gerado', label: 'Pix gerado' },
    { slug: 'boleto_gerado', label: 'Boleto gerado' },
    { slug: 'carrinho_abandonado', label: 'Carrinho abandonado' },
    { slug: 'assinatura_criada', label: 'Assinatura criada' },
    { slug: 'assinatura_renovada', label: 'Assinatura renovada' },
    { slug: 'assinatura_cancelada', label: 'Assinatura cancelada' },
];

const editingIntegration = ref(null);
const isCreating = ref(false);
const logsIntegration = ref(null);
const selectedLog = ref(null);

const form = ref({
    name: '',
    url: '',
    token: '',
    is_active: true,
    events: [],
    product_ids: [],
});

const saving = ref(false);
const deleting = ref(null);
const confirmingDeleteId = ref(null);
const errorMessage = ref(null);
const logs = ref([]);
const loadingLogs = ref(false);
const testingIntegrationId = ref(null);
const testResult = ref(null);
const loadingLogDetail = ref(false);
const logDetailModal = ref(false);

const showingForm = computed(
    () => editingIntegration.value !== null || isCreating.value
);

const currentView = computed(() => {
    if (showingForm.value) {
        return 'form';
    }
    if (logsIntegration.value) {
        return 'logs';
    }
    return 'hub';
});

const headerTitle = computed(() => {
    if (currentView.value === 'form') {
        return editingIntegration.value ? 'Editar integração' : 'Nova integração';
    }
    if (currentView.value === 'logs' && logsIntegration.value) {
        return `Logs — ${logsIntegration.value.name}`;
    }
    return 'Pixel X';
});

const activeStatus = computed(() =>
    (props.pixel_x_integrations || []).some((i) => i.configured && i.is_active)
);

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            resetForm();
            logsIntegration.value = null;
            testResult.value = null;
        }
    }
);

function resetForm() {
    editingIntegration.value = null;
    isCreating.value = false;
    confirmingDeleteId.value = null;
    form.value = {
        name: '',
        url: '',
        token: '',
        is_active: true,
        events: [],
        product_ids: [],
    };
    errorMessage.value = null;
}

function startNew() {
    logsIntegration.value = null;
    editingIntegration.value = null;
    isCreating.value = true;
    form.value = {
        name: '',
        url: '',
        token: '',
        is_active: true,
        events: [],
        product_ids: [],
    };
    errorMessage.value = null;
    testResult.value = null;
}

function editIntegration(integration) {
    logsIntegration.value = null;
    isCreating.value = false;
    editingIntegration.value = integration;
    form.value = {
        name: integration.name,
        url: integration.url,
        token: '', // Nunca pré-popular o token por segurança
        is_active: integration.is_active ?? true,
        events: [...(integration.events || [])],
        product_ids: (integration.product_ids || []),
    };
    errorMessage.value = null;
    testResult.value = null;
}

function cancelEdit() {
    resetForm();
}

function toggleEvent(slug) {
    const idx = form.value.events.indexOf(slug);
    if (idx >= 0) {
        form.value.events.splice(idx, 1);
    } else {
        form.value.events.push(slug);
    }
}

function isEventSelected(slug) {
    return form.value.events.includes(slug);
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

async function saveIntegration() {
    errorMessage.value = null;
    if (!form.value.name?.trim()) {
        errorMessage.value = 'Informe o nome da integração.';
        return;
    }
    if (!form.value.url?.trim()) {
        errorMessage.value = 'Informe a URL do webhook da Pixel X.';
        return;
    }
    if (!editingIntegration.value && !form.value.token?.trim()) {
        errorMessage.value = 'Informe o token da Pixel X.';
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
        // Enviar token apenas se preenchido (ao editar, em branco = manter o atual)
        if (form.value.token?.trim()) {
            payload.token = form.value.token.trim();
        }

        if (editingIntegration.value) {
            await axios.put(
                `/integracoes/pixel-x/${editingIntegration.value.id}`,
                payload
            );
        } else {
            await axios.post('/integracoes/pixel-x', payload);
        }
        emit('saved');
        resetForm();
    } catch (err) {
        const data = err.response?.data;
        const firstValidationError = data?.errors
            ? Object.values(data.errors).flat().find(Boolean)
            : null;
        errorMessage.value =
            firstValidationError ||
            data?.message ||
            'Erro ao salvar integração.';
    } finally {
        saving.value = false;
    }
}

async function deleteIntegration(id) {
    deleting.value = id;
    confirmingDeleteId.value = null;
    try {
        await axios.delete(`/integracoes/pixel-x/${id}`);
        emit('saved');
        if (logsIntegration.value?.id === id) {
            logsIntegration.value = null;
        }
        if (editingIntegration.value?.id === id) {
            resetForm();
        }
    } catch (err) {
        errorMessage.value =
            err.response?.data?.message || 'Erro ao excluir integração.';
    } finally {
        deleting.value = null;
    }
}

function requestDelete(integration) {
    confirmingDeleteId.value = integration.id;
}

function cancelDelete() {
    confirmingDeleteId.value = null;
}

function openLogs(integration) {
    logsIntegration.value = integration;
    logs.value = [];
    loadLogs();
}

function backToHub() {
    logsIntegration.value = null;
    logs.value = [];
}

async function loadLogs() {
    if (!logsIntegration.value) return;
    loadingLogs.value = true;
    try {
        const { data } = await axios.get(
            `/integracoes/pixel-x/${logsIntegration.value.id}/logs`
        );
        logs.value = data.logs || [];
    } catch {
        logs.value = [];
    } finally {
        loadingLogs.value = false;
    }
}

async function openLogDetail(log) {
    if (log.request_payload !== undefined && log.response_body !== undefined) {
        selectedLog.value = log;
        logDetailModal.value = true;
        return;
    }
    loadingLogDetail.value = true;
    selectedLog.value = null;
    logDetailModal.value = true;
    try {
        const { data } = await axios.get(
            `/integracoes/pixel-x/${logsIntegration.value.id}/logs/${log.id}`
        );
        selectedLog.value = data.log;
    } catch {
        selectedLog.value = null;
    } finally {
        loadingLogDetail.value = false;
    }
}

function closeLogDetail() {
    logDetailModal.value = false;
    selectedLog.value = null;
}

async function testIntegration(id) {
    testingIntegrationId.value = id;
    testResult.value = null;
    try {
        const { data } = await axios.post(`/integracoes/pixel-x/${id}/test`);
        testResult.value = { success: data.success, message: data.message };
    } catch (err) {
        testResult.value = {
            success: false,
            message: err.response?.data?.message || 'Erro ao disparar evento de teste.',
        };
    } finally {
        testingIntegrationId.value = null;
    }
}

function closeSidebar() {
    emit('close');
    resetForm();
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

function formatPayload(obj) {
    if (obj == null) return '–';
    try {
        if (typeof obj === 'string') {
            const trimmed = obj.trim();
            if (trimmed === '') return '–';
            if (
                (trimmed.startsWith('{') && trimmed.endsWith('}')) ||
                (trimmed.startsWith('[') && trimmed.endsWith(']'))
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
                @click="closeSidebar"
            />
            <aside
                class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900"
            >
                <!-- Header -->
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
                        <h2 class="truncate text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ headerTitle }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="shrink-0 rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="closeSidebar"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex flex-1 flex-col overflow-y-auto">
                    <!-- View: hub -->
                    <template v-if="currentView === 'hub'">
                        <div class="space-y-4 p-4">
                            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                Envie os eventos mapeados para a Pixel X com token e payload proprietário.
                            </p>

                            <Button class="bg-emerald-600 hover:bg-emerald-700" @click="startNew">
                                <Plus class="mr-2 h-4 w-4" />
                                Nova integração
                            </Button>
                        </div>

                        <div class="flex-1 px-4 pb-6">
                            <h3 class="mb-3 text-xs font-medium uppercase tracking-wider text-zinc-400 dark:text-zinc-500">
                                Minhas integrações ({{ pixel_x_integrations.length }})
                            </h3>

                            <ul v-if="pixel_x_integrations.length > 0" class="space-y-3">
                                <li
                                    v-for="integration in pixel_x_integrations"
                                    :key="integration.id"
                                    class="rounded-2xl border border-zinc-200/80 bg-zinc-50/80 shadow-sm transition-shadow hover:shadow dark:border-zinc-700/60 dark:bg-zinc-800/60"
                                >
                                    <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
                                        <div class="min-w-0 flex-1">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="font-medium text-zinc-900 dark:text-white">
                                                    {{ integration.name }}
                                                </span>
                                                <span
                                                    class="rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase"
                                                    :class="
                                                        integration.is_active
                                                            ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                            : 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300'
                                                    "
                                                >
                                                    {{ integration.is_active ? 'Ativo' : 'Inativo' }}
                                                </span>
                                            </div>
                                            <div
                                                class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400"
                                                :title="integration.url"
                                            >
                                                {{ truncateUrl(integration.url, 52) }}
                                            </div>
                                            <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                {{ (integration.events || []).length }} evento(s)
                                            </div>
                                        </div>

                                        <div class="flex shrink-0 flex-wrap items-center gap-1">
                                            <template v-if="confirmingDeleteId === integration.id">
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
                                                    :disabled="deleting === integration.id"
                                                    @click.stop="deleteIntegration(integration.id)"
                                                >
                                                    <Loader2 v-if="deleting === integration.id" class="h-3 w-3 animate-spin" />
                                                    <Trash2 v-else class="h-3 w-3" />
                                                    {{ deleting === integration.id ? 'Excluindo...' : 'Excluir' }}
                                                </button>
                                            </template>
                                            <template v-else>
                                                <button
                                                    type="button"
                                                    class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-200/80 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                                    @click.stop="openLogs(integration)"
                                                >
                                                    Ver logs
                                                </button>
                                                <button
                                                    type="button"
                                                    class="flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-900/30"
                                                    title="Disparar evento de teste"
                                                    :disabled="testingIntegrationId === integration.id"
                                                    @click.stop="testIntegration(integration.id)"
                                                >
                                                    <Loader2
                                                        v-if="testingIntegrationId === integration.id"
                                                        class="h-3.5 w-3.5 animate-spin"
                                                    />
                                                    <Send v-else class="h-3.5 w-3.5" />
                                                    Testar
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-200/80 dark:hover:bg-zinc-700"
                                                    title="Configurar"
                                                    @click.stop="editIntegration(integration)"
                                                >
                                                    <Settings class="h-4 w-4" />
                                                </button>
                                                <button
                                                    type="button"
                                                    class="rounded-lg p-2 text-zinc-500 hover:bg-red-100 hover:text-red-600 dark:hover:bg-red-900/30 dark:hover:text-red-400"
                                                    title="Excluir"
                                                    :disabled="deleting === integration.id"
                                                    @click.stop="requestDelete(integration)"
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
                                Nenhuma integração configurada. Clique em "Nova integração" para criar.
                            </p>

                            <p
                                v-if="testResult"
                                :class="[
                                    'mt-3 rounded-lg px-3 py-2 text-sm',
                                    testResult.success
                                        ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                                        : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                                ]"
                            >
                                {{ testResult.message }}
                            </p>
                        </div>
                    </template>

                    <!-- View: logs -->
                    <template v-else-if="currentView === 'logs' && logsIntegration">
                        <div class="flex-1 px-4 pb-6 pt-4">
                            <div
                                v-if="loadingLogs"
                                class="flex items-center justify-center gap-2 py-12 text-sm text-zinc-500"
                            >
                                <Loader2 class="h-5 w-5 animate-spin" />
                                Carregando logs...
                            </div>
                            <div
                                v-else-if="logs.length === 0"
                                class="rounded-2xl border border-dashed border-zinc-300 py-12 text-center text-sm text-zinc-500 dark:border-zinc-600"
                            >
                                Nenhum log encontrado.
                            </div>
                            <div v-else class="overflow-hidden rounded-2xl border border-zinc-200 dark:border-zinc-700">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        <tr>
                                            <th class="px-3 py-2 font-medium">Horário</th>
                                            <th class="px-3 py-2 font-medium">Evento</th>
                                            <th class="px-3 py-2 font-medium">Status</th>
                                            <th class="px-3 py-2 font-medium">Origem</th>
                                            <th class="px-3 py-2 font-medium"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr
                                            v-for="log in logs"
                                            :key="log.id"
                                            class="cursor-pointer border-t border-zinc-100 hover:bg-zinc-50 dark:border-zinc-800 dark:hover:bg-zinc-800/80"
                                            @click="openLogDetail(log)"
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
                                                {{ log.source === 'test' ? 'Teste' : 'Automático' }}
                                            </td>
                                            <td class="px-3 py-2.5 text-right">
                                                <button
                                                    type="button"
                                                    class="rounded px-2 py-1 text-xs text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300"
                                                    @click.stop="openLogDetail(log)"
                                                >
                                                    Detalhes
                                                </button>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <!-- View: form (criar/editar) -->
                    <div
                        v-else-if="currentView === 'form'"
                        class="flex flex-1 flex-col bg-zinc-50/50 p-4 dark:bg-zinc-800/30"
                    >
                        <div class="space-y-4">
                            <!-- Nome -->
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Nome
                                </label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    placeholder="Ex: Pixel X Principal"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                            </div>

                            <!-- URL do Webhook -->
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    URL do Webhook
                                </label>
                                <input
                                    v-model="form.url"
                                    type="url"
                                    placeholder="https://app.pixelx.com.br/api/..."
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                            </div>

                            <!-- Token -->
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Token
                                    <span v-if="editingIntegration" class="font-normal text-zinc-500">(opcional)</span>
                                </label>
                                <p v-if="editingIntegration" class="mb-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    Por segurança, o token salvo não é exibido. Deixe em branco para manter o atual.
                                </p>
                                <input
                                    v-model="form.token"
                                    type="password"
                                    :placeholder="editingIntegration ? 'Deixe vazio para manter o token atual' : 'Token da Pixel X'"
                                    autocomplete="new-password"
                                    class="block w-full rounded-xl bg-white px-4 py-2.5 text-zinc-900 shadow-sm ring-1 ring-zinc-200 placeholder-zinc-400 transition focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/30 focus:ring-offset-0 dark:bg-zinc-800 dark:ring-zinc-600 dark:text-white dark:placeholder-zinc-500"
                                />
                            </div>

                            <!-- Eventos -->
                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Eventos
                                </label>
                                <div class="max-h-48 space-y-2 overflow-y-auto rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200/80 dark:ring-zinc-600 dark:bg-zinc-800/50">
                                    <Checkbox
                                        v-for="event in PIXEL_X_EVENTS"
                                        :key="event.slug"
                                        :model-value="isEventSelected(event.slug)"
                                        :label="event.label"
                                        class="block"
                                        @update:model-value="toggleEvent(event.slug)"
                                    />
                                </div>
                            </div>

                            <!-- Produtos -->
                            <div>
                                <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Produtos
                                    <span class="font-normal text-zinc-500">(opcional - deixe vazio para todos)</span>
                                </label>
                                <div class="max-h-40 space-y-2 overflow-y-auto rounded-xl bg-white p-3 shadow-sm ring-1 ring-zinc-200/80 dark:ring-zinc-600 dark:bg-zinc-800/50">
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

                            <!-- Toggle ativo/inativo -->
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
                            <Button :disabled="saving" @click="saveIntegration">
                                {{ saving ? 'Salvando...' : 'Salvar' }}
                            </Button>
                        </div>
                    </div>
                </div>

                <!-- Modal: detalhe do log -->
                <div
                    v-if="logDetailModal"
                    class="absolute inset-0 z-10 flex items-center justify-center bg-zinc-900/40 p-4 dark:bg-zinc-950/60"
                    @click.self="closeLogDetail"
                >
                    <div
                        class="flex max-h-[90vh] w-full max-w-lg flex-col rounded-2xl bg-white shadow-2xl dark:bg-zinc-800"
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
                            <template v-else-if="selectedLog">
                                <div class="mb-4 flex flex-wrap items-center gap-2">
                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ selectedLog.event_label || selectedLog.event }}
                                    </span>
                                    <span
                                        :class="[
                                            'rounded px-2 py-0.5 text-xs font-medium',
                                            selectedLog.success
                                                ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
                                                : 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
                                        ]"
                                    >
                                        {{ selectedLog.success ? 'Sucesso' : 'Falha' }}
                                        <span v-if="selectedLog.response_status != null">
                                            (HTTP {{ selectedLog.response_status }})
                                        </span>
                                    </span>
                                    <span v-if="selectedLog.source === 'test'" class="rounded bg-zinc-200 px-2 py-0.5 text-xs dark:bg-zinc-600">
                                        Teste manual
                                    </span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ formatLogDate(selectedLog.created_at) }}
                                    </span>
                                </div>
                                <p
                                    v-if="selectedLog.error_message"
                                    class="mb-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-900/20 dark:text-red-300"
                                >
                                    {{ selectedLog.error_message }}
                                </p>
                                <div class="space-y-4">
                                    <div>
                                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            Payload enviado (request)
                                        </span>
                                        <pre class="max-h-64 overflow-auto rounded-xl bg-zinc-50 p-4 text-xs leading-relaxed text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">{{ formatPayload(selectedLog.request_payload) }}</pre>
                                    </div>
                                    <div>
                                        <span class="mb-1 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                            Resposta do servidor (response)
                                        </span>
                                        <p v-if="selectedLog.response_status != null" class="mb-1 text-xs text-zinc-600 dark:text-zinc-400">
                                            Status: {{ selectedLog.response_status }}
                                        </p>
                                        <pre class="max-h-64 overflow-auto rounded-xl bg-zinc-50 p-4 text-xs leading-relaxed text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">{{ formatPayload(selectedLog.response_body) }}</pre>
                                    </div>
                                </div>
                            </template>
                            <p v-else class="py-8 text-center text-sm text-zinc-500">
                                Não foi possível carregar o detalhe do log.
                            </p>
                        </div>
                        <div class="bg-zinc-50/80 px-5 py-3 dark:bg-zinc-800/80">
                            <Button variant="outline" size="sm" class="w-full sm:w-auto" @click="closeLogDetail">
                                Fechar
                            </Button>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
