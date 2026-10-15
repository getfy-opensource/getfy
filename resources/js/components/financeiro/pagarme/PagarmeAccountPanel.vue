<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { Landmark, Wallet, CalendarClock, RefreshCw, AlertTriangle } from 'lucide-vue-next';
import {
    formatCents,
    statusLabel,
    bankAccountLabel,
    transferSettingsLabel,
    centsFromInput,
} from '@/lib/pagarmeFinance.js';

const props = defineProps({
    account: { type: Object, required: true },
    // Base das ações (/financeiro/pagarme) para quem pode gerenciar. Vazio = só leitura.
    actionsBaseUrl: { type: String, default: '' },
    writeEnabled: { type: Boolean, default: false },
    sharedTenantsCount: { type: Number, default: 1 },
});

const canAct = computed(() => props.account.can_act && props.actionsBaseUrl !== '');
const balance = computed(() => props.account.balance);

const busy = ref(false);
const message = ref('');
const error = ref('');

function newKey() {
    return (globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`);
}

function fail(e) {
    const data = e?.response?.data;
    const first = data?.errors ? Object.values(data.errors)[0] : null;
    error.value = (Array.isArray(first) ? first[0] : first) || data?.message || 'Não foi possível concluir.';
}

async function run(fn, success) {
    busy.value = true;
    error.value = '';
    message.value = '';
    try {
        await fn();
        message.value = success;
        router.reload({ preserveScroll: true });
        return true;
    } catch (e) {
        fail(e);
        return false;
    } finally {
        busy.value = false;
    }
}

// Transferência
const transfer = ref({ amount: '', all: false, password: '', key: newKey() });
const transferCents = computed(() => (transfer.value.all ? balance.value?.available_cents ?? 0 : centsFromInput(transfer.value.amount)));

async function submitTransfer() {
    const cents = transferCents.value;
    if (!cents || cents <= 0) {
        error.value = 'Informe um valor válido.';
        return;
    }
    if (!window.confirm(`Transferir ${formatCents(cents)} para ${bankAccountLabel(props.account.bank_account)}?`)) {
        return;
    }
    const ok = await run(
        () => axios.post(`${props.actionsBaseUrl}/transfers`, {
            amount_cents: cents,
            idempotency_key: transfer.value.key,
            current_password: transfer.value.password,
        }),
        'Transferência solicitada. Acompanhe o status abaixo.',
    );
    if (ok) {
        transfer.value = { amount: '', all: false, password: '', key: newKey() };
    }
}

// Antecipação
const anticipation = ref({ timeframe: 'start', payment_date: '', amount: '', password: '', key: newKey() });
const limits = ref(null);
const simulation = ref(null);

async function loadLimits() {
    if (!anticipation.value.payment_date) {
        error.value = 'Escolha a data de pagamento (dia útil).';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`${props.actionsBaseUrl}/anticipations/limits`, {
            params: { timeframe: anticipation.value.timeframe, payment_date: anticipation.value.payment_date },
        });
        limits.value = data;
        simulation.value = null;
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

async function simulate() {
    const cents = centsFromInput(anticipation.value.amount);
    if (!cents) {
        error.value = 'Informe o valor a antecipar.';
        return;
    }
    busy.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(`${props.actionsBaseUrl}/anticipations/simulate`, {
            timeframe: anticipation.value.timeframe,
            payment_date: anticipation.value.payment_date,
            requested_amount_cents: cents,
        });
        simulation.value = data;
    } catch (e) {
        fail(e);
    } finally {
        busy.value = false;
    }
}

async function submitAnticipation() {
    const cents = centsFromInput(anticipation.value.amount);
    if (!window.confirm(`Antecipar ${formatCents(cents)} para ${anticipation.value.payment_date}? O custo é descontado do valor.`)) {
        return;
    }
    const ok = await run(
        () => axios.post(`${props.actionsBaseUrl}/anticipations`, {
            timeframe: anticipation.value.timeframe,
            payment_date: anticipation.value.payment_date,
            requested_amount_cents: cents,
            idempotency_key: anticipation.value.key,
            current_password: anticipation.value.password,
        }),
        'Antecipação solicitada.',
    );
    if (ok) {
        anticipation.value = { timeframe: 'start', payment_date: '', amount: '', password: '', key: newKey() };
        limits.value = null;
        simulation.value = null;
    }
}

const cancelPassword = ref('');
async function cancelAnticipation(item) {
    if (!cancelPassword.value) {
        error.value = 'Digite sua senha no campo "Senha para cancelar".';
        return;
    }
    if (!window.confirm('Cancelar esta antecipação pendente?')) {
        return;
    }
    await run(
        () => axios.post(`${props.actionsBaseUrl}/anticipations/${item.id}/cancel`, { current_password: cancelPassword.value }),
        'Antecipação cancelada.',
    );
}

// Transferência automática
const settings = ref({
    transfer_enabled: props.account.transfer_settings?.transfer_enabled ?? false,
    transfer_interval: props.account.transfer_settings?.transfer_interval || 'weekly',
    transfer_day: props.account.transfer_settings?.transfer_day ?? 1,
    password: '',
});

async function saveSettings() {
    await run(
        () => axios.patch(`${props.actionsBaseUrl}/transfer-settings`, {
            transfer_enabled: settings.value.transfer_enabled,
            transfer_interval: settings.value.transfer_interval,
            transfer_day: Number(settings.value.transfer_day) || 0,
            current_password: settings.value.password,
        }),
        'Configuração de transferência salva.',
    );
    settings.value.password = '';
}

async function syncNow() {
    await run(() => axios.post(`${props.actionsBaseUrl}/sync`), 'Sincronizado com a Pagar.me.');
}

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' });
}
function formatDate(date) {
    if (!date) return '—';
    const [y, m, d] = String(date).slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

const inputClass = 'mt-1 block w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white';
const panelClass = 'rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80';
</script>

<template>
    <div class="space-y-6">
        <div
            v-if="sharedTenantsCount > 1"
            class="rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/30 dark:text-sky-100"
        >
            Conta Pagar.me <strong>compartilhada</strong> por {{ sharedTenantsCount }} produtores: saldo, transferências e antecipações são da conta inteira.
            <span v-if="!canAct">Transferir e antecipar fica com quem gerencia o financeiro.</span>
        </div>

        <div v-if="account.error" class="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/25 dark:text-amber-100" role="alert">
            <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0" />
            <p>{{ account.error }}</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div :class="panelClass">
                <div class="flex items-center gap-2 text-xs font-medium text-zinc-500"><Wallet class="h-4 w-4" /> Saldo disponível</div>
                <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ balance ? formatCents(balance.available_cents) : '—' }}</p>
                <p class="mt-1 text-xs text-zinc-500">Pode ser transferido para o banco</p>
            </div>
            <div :class="panelClass">
                <div class="flex items-center gap-2 text-xs font-medium text-zinc-500"><CalendarClock class="h-4 w-4" /> A liberar</div>
                <p class="mt-2 text-2xl font-bold tabular-nums text-emerald-600 dark:text-emerald-400">{{ balance ? formatCents(balance.waiting_funds_cents) : '—' }}</p>
                <p class="mt-1 text-xs text-zinc-500">
                    Recebíveis futuros da conta<span v-if="account.outside_getfy?.waiting_cents"> · {{ formatCents(account.outside_getfy.waiting_cents) }} de vendas fora do Getfy</span>
                </p>
            </div>
            <div :class="panelClass">
                <div class="flex items-center gap-2 text-xs font-medium text-zinc-500"><Landmark class="h-4 w-4" /> Conta de destino</div>
                <p class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">{{ bankAccountLabel(account.bank_account) }}</p>
                <p class="mt-1 text-xs text-zinc-500">{{ transferSettingsLabel(account.transfer_settings) }}</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-2 text-xs text-zinc-500">
            <span>
                Última sincronização: {{ formatDateTime(account.last_synced_at) }}
                <span v-if="account.last_sync_error" class="text-amber-700 dark:text-amber-300"> · {{ account.last_sync_error }}</span>
            </span>
            <button v-if="canAct" type="button" class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 px-3 py-1.5 font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-200" :disabled="busy" @click="syncNow">
                <RefreshCw class="h-3.5 w-3.5" /> Sincronizar agora
            </button>
        </div>

        <p v-if="message" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200" role="status">{{ message }}</p>
        <p v-if="error" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-200" role="alert">{{ error }}</p>

        <template v-if="canAct">
            <p v-if="!writeEnabled" class="rounded-lg bg-zinc-100 px-3 py-2 text-sm text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                Ações desligadas. Ligue <code>GETFY_PAGARME_FINANCE_WRITE=true</code> para transferir e antecipar pelo Getfy.
            </p>
            <div v-else class="grid gap-4 lg:grid-cols-2">
                <form :class="panelClass" class="space-y-3" @submit.prevent="submitTransfer">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Transferir para o banco</h3>
                    <p class="text-xs text-zinc-500">Destino: {{ bankAccountLabel(account.bank_account) }}. A Pagar.me cobra uma taxa por transferência.</p>
                    <label class="block text-xs font-medium text-zinc-500">
                        Valor (R$)
                        <input v-model="transfer.amount" :disabled="transfer.all" inputmode="decimal" placeholder="0,00" :class="inputClass" />
                    </label>
                    <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-200">
                        <input v-model="transfer.all" type="checkbox" /> Todo o disponível ({{ formatCents(balance?.available_cents ?? 0) }})
                    </label>
                    <label class="block text-xs font-medium text-zinc-500">
                        Sua senha
                        <input v-model="transfer.password" type="password" autocomplete="current-password" required :class="inputClass" />
                    </label>
                    <button type="submit" class="w-full rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy">
                        Transferir {{ transferCents ? formatCents(transferCents) : '' }}
                    </button>
                </form>

                <form :class="panelClass" class="space-y-3" @submit.prevent="submitAnticipation">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Antecipar recebíveis</h3>
                    <div class="grid grid-cols-2 gap-3">
                        <label class="block text-xs font-medium text-zinc-500">
                            Recebíveis
                            <select v-model="anticipation.timeframe" :class="inputClass">
                                <option value="start">Mais próximos</option>
                                <option value="end">Mais distantes</option>
                            </select>
                        </label>
                        <label class="block text-xs font-medium text-zinc-500">
                            Pagamento em
                            <input v-model="anticipation.payment_date" type="date" :class="inputClass" @change="limits = null; simulation = null" />
                        </label>
                    </div>
                    <button type="button" class="text-xs font-medium text-[var(--color-primary)] underline disabled:opacity-50" :disabled="busy" @click="loadLimits">Ver limite disponível</button>
                    <p v-if="limits" class="text-xs text-zinc-600 dark:text-zinc-300">
                        Máximo: <strong>{{ formatCents(limits.max_cents) }}</strong><span v-if="limits.min_cents"> · mínimo {{ formatCents(limits.min_cents) }}</span>
                    </p>
                    <label class="block text-xs font-medium text-zinc-500">
                        Valor (R$)
                        <input v-model="anticipation.amount" inputmode="decimal" placeholder="0,00" :class="inputClass" @input="simulation = null" />
                    </label>
                    <button type="button" class="w-full rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-200" :disabled="busy || !anticipation.payment_date" @click="simulate">
                        Simular custo
                    </button>
                    <div v-if="simulation" class="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800/60">
                        Você recebe <strong>{{ formatCents(simulation.amount_cents - simulation.total_fee_cents) }}</strong>
                        · custo {{ formatCents(simulation.total_fee_cents) }}
                    </div>
                    <template v-if="simulation">
                        <label class="block text-xs font-medium text-zinc-500">
                            Sua senha
                            <input v-model="anticipation.password" type="password" autocomplete="current-password" required :class="inputClass" />
                        </label>
                        <button type="submit" class="w-full rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50" :disabled="busy">
                            Confirmar antecipação
                        </button>
                    </template>
                </form>

                <form :class="panelClass" class="space-y-3 lg:col-span-2" @submit.prevent="saveSettings">
                    <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Transferência automática</h3>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <label class="flex items-center gap-2 pt-6 text-sm text-zinc-700 dark:text-zinc-200">
                            <input v-model="settings.transfer_enabled" type="checkbox" /> Ligada
                        </label>
                        <label class="block text-xs font-medium text-zinc-500">
                            Frequência
                            <select v-model="settings.transfer_interval" :class="inputClass" :disabled="!settings.transfer_enabled">
                                <option value="daily">Diária</option>
                                <option value="weekly">Semanal</option>
                                <option value="monthly">Mensal</option>
                            </select>
                        </label>
                        <label class="block text-xs font-medium text-zinc-500">
                            {{ settings.transfer_interval === 'weekly' ? 'Dia da semana (1=seg … 5=sex)' : 'Dia do mês' }}
                            <input v-model="settings.transfer_day" type="number" min="0" max="31" :class="inputClass" :disabled="!settings.transfer_enabled || settings.transfer_interval === 'daily'" />
                        </label>
                        <label class="block text-xs font-medium text-zinc-500">
                            Sua senha
                            <input v-model="settings.password" type="password" autocomplete="current-password" required :class="inputClass" />
                        </label>
                    </div>
                    <button type="submit" class="rounded-lg border border-zinc-200 px-4 py-2 text-sm font-medium text-zinc-700 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-200" :disabled="busy">Salvar</button>
                </form>
            </div>
        </template>

        <section :class="panelClass" class="!p-0 overflow-hidden">
            <h3 class="border-b border-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-white">Transferências</h3>
            <p v-if="!account.transfers.length" class="px-4 py-6 text-sm text-zinc-500">Nenhuma transferência registrada.</p>
            <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <li v-for="t in account.transfers" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                    <span class="tabular-nums text-zinc-500">{{ formatDateTime(t.created_at) }}</span>
                    <span class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ formatCents(t.amount_cents) }}</span>
                    <span class="text-xs text-zinc-500">taxa {{ formatCents(t.fee_cents) }} · previsão {{ formatDate(t.funding_estimated_at) }}</span>
                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800" :title="t.error_message || ''">{{ statusLabel(t.status) }}</span>
                </li>
            </ul>
        </section>

        <section :class="panelClass" class="!p-0 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Antecipações</h3>
                <label v-if="canAct && writeEnabled && account.anticipations.some((a) => a.status === 'pending')" class="text-xs text-zinc-500">
                    Senha para cancelar
                    <input v-model="cancelPassword" type="password" autocomplete="current-password" class="ml-1 rounded border border-zinc-200 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-900" />
                </label>
            </div>
            <p v-if="!account.anticipations.length" class="px-4 py-6 text-sm text-zinc-500">Nenhuma antecipação registrada.</p>
            <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <li v-for="a in account.anticipations" :key="a.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                    <span class="tabular-nums text-zinc-500">{{ formatDate(a.payment_date) }}</span>
                    <span class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ formatCents(a.amount_cents ?? a.requested_amount_cents) }}</span>
                    <span class="text-xs text-zinc-500">custo {{ formatCents(a.fee_cents) }}</span>
                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800" :title="a.error_message || ''">{{ statusLabel(a.status) }}</span>
                    <button v-if="canAct && writeEnabled && a.status === 'pending'" type="button" class="text-xs font-medium text-red-600 underline" :disabled="busy" @click="cancelAnticipation(a)">Cancelar</button>
                </li>
            </ul>
        </section>

        <section :class="panelClass" class="!p-0 overflow-hidden">
            <h3 class="border-b border-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-white">Liquidações (pagamentos da Pagar.me)</h3>
            <p v-if="!account.settlements.length" class="px-4 py-6 text-sm text-zinc-500">Nenhuma liquidação nos últimos 35 dias.</p>
            <ul v-else class="divide-y divide-zinc-100 dark:divide-zinc-800">
                <li v-for="s in account.settlements" :key="s.id" class="flex flex-wrap items-center justify-between gap-2 px-4 py-2 text-sm">
                    <span class="tabular-nums text-zinc-500">{{ formatDate(s.payment_date) }}</span>
                    <span class="font-medium tabular-nums text-zinc-900 dark:text-white">{{ formatCents(s.amount_cents) }}</span>
                    <span class="text-xs text-zinc-500">{{ s.product || '—' }}<span v-if="s.card_brand"> · {{ s.card_brand }}</span></span>
                    <span class="rounded-full bg-zinc-100 px-2 py-0.5 text-xs dark:bg-zinc-800">{{ statusLabel(s.status) }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
