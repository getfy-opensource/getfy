<script setup>
import { ref, watch } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import GatewayRedundancySidebar from '@/components/produtos/GatewayRedundancySidebar.vue';
import { Settings2, KeyRound, Copy, RefreshCw, X, Check, ImagePlus, Trash2, Palette } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    application: { type: Object, required: true },
    gateways_by_method: { type: Object, default: () => ({}) },
    api_key_reveal: { type: String, default: null },
});

const pg = props.application.payment_gateways || {};
const form = useForm({
    name: props.application.name,
    payment_gateways: {
        pix: pg.pix ?? '',
        pix_redundancy: Array.isArray(pg.pix_redundancy) ? pg.pix_redundancy : [],
        card: pg.card ?? '',
        card_redundancy: Array.isArray(pg.card_redundancy) ? pg.card_redundancy : [],
        boleto: pg.boleto ?? '',
        boleto_redundancy: Array.isArray(pg.boleto_redundancy) ? pg.boleto_redundancy : [],
        pix_auto: pg.pix_auto ?? '',
        pix_auto_redundancy: Array.isArray(pg.pix_auto_redundancy) ? pg.pix_auto_redundancy : [],
        crypto: pg.crypto ?? '',
        crypto_redundancy: Array.isArray(pg.crypto_redundancy) ? pg.crypto_redundancy : [],
    },
    webhook_url: props.application.webhook_url ?? '',
    default_return_url: props.application.default_return_url ?? '',
    webhook_secret: props.application.webhook_secret ?? '',
    allowed_ips: props.application.allowed_ips ?? '',
    is_active: props.application.is_active !== false,
    checkout_sidebar_bg: props.application.checkout_sidebar_bg ?? '',
});

const showKeyModal = ref(!!props.api_key_reveal);
const revealedKey = ref(props.api_key_reveal ?? '');
const copyKeyFeedback = ref(false);

const logoUrl = ref(props.application.logo_url ?? null);
const logoUploading = ref(false);
const logoError = ref(null);
const logoInputRef = ref(null);

watch(() => props.application.logo_url, (v) => {
    logoUrl.value = v ?? null;
}, { immediate: true });

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function onLogoFileChange(ev) {
    const file = ev.target?.files?.[0];
    if (!file) return;
    logoError.value = null;
    logoUploading.value = true;
    try {
        const formData = new FormData();
        formData.append('image', file);
        const res = await fetch(`/aplicacoes-api/${props.application.id}/logo`, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) {
            logoError.value = data.message || 'Falha ao enviar a logo.';
            return;
        }
        logoUrl.value = data.url ?? null;
    } catch (e) {
        logoError.value = e?.message || 'Erro ao enviar a logo.';
    } finally {
        logoUploading.value = false;
        if (logoInputRef.value) logoInputRef.value.value = '';
    }
}

async function removeLogo() {
    if (!logoUrl.value) return;
    logoError.value = null;
    logoUploading.value = true;
    try {
        const res = await fetch(`/aplicacoes-api/${props.application.id}/logo`, {
            method: 'DELETE',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': getCsrfToken() },
            credentials: 'same-origin',
        });
        if (!res.ok) {
            const data = await res.json().catch(() => ({}));
            logoError.value = data.message || 'Falha ao remover a logo.';
            return;
        }
        logoUrl.value = null;
    } catch (e) {
        logoError.value = e?.message || 'Erro ao remover a logo.';
    } finally {
        logoUploading.value = false;
    }
}

watch(() => props.api_key_reveal, (val) => {
    if (val) {
        revealedKey.value = val;
        showKeyModal.value = true;
        copyKeyFeedback.value = false;
    }
}, { immediate: false });

function gatewayOptions(method) {
    const list = props.gateways_by_method?.[method] ?? [];
    return [
        { value: '', label: 'Nenhum' },
        ...list.map((g) => ({ value: g.slug, label: g.name })),
    ];
}

const METHOD_LABELS = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto', pix_auto: 'PIX automático', crypto: 'Criptomoeda' };
const redundancySidebarOpen = ref(false);
const redundancySidebarMethod = ref(null);

function openRedundancySidebar(method) {
    redundancySidebarMethod.value = method;
    redundancySidebarOpen.value = true;
}

function canShowRedundancy(slug) {
    return slug !== '' && slug != null;
}

function submit() {
    form.put(`/aplicacoes-api/${props.application.id}`);
}

async function copyKey() {
    const text = revealedKey.value;
    if (!text) return;
    try {
        if (navigator.clipboard && window.isSecureContext) {
            await navigator.clipboard.writeText(text);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            ta.style.top = '0';
            document.body.appendChild(ta);
            ta.focus();
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }
        copyKeyFeedback.value = true;
        setTimeout(() => { copyKeyFeedback.value = false; }, 2000);
    } catch {
        copyKeyFeedback.value = false;
    }
}

function closeKeyModal() {
    showKeyModal.value = false;
}

function regenerateKey() {
    if (!window.confirm('Gerar uma nova API key? A key atual deixará de funcionar imediatamente.')) return;
    router.post(`/aplicacoes-api/${props.application.id}/regenerate-key`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Editar aplicação</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ application.name }} ({{ application.slug }})</p>
            </div>
            <Button variant="outline" size="sm" class="inline-flex items-center gap-2" @click="regenerateKey">
                <RefreshCw class="h-4 w-4" />
                Gerar nova API key
            </Button>
        </div>

        <form class="max-w-2xl space-y-6" @submit.prevent="submit">
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome</label>
                <input v-model="form.name" type="text" required class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 p-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <ImagePlus class="h-4 w-4" />
                    Logo do checkout
                </h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Exibida no Checkout Pro (página de pagamento hospedada).</p>
                <div class="mt-4 flex flex-wrap items-center gap-4">
                    <div v-if="logoUrl" class="flex items-center gap-3">
                        <img :src="logoUrl" alt="Logo" class="h-14 w-auto max-w-[180px] rounded-lg border border-zinc-200 object-contain dark:border-zinc-600" />
                        <Button type="button" variant="outline" size="sm" class="text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20" :disabled="logoUploading" @click="removeLogo">
                            <Trash2 class="h-4 w-4" />
                            Remover logo
                        </Button>
                    </div>
                    <div class="flex items-center gap-2">
                        <input
                            ref="logoInputRef"
                            type="file"
                            accept="image/*"
                            class="hidden"
                            @change="onLogoFileChange"
                        />
                        <Button type="button" variant="outline" size="sm" :disabled="logoUploading" @click="logoInputRef?.click()">
                            {{ logoUrl ? 'Trocar logo' : 'Enviar logo' }}
                        </Button>
                    </div>
                </div>
                <p v-if="logoError" class="mt-2 text-sm text-red-600">{{ logoError }}</p>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 p-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <Palette class="h-4 w-4" />
                    Cor de fundo do checkout
                </h2>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Cor da coluna esquerda (resumo) no Checkout Pro.</p>
                <div class="mt-4 flex flex-wrap items-center gap-4">
                    <input
                        :value="form.checkout_sidebar_bg || '#18181b'"
                        type="color"
                        class="h-10 w-14 cursor-pointer rounded border border-zinc-300 bg-white p-1 dark:border-zinc-600"
                        :title="form.checkout_sidebar_bg || '#18181b'"
                        @input="form.checkout_sidebar_bg = $event.target.value"
                    />
                    <input
                        v-model="form.checkout_sidebar_bg"
                        type="text"
                        class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 font-mono text-sm w-28"
                        placeholder="#18181b"
                        maxlength="7"
                    />
                    <Button type="button" variant="outline" size="sm" @click="form.checkout_sidebar_bg = ''">
                        Restaurar padrão
                    </Button>
                </div>
                <p v-if="form.errors.checkout_sidebar_bg" class="mt-2 text-sm text-red-600">{{ form.errors.checkout_sidebar_bg }}</p>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 p-4">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <Settings2 class="h-4 w-4" />
                    Gateways por método
                </h2>
                <div class="mt-4 space-y-3">
                    <template v-for="method in ['pix', 'card', 'boleto', 'pix_auto', 'crypto']" :key="method">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="w-24 text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ METHOD_LABELS[method] || method }}</span>
                            <select
                                v-model="form.payment_gateways[method]"
                                class="rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2 text-sm min-w-[160px]"
                            >
                                <option v-for="opt in gatewayOptions(method)" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                            <Button
                                v-if="canShowRedundancy(form.payment_gateways[method])"
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="openRedundancySidebar(method)"
                            >
                                Redundância
                            </Button>
                        </div>
                    </template>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL do webhook (opcional)</label>
                <input v-model="form.webhook_url" type="url" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" />
                <p v-if="form.errors.webhook_url" class="mt-1 text-sm text-red-600">{{ form.errors.webhook_url }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL de retorno padrão (opcional)</label>
                <input v-model="form.default_return_url" type="url" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Usada no Checkout Pro quando a sessão não enviar <span class="font-mono">return_url</span>.
                </p>
                <p v-if="form.errors.default_return_url" class="mt-1 text-sm text-red-600">{{ form.errors.default_return_url }}</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Webhook secret (opcional)</label>
                <input v-model="form.webhook_secret" type="password" autocomplete="off" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2" placeholder="Secret para validar assinatura HMAC" />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Usado para assinar o body do webhook (X-Getfy-Signature). Deixe em branco para não alterar.</p>
                <p v-if="form.errors.webhook_secret" class="mt-1 text-sm text-red-600">{{ form.errors.webhook_secret }}</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">IPs permitidos (opcional)</label>
                <textarea v-model="form.allowed_ips" rows="3" class="mt-1 block w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-900 px-3 py-2"></textarea>
                <p v-if="form.errors.allowed_ips" class="mt-1 text-sm text-red-600">{{ form.errors.allowed_ips }}</p>
            </div>

            <div class="flex items-center gap-2">
                <input v-model="form.is_active" type="checkbox" id="is_active" class="h-4 w-4 rounded border-zinc-300" />
                <label for="is_active" class="text-sm text-zinc-700 dark:text-zinc-300">Aplicação ativa</label>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing">Salvar</Button>
                <Button as="a" href="/aplicacoes-api" variant="outline">Voltar</Button>
            </div>
        </form>

        <GatewayRedundancySidebar
            :open="redundancySidebarOpen"
            :method="redundancySidebarMethod"
            :method-label="METHOD_LABELS[redundancySidebarMethod] || redundancySidebarMethod"
            :primary-slug="redundancySidebarMethod ? (form.payment_gateways[redundancySidebarMethod] || '') : ''"
            :gateways="gateways_by_method[redundancySidebarMethod] || []"
            :model-value="redundancySidebarMethod ? (form.payment_gateways[redundancySidebarMethod + '_redundancy'] || []) : []"
            @update:model-value="(val) => redundancySidebarMethod && (form.payment_gateways[redundancySidebarMethod + '_redundancy'] = val)"
            @save="(val) => { if (redundancySidebarMethod) { form.payment_gateways[redundancySidebarMethod + '_redundancy'] = val; } redundancySidebarOpen = false; }"
            @close="redundancySidebarOpen = false"
        />
    </div>

    <!-- Modal: API key (mostrar uma vez) -->
    <Teleport to="body">
        <div v-show="showKeyModal && revealedKey" class="fixed inset-0 z-[100000] flex items-center justify-center p-4" aria-modal="true" role="dialog">
            <div class="fixed inset-0 bg-zinc-900/60" aria-hidden="true" @click="closeKeyModal" />
            <div class="relative max-w-lg w-full rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between gap-2">
                    <h2 class="flex items-center gap-2 text-lg font-semibold text-zinc-900 dark:text-white">
                        <KeyRound class="h-5 w-5 text-amber-500" />
                        Sua API key
                    </h2>
                    <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:text-zinc-400 dark:hover:bg-zinc-800" aria-label="Fechar" @click="closeKeyModal">
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Copie agora. Esta key não será exibida novamente.
                </p>
                <div class="mt-4 flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800">
                    <code class="min-w-0 flex-1 truncate text-sm font-mono text-zinc-800 dark:text-zinc-200">{{ revealedKey }}</code>
                    <Button type="button" size="sm" variant="outline" class="shrink-0" @click="copyKey">
                        <Check v-if="copyKeyFeedback" class="h-4 w-4 text-emerald-600" />
                        <Copy v-else class="h-4 w-4" />
                        {{ copyKeyFeedback ? 'Copiado!' : 'Copiar' }}
                    </Button>
                </div>
                <Button class="mt-4 w-full" @click="closeKeyModal">Entendi</Button>
            </div>
        </div>
    </Teleport>
</template>
