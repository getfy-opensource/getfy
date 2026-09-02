<script setup>
import { computed } from 'vue';
import { formatBRL, formatPercent } from '@/composables/useTrackingPanel';
import {
    CircleDollarSign, TrendingUp, Percent, BarChart3, Receipt, Wallet,
} from 'lucide-vue-next';
import TrackingAdSpendCard from './TrackingAdSpendCard.vue';

const props = defineProps({
    financial: { type: Object, default: () => ({}) },
    adSpend: { type: Object, default: () => ({}) },
    period: { type: String, default: 'hoje' },
    valuesVisible: { type: Boolean, default: true },
});

const emit = defineEmits(['save-daily', 'save-period', 'clear-period']);

const cards = computed(() => {
    const f = props.financial ?? {};
    const hide = (v) => (props.valuesVisible ? v : '••••••');
    const lucroOperacional = f.lucro_operacional ?? f.lucro_liquido;

    return [
        {
            key: 'faturamento',
            label: 'Faturamento',
            title: 'Valor bruto pago pelos clientes',
            value: hide(formatBRL(f.faturamento_bruto)),
            icon: CircleDollarSign,
            accent: 'from-emerald-500/10',
        },
        {
            key: 'receita_liquida',
            label: 'Receita líquida',
            title: 'Faturamento bruto menos taxas do gateway',
            value: hide(formatBRL(f.receita_liquida)),
            icon: Wallet,
            accent: 'from-teal-500/10',
        },
        {
            key: 'lucro',
            label: 'Lucro operacional',
            title: 'Receita líquida menos comissões, reembolsos e gasto com ads',
            value: hide(formatBRL(lucroOperacional)),
            icon: TrendingUp,
            accent: 'from-[var(--color-primary)]/10',
        },
        {
            key: 'roi',
            label: 'ROI',
            title: 'Retorno sobre investimento em ads',
            value: hide(formatPercent(f.roi)),
            icon: Percent,
            accent: 'from-violet-500/10',
        },
        {
            key: 'roas',
            label: 'ROAS',
            title: 'Retorno sobre gasto com ads',
            value: hide(f.roas != null ? `${f.roas}x` : '—'),
            icon: BarChart3,
            accent: 'from-blue-500/10',
        },
        {
            key: 'ticket',
            label: 'Ticket médio',
            title: 'Faturamento bruto dividido pelo número de vendas',
            value: hide(formatBRL(f.ticket_medio)),
            icon: Receipt,
            accent: 'from-amber-500/10',
        },
    ];
});
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-7">
        <div
            v-for="card in cards"
            :key="card.key"
            class="panel-card-md relative overflow-hidden transition-transform hover:scale-[1.01]"
            :title="card.title"
        >
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-br to-transparent opacity-80" :class="card.accent" />
            <div class="relative flex items-center gap-2 text-zinc-500 dark:text-zinc-400">
                <div class="dash-metric-icon-sm">
                    <component :is="card.icon" class="h-4 w-4" />
                </div>
                <span class="text-xs font-semibold uppercase tracking-wide">{{ card.label }}</span>
            </div>
            <p class="relative mt-3 text-xl font-bold text-zinc-900 dark:text-white sm:text-2xl">{{ card.value }}</p>
        </div>
        <TrackingAdSpendCard
            :amount="financial?.gasto_ads ?? 0"
            :ad-spend-meta="adSpend"
            :period="period"
            :values-visible="valuesVisible"
            class="xl:col-span-1"
            @save-daily="emit('save-daily', $event)"
            @save-period="emit('save-period', $event)"
            @clear-period="emit('clear-period')"
        />
    </div>
</template>
