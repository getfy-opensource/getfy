<script setup>
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { TrendingUp, Receipt, Wallet, CalendarClock, CheckCircle2, Undo2 } from 'lucide-vue-next';
import { formatCents, methodLabel, scheduleBars } from '@/lib/pagarmeFinance.js';

const props = defineProps({
    period: { type: Object, required: true },
    overview: { type: Object, required: true },
    byMethod: { type: Array, default: () => [] },
    schedule: { type: Object, default: () => ({ weeks: [], months: [] }) },
});

const from = ref(props.period.from);
const to = ref(props.period.to);
const scheduleMode = ref('week');

const bars = computed(() =>
    scheduleMode.value === 'month'
        ? scheduleBars(props.schedule.months, 'month')
        : scheduleBars(props.schedule.weeks, 'week'),
);
const scheduleTotal = computed(() => bars.value.reduce((sum, b) => sum + b.cents, 0));

const cards = computed(() => [
    { key: 'gross', label: 'Vendido (bruto)', value: props.overview.gross_cents, hint: `${props.overview.sales_count} vendas no período`, icon: TrendingUp },
    { key: 'fee', label: 'Taxas Pagar.me', value: props.overview.fee_cents, hint: props.overview.anticipation_fee_cents ? `+ ${formatCents(props.overview.anticipation_fee_cents)} de antecipação` : 'Taxa real dos recebíveis', icon: Receipt },
    { key: 'net', label: 'Líquido', value: props.overview.net_cents, hint: 'Bruto menos taxas', icon: Wallet },
    { key: 'waiting', label: 'A receber', value: props.overview.waiting_cents, hint: 'Todos os recebíveis futuros', icon: CalendarClock },
    { key: 'received', label: 'Recebido', value: props.overview.received_cents, hint: 'Pago pela Pagar.me no período', icon: CheckCircle2 },
    { key: 'reversals', label: 'Estornos e chargebacks', value: props.overview.reversals_cents, hint: 'Descontados dos recebíveis', icon: Undo2 },
]);

function applyPeriod() {
    router.get('/financeiro', { period_from: from.value, period_to: to.value }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <form class="flex flex-wrap items-end gap-3" @submit.prevent="applyPeriod">
            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                De
                <input v-model="from" type="date" class="mt-1 block rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </label>
            <label class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                Até
                <input v-model="to" type="date" class="mt-1 block rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white" />
            </label>
            <button type="submit" class="rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-semibold text-white hover:opacity-90">
                Aplicar
            </button>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Vendas pela data da venda; recebido pela data de pagamento.</p>
        </form>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <div
                v-for="card in cards"
                :key="card.key"
                class="rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80"
            >
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ card.label }}</p>
                        <p class="mt-2 text-2xl font-bold tracking-tight text-zinc-900 tabular-nums dark:text-white">{{ formatCents(card.value) }}</p>
                        <p class="mt-1 text-xs text-zinc-500">{{ card.hint }}</p>
                    </div>
                    <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                        <component :is="card.icon" class="h-5 w-5" />
                    </div>
                </div>
            </div>
        </div>

        <section class="rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Agenda de recebíveis</h2>
                    <p class="text-xs text-zinc-500">Líquido a receber por {{ scheduleMode === 'month' ? 'mês' : 'semana' }} · {{ formatCents(scheduleTotal) }} nos próximos 12 períodos</p>
                </div>
                <div class="flex gap-1 rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-700" role="group" aria-label="Agrupar agenda">
                    <button
                        v-for="mode in [{ k: 'week', l: 'Semanas' }, { k: 'month', l: 'Meses' }]"
                        :key="mode.k"
                        type="button"
                        class="rounded-md px-3 py-1 text-xs font-medium"
                        :class="scheduleMode === mode.k ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-500'"
                        :aria-pressed="scheduleMode === mode.k"
                        @click="scheduleMode = mode.k"
                    >
                        {{ mode.l }}
                    </button>
                </div>
            </div>
            <div class="mt-4 flex h-44 items-end gap-1.5" role="img" :aria-label="`Agenda de recebíveis, total ${formatCents(scheduleTotal)}`">
                <div v-for="bar in bars" :key="bar.key" class="group flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
                    <span class="invisible whitespace-nowrap text-[10px] font-medium text-zinc-600 tabular-nums group-hover:visible dark:text-zinc-300">
                        {{ formatCents(bar.cents) }}
                    </span>
                    <div
                        class="w-full rounded-t bg-[var(--color-primary)]"
                        :class="bar.cents > 0 ? 'opacity-90 group-hover:opacity-100' : 'opacity-20'"
                        :style="{ height: `${Math.max(bar.pct, bar.cents > 0 ? 3 : 1)}%` }"
                        :title="`${bar.label}: ${formatCents(bar.cents)}`"
                    />
                    <span class="text-[10px] text-zinc-500 tabular-nums">{{ bar.label }}</span>
                </div>
            </div>
        </section>

        <section class="overflow-hidden rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900/80">
            <h2 class="border-b border-zinc-100 px-4 py-3 text-sm font-semibold text-zinc-900 dark:border-zinc-800 dark:text-white">Por meio de pagamento</h2>
            <p v-if="!byMethod.length" class="px-4 py-6 text-sm text-zinc-500">Nenhuma venda Pagar.me no período.</p>
            <table v-else class="w-full text-sm">
                <thead class="text-left text-xs text-zinc-500">
                    <tr>
                        <th class="px-4 py-2 font-medium">Meio</th>
                        <th class="px-4 py-2 font-medium">Vendas</th>
                        <th class="px-4 py-2 text-right font-medium">Bruto</th>
                        <th class="px-4 py-2 text-right font-medium">Taxas</th>
                        <th class="px-4 py-2 text-right font-medium">Líquido</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    <tr v-for="row in byMethod" :key="row.method">
                        <td class="px-4 py-2 text-zinc-900 dark:text-white">{{ methodLabel(row.method) }}</td>
                        <td class="px-4 py-2 tabular-nums">{{ row.sales_count }}</td>
                        <td class="px-4 py-2 text-right tabular-nums">{{ formatCents(row.gross_cents) }}</td>
                        <td class="px-4 py-2 text-right tabular-nums text-red-600 dark:text-red-400">{{ formatCents(row.fee_cents) }}</td>
                        <td class="px-4 py-2 text-right font-medium tabular-nums">{{ formatCents(row.net_cents) }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
