<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import { X, ExternalLink, Copy, Check, ChevronDown, ChevronUp } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    gatewaySlug: { type: String, default: null },
});

const emit = defineEmits(['close', 'saved']);

function getCsrfToken() {
    return typeof document !== 'undefined'
        ? (document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ||
            document.querySelector('meta[name="X-XSRF-TOKEN"]')?.getAttribute('content') ||
            '')
        : '';
}

const gateway = ref(null);
const loading = ref(false);
const saving = ref(false);
const testing = ref(false);
const testMessage = ref(null);
const testSuccess = ref(null);
const credentialValues = ref({});
const secretMaskPlaceholders = ref({});
const certificateFile = ref(null);

const SECRET_FIELD_KEYS = ['secret_key', 'webhook_secret', 'webhook_signing_secret'];

function isSecretCredentialField(field) {
    const key = field?.key;
    const type = field?.type || 'text';
    return type === 'password' || (key && SECRET_FIELD_KEYS.includes(key));
}

function isMaskPlaceholder(key, value) {
    const mask = secretMaskPlaceholders.value[key];
    return !!mask && String(value ?? '').trim() === mask;
}

function hasSecretValue(key, value) {
    const trimmed = String(value ?? '').trim();
    return trimmed !== '' && (isMaskPlaceholder(key, trimmed) || !trimmed.includes('••••'));
}

function resolveSecretForSubmit(key, value) {
    if (isMaskPlaceholder(key, value)) {
        return '';
    }
    return value != null ? String(value).trim() : '';
}

function secretInputType(field, key) {
    if (!isSecretCredentialField(field)) {
        return field.type === 'password' ? 'password' : 'text';
    }
    if (isMaskPlaceholder(key, credentialValues.value[key])) {
        return 'text';
    }
    return 'password';
}

function onSecretFocus(key) {
    if (isMaskPlaceholder(key, credentialValues.value[key])) {
        credentialValues.value[key] = '';
    }
}

function onSecretBlur(key) {
    const v = credentialValues.value[key];
    if ((v == null || String(v).trim() === '') && secretMaskPlaceholders.value[key]) {
        credentialValues.value[key] = secretMaskPlaceholders.value[key];
    }
}

function buildCredentialInitial(keys, saved) {
    const initial = {};
    const masks = {};
    for (const k of keys) {
        if ((k.type || 'text') === 'file') continue;
        const key = k.key;
        if (key == null) continue;
        const v = saved[key];
        if (k.type === 'boolean') {
            initial[key] = v === true || v === '1' || v === 'true';
        } else {
            const str = v != null && v !== '' ? String(v) : '';
            initial[key] = str;
            if (isSecretCredentialField(k) && str.includes('••••')) {
                masks[key] = str;
            }
        }
    }
    secretMaskPlaceholders.value = masks;
    return initial;
}
const webhookCopied = ref(false);
const webhookCopiedSecondary = ref(false);
const disconnecting = ref(false);
const fees = ref({
    pix: { percent: 0, fixed_cents: 0 },
    card: { percent: 0, fixed_cents: 0 },
    boleto: { percent: 0, fixed_cents: 0 },
});
const savingFees = ref(false);
const feesMessage = ref('');
const feesPanelOpen = ref(false);
const advancedPanelOpen = ref(false);
const rotatingWebhook = ref(false);
const acceptingParcelado = ref(false);
const parceladoMessage = ref('');

const feeMethodLabels = {
    pix: 'PIX',
    card: 'Cartão',
    boleto: 'Boleto',
};

const isCajuPay = computed(() => (props.gatewaySlug || gateway.value?.slug || '').toLowerCase() === 'cajupay');

const standardCredentialFields = computed(() => {
    const keys = gateway.value?.credential_keys || [];
    return keys.filter((k) => !k.advanced);
});

const advancedCredentialFields = computed(() => {
    const keys = gateway.value?.credential_keys || [];
    return keys.filter((k) => k.advanced);
});

const hasAdvancedCredentialFields = computed(() => advancedCredentialFields.value.length > 0);

const cajupayWebhookNeedsAttention = computed(() => {
    if (!isCajuPay.value || !gateway.value) return false;
    const status = gateway.value.webhook_setup_status;
    if (!status || typeof status !== 'object') return false;
    return status.has_enabled_endpoint === false || status.subscribes_checkout_events === false;
});

async function reloadGateway(slug) {
    const { data } = await axios.get(
        `/configuracoes/gateways/${encodeURIComponent(slug)}`,
        { params: { t: Date.now() } }
    );
    gateway.value = data;
    const keys = data.credential_keys || [];
    const saved = data.credential_values || {};
    credentialValues.value = { ...buildCredentialInitial(keys, saved) };
}

async function rotateWebhookSecret() {
    const url = gateway.value?.webhook_rotate_url;
    if (!url) return;
    rotatingWebhook.value = true;
    testMessage.value = null;
    try {
        const { data } = await axios.post(
            url,
            {},
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        testSuccess.value = data.success;
        const parts = [data.message, data.webhook_warning].filter(Boolean);
        testMessage.value = parts.join(' ');
        if (gateway.value?.slug) {
            await reloadGateway(gateway.value.slug);
        }
    } catch (err) {
        testSuccess.value = false;
        testMessage.value = err.response?.data?.message || 'Erro ao rotacionar token do webhook.';
    } finally {
        rotatingWebhook.value = false;
    }
}

async function acceptPixParceladoEnrollment() {
    const url = gateway.value?.pix_parcelado_accept_url;
    if (!url) return;
    acceptingParcelado.value = true;
    parceladoMessage.value = '';
    try {
        const { data } = await axios.post(
            url,
            {},
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } },
        );
        testSuccess.value = data.success;
        parceladoMessage.value = data.message || 'Contrato aceito.';
        if (gateway.value?.slug) {
            await reloadGateway(gateway.value.slug);
        }
    } catch (err) {
        testSuccess.value = false;
        parceladoMessage.value = err.response?.data?.message || 'Erro ao aceitar contrato PIX Parcelado.';
    } finally {
        acceptingParcelado.value = false;
    }
}

async function loadFees(slug) {
    try {
        const { data } = await axios.get(`/configuracoes/gateways/${encodeURIComponent(slug)}/fees`);
        if (data.fees) {
            fees.value = { ...fees.value, ...data.fees };
        }
    } catch {
        feesMessage.value = '';
    }
}

async function saveFees() {
    if (!props.gatewaySlug) return;
    savingFees.value = true;
    feesMessage.value = '';
    try {
        await axios.put(`/configuracoes/gateways/${encodeURIComponent(props.gatewaySlug)}/fees`, { fees: fees.value });
        feesMessage.value = 'Taxas salvas.';
    } catch {
        feesMessage.value = 'Erro ao salvar taxas.';
    } finally {
        savingFees.value = false;
    }
}

async function copyWebhookUrl() {
    const url = gateway.value?.webhook_url;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        webhookCopied.value = true;
        setTimeout(() => { webhookCopied.value = false; }, 2000);
    } catch {
        webhookCopied.value = false;
    }
}

async function copyWebhookUrlSecondary() {
    const url = gateway.value?.webhook_url_secondary;
    if (!url) return;
    try {
        await navigator.clipboard.writeText(url);
        webhookCopiedSecondary.value = true;
        setTimeout(() => { webhookCopiedSecondary.value = false; }, 2000);
    } catch {
        webhookCopiedSecondary.value = false;
    }
}

watch(
    () => [props.open, props.gatewaySlug],
    async ([open, slug]) => {
        if (open && slug) {
            loading.value = true;
            testMessage.value = null;
            feesPanelOpen.value = false;
            advancedPanelOpen.value = false;
            feesMessage.value = '';
            webhookCopied.value = false;
            webhookCopiedSecondary.value = false;
            credentialValues.value = {};
            secretMaskPlaceholders.value = {};
            try {
                const { data } = await axios.get(
                    `/configuracoes/gateways/${encodeURIComponent(slug)}`,
                    { params: { t: Date.now() } }
                );
                gateway.value = data;
                await loadFees(slug);
                const keys = data.credential_keys || [];
                const saved = data.credential_values || {};
                credentialValues.value = { ...buildCredentialInitial(keys, saved) };
                certificateFile.value = null;
            } catch {
                gateway.value = null;
            } finally {
                loading.value = false;
            }
        } else {
            gateway.value = null;
        }
    },
    { immediate: true }
);

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';

function buildTestPayload() {
    const keys = gateway.value?.credential_keys || [];
    const payload = {};
    for (const k of keys) {
        if ((k.type || 'text') === 'file') continue;
        const v = credentialValues.value[k.key];
        if (k.type === 'boolean') {
            payload[k.key] = v === true || v === '1' || v === 'true';
        } else if (isSecretCredentialField(k)) {
            const resolved = resolveSecretForSubmit(k.key, v);
            if (resolved !== '') {
                payload[k.key] = resolved;
            }
        } else if (v != null && String(v).trim() !== '') {
            payload[k.key] = String(v).trim();
        }
    }
    return payload;
}

async function testConnection() {
    if (!gateway.value?.slug) return;
    const keys = gateway.value.credential_keys || [];
    const certificateKey = gateway.value.certificate_key;
    for (const k of keys) {
        if (k.key === certificateKey) continue;
        if ((k.type || 'text') === 'boolean') continue;
        if (k.optional) continue;
        if (
            (gateway.value.slug === 'spacepag' || gateway.value.slug === 'cajupay')
            && (k.key === 'secret_key' || k.key === 'public_key')
            && (
                gateway.value.spacepag_keys_configured
                || (gateway.value.slug === 'cajupay' && gateway.value.is_configured)
            )
        ) {
            continue;
        }
        const v = credentialValues.value[k.key];
        const satisfied = isSecretCredentialField(k)
            ? hasSecretValue(k.key, v)
            : (v != null && String(v).trim() !== '');
        if (!satisfied) {
            testMessage.value = 'Preencha todas as credenciais obrigatórias para testar.';
            testSuccess.value = false;
            return;
        }
    }
    if (certificateKey && !gateway.value.certificate_configured && !certificateFile.value) {
        testMessage.value = 'Envie e salve o certificado P12 antes de testar.';
        testSuccess.value = false;
        return;
    }
    const payload = buildTestPayload();
    testing.value = true;
    testMessage.value = null;
    try {
        const { data } = await axios.post(
            `/configuracoes/gateways/${encodeURIComponent(gateway.value.slug)}/test`,
            payload,
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        testSuccess.value = data.success;
        const parts = [data.message || (data.success ? 'Conexão OK.' : 'Falha.'), data.webhook_warning].filter(Boolean);
        testMessage.value = parts.join(' ');
    } catch (err) {
        testSuccess.value = false;
        testMessage.value =
            err.response?.data?.message || 'Erro ao testar conexão.';
    } finally {
        testing.value = false;
    }
}

async function save() {
    if (!gateway.value?.slug) return;
    saving.value = true;
    testMessage.value = null;
    try {
        const keys = gateway.value.credential_keys || [];
        const certificateKey = gateway.value.certificate_key;

        // 1) Salva sempre as credenciais (sem arquivo) em JSON
        const payload = {};
        for (const k of keys) {
            if (k.key === certificateKey) continue;
            const v = credentialValues.value[k.key];
            if (k.type === 'boolean') {
                payload[k.key] = v === true || v === '1' || v === 'true';
            } else if (isSecretCredentialField(k)) {
                payload[k.key] = resolveSecretForSubmit(k.key, v);
            } else {
                payload[k.key] = v != null ? String(v).trim() : '';
            }
        }
        const { data } = await axios.put(
            `/configuracoes/gateways/${encodeURIComponent(gateway.value.slug)}`,
            payload,
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), 'Content-Type': 'application/json', Accept: 'application/json' } }
        );

        // 2) Se tiver certificado, envia em chamada separada
        if (certificateKey && certificateFile.value) {
            const form = new FormData();
            form.append(certificateKey, certificateFile.value);
            await axios.post(
                `/configuracoes/gateways/${encodeURIComponent(gateway.value.slug)}/certificate`,
                form,
                { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
            );
        }

        certificateFile.value = null;
        testSuccess.value = true;
        const saveParts = [data?.message || 'Credenciais salvas.', data?.webhook_warning].filter(Boolean);
        testMessage.value = saveParts.join(' ');
        if (gateway.value?.slug) {
            await reloadGateway(gateway.value.slug);
        }
        emit('saved');
        setTimeout(() => {
            emit('close');
        }, 1500);
    } catch (err) {
        testSuccess.value = false;
        const res = err.response?.data;
        let msg = res?.message || 'Erro ao salvar.';
        if (res?.errors && typeof res.errors === 'object') {
            const parts = Object.values(res.errors).flat().filter(Boolean);
            if (parts.length) msg = parts.join(' ');
        }
        testMessage.value = msg;
    } finally {
        saving.value = false;
    }
}

function close() {
    emit('close');
}

async function disconnectOAuth() {
    const url = gateway.value?.oauth_disconnect_url;
    if (!url) return;
    disconnecting.value = true;
    testMessage.value = null;
    try {
        await axios.post(
            url,
            {},
            { headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json' } }
        );
        testSuccess.value = true;
        testMessage.value = 'Conta desconectada.';
        emit('saved');
        const slug = gateway.value?.slug;
        if (slug) {
            const { data } = await axios.get(
                `/configuracoes/gateways/${encodeURIComponent(slug)}`,
                { params: { t: Date.now() } }
            );
            gateway.value = data;
        }
    } catch (err) {
        testSuccess.value = false;
        testMessage.value =
            err.response?.data?.message || 'Não foi possível desconectar.';
    } finally {
        disconnecting.value = false;
    }
}

const hasManualCredentialFields = computed(() => {
    const keys = gateway.value?.credential_keys || [];
    return keys.length > 0 || !!gateway.value?.certificate_key;
});

const canTestConnection = computed(() => {
    if (!gateway.value) return false;
    if (gateway.value.uses_oauth && !gateway.value.oauth_connected) {
        return false;
    }
    return true;
});
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
                class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl border-l border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
            >
                <div
                    class="flex items-center justify-between gap-2 rounded-tl-2xl border-b border-zinc-200 px-4 py-4 dark:border-zinc-700"
                >
                    <div class="flex min-w-0 flex-1 flex-wrap items-center gap-2">
                        <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                            {{ gateway?.name || 'Gateway' }}
                        </h2>
                    </div>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div v-if="loading" class="flex flex-1 items-center justify-center p-8">
                    <p class="text-sm text-zinc-500">Carregando...</p>
                </div>

                <div v-else-if="gateway" class="flex flex-1 flex-col overflow-y-auto p-4">
                    <!-- Criar conta -->
                    <a
                        v-if="gateway.signup_url"
                        :href="gateway.signup_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="mb-6 flex items-center gap-2 rounded-xl border-2 border-[var(--color-primary)] bg-[var(--color-primary)]/10 px-4 py-3 text-sm font-medium text-[var(--color-primary)] transition hover:bg-[var(--color-primary)]/20"
                    >
                        <ExternalLink class="h-4 w-4 shrink-0" />
                        Criar conta no {{ gateway.name }}
                    </a>

                    <!-- Webhook: URL(s) no painel do gateway + token nas credenciais (CajuPay, etc.) -->
                    <div
                        v-if="gateway.webhook_url"
                        class="mb-6 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
                    >
                        <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Webhook (URL no {{ gateway.name }})
                        </h3>
                        <template v-if="gateway.slug === 'cajupay'">
                            <p class="mb-3 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Ao salvar as chaves de API, o Getfy registra o webhook na CajuPay automaticamente
                                (eventos <code class="rounded bg-zinc-200 px-0.5 text-[11px] dark:bg-zinc-700">checkout.payment.*</code>
                                e <code class="rounded bg-zinc-200 px-0.5 text-[11px] dark:bg-zinc-700">pix.payment.*</code>).
                                Não é necessário configurar manualmente no painel CajuPay.
                            </p>
                            <div
                                v-if="gateway.webhook_auto_configured"
                                class="mb-3 flex flex-wrap items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-800 dark:bg-emerald-950/40"
                            >
                                <Check class="h-4 w-4 shrink-0 text-emerald-600 dark:text-emerald-400" />
                                <span class="text-xs font-medium text-emerald-800 dark:text-emerald-200">Webhook já configurado</span>
                            </div>
                            <p
                                v-if="cajupayWebhookNeedsAttention"
                                class="mb-3 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                            >
                                A CajuPay indica que o webhook pode estar incompleto. Salve as credenciais novamente ou use “Rotacionar token” na configuração avançada.
                            </p>
                        </template>
                        <p v-else class="mb-2 text-xs text-zinc-600 dark:text-zinc-400">
                            Configure esta URL no painel do {{ gateway.name }} (notificações de pagamento).
                        </p>

                        <div
                            v-if="gateway.slug === 'cajupay' && gateway.is_connected"
                            class="mb-6 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
                        >
                            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                PIX Parcelado
                            </h3>
                            <p class="mb-3 text-xs leading-relaxed text-zinc-600 dark:text-zinc-400">
                                Adesão necessária para oferecer PIX Parcelado no checkout. Status:
                                <strong>{{ gateway.pix_parcelado_enrollment?.status || 'desconhecido' }}</strong>
                            </p>
                            <div
                                v-if="gateway.pix_parcelado_enrolled"
                                class="mb-3 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-3 py-2 dark:border-emerald-800 dark:bg-emerald-950/40"
                            >
                                <Check class="h-4 w-4 shrink-0 text-emerald-600" />
                                <span class="text-xs font-medium text-emerald-800 dark:text-emerald-200">Adesão ativa</span>
                            </div>
                            <button
                                v-else-if="gateway.pix_parcelado_accept_url"
                                type="button"
                                class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-50"
                                :disabled="acceptingParcelado"
                                @click="acceptPixParceladoEnrollment"
                            >
                                {{ acceptingParcelado ? 'Aceitando…' : 'Aceitar contrato PIX Parcelado' }}
                            </button>
                            <p v-if="parceladoMessage" class="mt-2 text-xs" :class="testSuccess ? 'text-emerald-700' : 'text-red-600'">
                                {{ parceladoMessage }}
                            </p>
                        </div>

                        <p class="mb-1 text-[11px] font-medium text-zinc-600 dark:text-zinc-400">URL principal</p>
                        <div class="mb-3 flex gap-2">
                            <input
                                :value="gateway.webhook_url"
                                type="text"
                                readonly
                                class="flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                            />
                            <button
                                type="button"
                                class="flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                @click="copyWebhookUrl"
                            >
                                <Check v-if="webhookCopied" class="h-4 w-4 text-emerald-600" />
                                <Copy v-else class="h-4 w-4" />
                                {{ webhookCopied ? 'Copiado!' : 'Copiar' }}
                            </button>
                        </div>
                        <template v-if="gateway.webhook_url_secondary && gateway.slug !== 'cajupay'">
                            <p class="mb-1 text-[11px] font-medium text-zinc-600 dark:text-zinc-400">URL alternativa (mesmo endpoint)</p>
                            <div class="flex gap-2">
                                <input
                                    :value="gateway.webhook_url_secondary"
                                    type="text"
                                    readonly
                                    class="flex-1 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs text-zinc-700 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                                />
                                <button
                                    type="button"
                                    class="flex shrink-0 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                                    @click="copyWebhookUrlSecondary"
                                >
                                    <Check v-if="webhookCopiedSecondary" class="h-4 w-4 text-emerald-600" />
                                    <Copy v-else class="h-4 w-4" />
                                    {{ webhookCopiedSecondary ? 'Copiado!' : 'Copiar' }}
                                </button>
                            </div>
                        </template>
                    </div>

                    <h3
                        v-if="hasManualCredentialFields"
                        class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                    >
                        Credenciais
                    </h3>
                    <div v-if="hasManualCredentialFields" class="space-y-4">
                        <div
                            v-for="field in standardCredentialFields"
                            :key="field.key"
                        >
                            <label
                                class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                            >
                                {{ field.label }}
                                <span v-if="field.optional" class="ml-1 text-xs font-normal text-zinc-500">(opcional)</span>
                            </label>
                            <p
                                v-if="field.hint"
                                class="mb-2 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400"
                            >
                                {{ field.hint }}
                            </p>
                            <template v-if="field.type === 'file'">
                                <input
                                    type="file"
                                    accept=".p12"
                                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-[var(--color-primary)] file:px-4 file:py-2 file:text-white file:transition dark:text-zinc-400"
                                    @change="certificateFile = $event.target.files?.[0] || null"
                                />
                                <p
                                    v-if="gateway.certificate_configured && !certificateFile"
                                    class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                                >
                                    <span v-if="gateway.certificate_filename" class="font-medium text-zinc-700 dark:text-zinc-300">Em uso: {{ gateway.certificate_filename }}</span>
                                    <template v-else>Certificado já enviado.</template>
                                    <span> Envie novamente para substituir.</span>
                                </p>
                            </template>
                            <template v-else-if="field.type === 'boolean'">
                                <label class="flex cursor-pointer items-center gap-2">
                                    <input
                                        v-model="credentialValues[field.key]"
                                        type="checkbox"
                                        class="h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)] dark:border-zinc-600"
                                    />
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">Sim (somente para testes)</span>
                                </label>
                            </template>
                            <input
                                v-else
                                v-model="credentialValues[field.key]"
                                :type="secretInputType(field, field.key)"
                                :placeholder="isSecretCredentialField(field) && secretMaskPlaceholders[field.key] ? 'Clique para substituir' : field.label"
                                :class="[
                                    inputClass,
                                    isMaskPlaceholder(field.key, credentialValues[field.key]) ? 'font-mono text-sm tracking-wide' : '',
                                ]"
                                autocomplete="off"
                                @focus="() => { if (isSecretCredentialField(field)) onSecretFocus(field.key); }"
                                @blur="() => { if (isSecretCredentialField(field)) onSecretBlur(field.key); }"
                            />
                        </div>
                    </div>

                    <div
                        v-if="hasAdvancedCredentialFields"
                        class="mt-4 border-t border-zinc-200 pt-4 dark:border-zinc-700"
                    >
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 text-left"
                            :aria-expanded="advancedPanelOpen"
                            @click="advancedPanelOpen = !advancedPanelOpen"
                        >
                            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                                Configuração avançada
                            </h3>
                            <ChevronUp v-if="advancedPanelOpen" class="h-5 w-5 shrink-0 text-zinc-400" />
                            <ChevronDown v-else class="h-5 w-5 shrink-0 text-zinc-400" />
                        </button>
                        <div v-show="advancedPanelOpen" class="mt-3 space-y-4">
                            <div
                                v-for="field in advancedCredentialFields"
                                :key="field.key"
                            >
                                <label
                                    class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300"
                                >
                                    {{ field.label }}
                                    <span v-if="field.optional" class="ml-1 text-xs font-normal text-zinc-500">(opcional)</span>
                                </label>
                                <p
                                    v-if="field.hint"
                                    class="mb-2 text-xs leading-relaxed text-zinc-500 dark:text-zinc-400"
                                >
                                    {{ field.hint }}
                                </p>
                                <input
                                    v-model="credentialValues[field.key]"
                                    :type="secretInputType(field, field.key)"
                                    :placeholder="secretMaskPlaceholders[field.key] ? 'Clique para substituir' : (field.key === 'webhook_signing_secret' ? 'Configurado automaticamente ao salvar' : field.label)"
                                    :class="[
                                        inputClass,
                                        isMaskPlaceholder(field.key, credentialValues[field.key]) ? 'font-mono text-sm tracking-wide' : '',
                                    ]"
                                    autocomplete="off"
                                    @focus="onSecretFocus(field.key)"
                                    @blur="onSecretBlur(field.key)"
                                />
                            </div>
                            <Button
                                v-if="isCajuPay && gateway.webhook_rotate_url"
                                type="button"
                                variant="outline"
                                class="w-full"
                                :disabled="rotatingWebhook"
                                @click="rotateWebhookSecret"
                            >
                                {{ rotatingWebhook ? 'Rotacionando…' : 'Rotacionar token do webhook' }}
                            </Button>
                        </div>
                    </div>

                    <div
                        v-if="gateway.uses_oauth"
                        class="mb-6 mt-6 rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
                    >
                        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Conectar via OAuth
                        </h3>
                        <div class="mb-4 flex flex-col gap-2">
                            <Button
                                v-if="gateway.oauth_start_url && !gateway.oauth_connected"
                                as="a"
                                :href="gateway.oauth_start_url"
                                variant="primary"
                                class="w-full justify-center text-center no-underline sm:w-full"
                            >
                                <ExternalLink class="h-4 w-4 shrink-0" aria-hidden="true" />
                                Conectar
                            </Button>
                            <p
                                v-if="gateway.oauth_start_url && !gateway.oauth_connected"
                                class="text-center text-[11px] text-zinc-500 dark:text-zinc-400"
                            >
                                Abre o fluxo de autorização do gateway e, após o consentimento, salva o token no Getfy.
                            </p>
                            <Button
                                v-if="gateway.oauth_disconnect_url && gateway.oauth_connected"
                                type="button"
                                variant="outline"
                                class="w-full justify-center sm:w-auto"
                                :disabled="disconnecting"
                                @click="disconnectOAuth"
                            >
                                {{ disconnecting ? 'Desconectando...' : 'Desconectar' }}
                            </Button>
                        </div>
                        <p
                            v-if="!gateway.oauth_client_configured"
                            class="mb-3 text-xs text-amber-700 dark:text-amber-300"
                        >
                            A identificação do aplicativo OAuth ainda não está configurada neste servidor (variáveis de ambiente ou registro do gateway).
                        </p>
                        <template v-else>
                            <p
                                v-if="gateway.oauth_callback_url && !gateway.oauth_connected"
                                class="mb-3 text-xs text-zinc-600 dark:text-zinc-400"
                            >
                                Na primeira conexão, cadastre a URL de callback no painel do integrador, se solicitado.
                            </p>
                            <p
                                v-if="gateway.oauth_callback_url"
                                class="mb-1 text-xs font-medium text-zinc-600 dark:text-zinc-400"
                            >
                                URL de redirecionamento (callback)
                            </p>
                            <p
                                v-if="gateway.oauth_callback_url"
                                class="mb-3 break-all rounded-lg bg-white px-2 py-1.5 font-mono text-[11px] text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200"
                            >
                                {{ gateway.oauth_callback_url }}
                            </p>
                        </template>
                        <p
                            v-if="gateway.oauth_connected"
                            class="text-xs text-emerald-700 dark:text-emerald-300"
                        >
                            Conta autorizada. Teste a conexão abaixo ou desconecte.
                        </p>
                    </div>

                    <div class="mt-6 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <button
                            type="button"
                            class="flex w-full items-start justify-between gap-3 text-left"
                            :aria-expanded="feesPanelOpen"
                            @click="feesPanelOpen = !feesPanelOpen"
                        >
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">
                                        Taxas para comissões (líquido)
                                    </h3>
                                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
                                        Opcional
                                    </span>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Só necessário se você usa co-produção ou afiliados e quer descontar a taxa do gateway no valor líquido.
                                </p>
                                <p v-if="isCajuPay && !feesPanelOpen" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Padrão CajuPay PIX: 0% + R$ 0,99 fixo.
                                </p>
                            </div>
                            <ChevronUp v-if="feesPanelOpen" class="mt-0.5 h-5 w-5 shrink-0 text-zinc-400" />
                            <ChevronDown v-else class="mt-0.5 h-5 w-5 shrink-0 text-zinc-400" />
                        </button>

                        <div v-show="feesPanelOpen" class="mt-3 space-y-3">
                            <div
                                v-for="method in ['pix', 'card', 'boleto']"
                                :key="method"
                                class="grid grid-cols-3 items-end gap-2"
                            >
                                <span class="text-xs font-medium text-zinc-600 dark:text-zinc-300">
                                    {{ feeMethodLabels[method] }}
                                </span>
                                <div>
                                    <label class="text-[10px] text-zinc-500">Percentual (%)</label>
                                    <input
                                        v-model.number="fees[method].percent"
                                        type="number"
                                        step="0.01"
                                        min="0"
                                        class="w-full rounded border px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                    />
                                </div>
                                <div>
                                    <label class="text-[10px] text-zinc-500">Fixo (centavos)</label>
                                    <input
                                        v-model.number="fees[method].fixed_cents"
                                        type="number"
                                        min="0"
                                        class="w-full rounded border px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                                    />
                                </div>
                            </div>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                Ex.: 99 centavos = R$ 0,99. Deixe em branco (0) se não quiser usar taxa fixa.
                            </p>
                            <Button type="button" class="w-full" variant="outline" :disabled="savingFees" @click="saveFees">
                                {{ savingFees ? 'Salvando…' : 'Salvar taxas' }}
                            </Button>
                            <p v-if="feesMessage" class="text-xs text-zinc-500">{{ feesMessage }}</p>
                        </div>
                    </div>

                    <p
                        v-if="testMessage"
                        :class="[
                            'mt-4 rounded-lg px-3 py-2 text-sm',
                            testSuccess
                                ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300'
                                : 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
                        ]"
                    >
                        {{ testMessage }}
                    </p>

                    <div class="mt-6 flex flex-col gap-2">
                        <Button
                            variant="outline"
                            :disabled="testing || !canTestConnection"
                            @click="testConnection"
                        >
                            {{ testing ? 'Testando...' : 'Testar conexão' }}
                        </Button>
                        <Button
                            v-if="hasManualCredentialFields"
                            :disabled="saving"
                            @click="save"
                        >
                            {{ saving ? 'Salvando...' : 'Salvar' }}
                        </Button>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
