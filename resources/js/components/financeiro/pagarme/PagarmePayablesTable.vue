<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import { formatCents, methodLabel, statusLabel } from '@/lib/pagarmeFinance.js';

const props = defineProps({
    payables: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    period: { type: Object, default: () => ({}) },
});

const form = ref({
    status: props.filters.status || '',
    method: props.filters.method || '',
    from: props.filters.from || '',
    to: props.filters.to || '',
});

function apply() {
    const query = { tab: 'recebiveis', period_from: props.period.from, period_to: props.period.to };
    Object.entries(form.value).forEach(([k, v]) => {
        if (v) query[k] = v;
    });
    router.get('/financeiro', query, { preserveState: true, preserveScroll: true, only: ['payables', 'filters'] });
}

function formatDate(date) {
    if (!date) return '—';
    const [y, m, d] = date.split('-');
    return `${d}/${m}/${y}`;
}
</script>

<template>
    <div class="space-y-4">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="apply">
            <label class="text-xs font-medium text-zinc-500">
                Status
                <select v-model="form.status" class="mt-1 block rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    <option value="">Todos</option>
                    <option value="waiting_funds">A receber</option>
                    <option value="paid">Recebido</option>
                </select>
            </label>
            <label class="text-xs font-medium text-zinc-500">
                Meio
                <select v-model="form.method" class="mt-1 block rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white">
                    <option value="">Todos</option>
                    <option value="credit_card">Cartão</option>
                    <option value="pix">Pix</option>
                    <option value="boleto">Boleto</option>
                </select>
            </label>
            <label class="text-xs font-medium text-zinc-500">
                Pagamento de
                <input v-model="form.from" type="date" class="mt-1 block rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </label>
            <label class="text-xs font-medium text-zinc-500">
                até
                <input v-model="form.to" type="date" class="mt-1 block rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </label>
            <button type="submit" class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90">Filtrar</button>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80">
            <p v-if="!payables.data.length" class="px-4 py-8 text-center text-sm text-zinc-500">Nenhum recebível encontrado.</p>
            <table v-else class="w-full min-w-[760px] text-sm">
                <thead class="text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Pagamento</th>
                        <th class="px-4 py-2 font-medium">Venda</th>
                        <th class="px-4 py-2 font-medium">Meio</th>
                        <th class="px-4 py-2 font-medium">Parcela</th>
                        <th class="px-4 py-2 text-right font-medium">Bruto</th>
                        <th class="px-4 py-2 text-right font-medium">Taxa</th>
                        <th class="px-4 py-2 text-right font-medium">Líquido</th>
                        <th class="px-4 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="p in payables.data" :key="p.id">
                        <td class="px-4 py-2 tabular-nums">{{ formatDate(p.payment_date) }}</td>
                        <td class="px-4 py-2">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ p.customer || '—' }}</div>
                            <div class="text-xs text-zinc-500">
                                {{ p.product || 'Pedido' }}
                                <span v-if="p.order_id"> · #{{ p.order_id }}</span>
                                <span v-if="p.type !== 'credit'" class="ml-1 rounded bg-red-100 px-1.5 text-[10px] font-semibold text-red-700 dark:bg-red-950 dark:text-red-300">{{ p.type }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-2">{{ methodLabel(p.payment_method) }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ p.installment || '—' }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ formatCents(p.amount_cents) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-red-600 dark:text-red-400">
                            {{ formatCents(p.fee_cents + p.anticipation_fee_cents) }}
                        </td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ formatCents(p.net_cents) }}</td>
                        <td class="px-4 py-2">
                            <span
                                class="rounded-full px-2 py-0.5 text-xs font-medium"
                                :class="p.status === 'paid' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'"
                            >
                                {{ statusLabel(p.status) }}
                            </span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav v-if="payables.last_page > 1" class="flex flex-wrap items-center gap-1" aria-label="Paginação dos recebíveis">
            <template v-for="link in payables.links" :key="link.label">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    preserve-state
                    :only="['payables', 'filters']"
                    class="rounded-md px-3 py-1 text-sm"
                    :class="link.active ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800'"
                    v-html="link.label"
                />
                <span v-else class="px-3 py-1 text-sm text-zinc-400" v-html="link.label" />
            </template>
        </nav>
    </div>
</template>
