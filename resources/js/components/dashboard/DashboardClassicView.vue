<script setup>
import { ref, computed, onMounted } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import DashboardPeriodFilter from '@/components/dashboard/DashboardPeriodFilter.vue';
import TrackingTabLauncher from '@/components/dashboard/tracking/TrackingTabLauncher.vue';
import { CircleDollarSign, ShoppingCart, CreditCard, ShoppingBag, RotateCcw, Package, Eye, EyeOff } from 'lucide-vue-next';

const page = usePage();
const hasAchievementsProgress = computed(() => !!(page.props.achievementsProgress ?? null));

const valuesVisible = defineModel('valuesVisible', { type: Boolean, default: true });

const isDarkMode = ref(false);

onMounted(() => {
    isDarkMode.value = document.documentElement.classList.contains('dark');
});

const props = defineProps({
    period: { type: String, default: 'hoje' },
    vendas_totais: { type: Number, default: 0 },
    vendas_totais_por_moeda: { type: Array, default: () => [] },
    lucro_liquido: { type: Number, default: 0 },
    lucro_liquido_por_moeda: { type: Array, default: () => [] },
    vendas_pendentes: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    taxa_conversao: { type: Number, default: 0 },
    abandono_carrinho: { type: Number, default: 0 },
    reembolsos_count: { type: Number, default: 0 },
    reembolsos_total: { type: Number, default: 0 },
    quantidade_produtos: { type: Number, default: 0 },
    grafico_vendas: { type: Array, default: () => [] },
});

const emit = defineEmits(['update:period', 'open-tracking']);

function setPeriod(value) {
    emit('update:period', value);
    router.get('/dashboard', { period: value }, { preserveState: false });
}

function formatMoney(value, currency = 'BRL') {
    const code = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
    const locale = code === 'BRL' ? 'pt-BR' : code === 'EUR' ? 'de-DE' : 'en-US';
    return new Intl.NumberFormat(locale, { style: 'currency', currency: code }).format(value ?? 0);
}

function formatBRL(value) {
    return formatMoney(value, 'BRL');
}

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function displayMoney(value, currency = 'BRL') {
    return valuesVisible.value ? formatMoney(value, currency) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
}

const chartSeries = computed(() => [
    {
        name: 'Vendas',
        data: valuesVisible.value
            ? props.grafico_vendas.map((d) => d.total)
            : props.grafico_vendas.map(() => 0),
    },
]);

const chartPrimaryColor = computed(() => {
    const fromSettings = page.props.appSettings?.theme_primary;
    if (typeof fromSettings === 'string' && fromSettings.trim() !== '') {
        return fromSettings.trim();
    }
    if (typeof document !== 'undefined') {
        const css = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim();
        if (css) {
            return css;
        }
    }
    return '#0ea5e9';
});

const chartOptions = computed(() => {
    const primary = chartPrimaryColor.value;

    return {
        chart: {
            type: 'area',
            toolbar: { show: false },
            zoom: { enabled: false },
            fontFamily: 'inherit',
            animations: { enabled: true, speed: 600 },
            dropShadow: {
                enabled: true,
                top: 4,
                left: 0,
                blur: 10,
                color: primary,
                opacity: 0.35,
            },
        },
        colors: [primary],
        dataLabels: {
            enabled: true,
            formatter: (v) => (valuesVisible.value && v > 0 ? formatBRL(v) : ''),
            style: { fontSize: '11px', colors: [primary] },
            offsetY: -4,
        },
        stroke: { curve: 'smooth', width: 3 },
        fill: {
            type: 'gradient',
            gradient: { shadeIntensity: 1, opacityFrom: 0.6, opacityTo: 0.0, stops: [0, 100] },
        },
        markers: { size: 4, strokeWidth: 2, hover: { size: 6 } },
        xaxis: {
            categories: (props.period === 'hoje' || props.period === 'ontem')
                ? props.grafico_vendas.map((d) => `${Number(d.data)}h`)
                : props.grafico_vendas.map((d) => {
                    const [, m, day] = (d.data || '').split('-');
                    return day && m ? `${day}/${m}` : d.data;
                }),
            labels: { style: { colors: '#71717a', fontSize: '12px' } },
            axisBorder: { show: true },
            crosshairs: { show: true },
        },
        yaxis: {
            labels: {
                style: { colors: '#71717a', fontSize: '12px' },
                formatter: (v) => formatBRL(v),
            },
        },
        grid: {
            borderColor: isDarkMode.value ? '#27272a' : '#e4e4e7',
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
            padding: { top: 20, right: 10, bottom: 0, left: 0 },
        },
        tooltip: {
            theme: isDarkMode.value ? 'dark' : 'light',
            shared: true,
            intersect: false,
            y: { formatter: (v) => (valuesVisible.value ? formatBRL(v) : '••••••') },
        },
    };
});
</script>

<template>
    <div class="space-y-6">
        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <div class="w-full">
            <div class="flex w-full flex-wrap items-end justify-between gap-x-3 gap-y-2">
                <DashboardPeriodFilter class="min-w-0 flex-1" :model-value="period" @update:model-value="setPeriod">
                    <template #trailing>
                        <button
                            type="button"
                            :aria-label="valuesVisible ? 'Ocultar valores' : 'Mostrar valores'"
                            class="flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl border border-zinc-200/80 bg-zinc-100/90 text-zinc-500 transition-colors hover:text-zinc-800 dark:border-zinc-700/80 dark:bg-zinc-800/50 dark:text-zinc-400 dark:hover:text-zinc-200"
                            @click="valuesVisible = !valuesVisible"
                        >
                            <Eye v-if="valuesVisible" class="h-5 w-5" aria-hidden="true" />
                            <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
                        </button>
                        <TrackingTabLauncher @click="emit('open-tracking')" />
                    </template>
                </DashboardPeriodFilter>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div class="panel-card-md">
                <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-400">
                    <div class="dash-metric-icon-md" aria-hidden="true">
                        <CircleDollarSign class="h-5 w-5" />
                    </div>
                    <span class="text-sm font-medium dark:text-zinc-300">Vendas totais</span>
                </div>
                <div v-if="(vendas_totais_por_moeda ?? []).length" class="mt-3 space-y-1">
                    <p
                        v-for="row in vendas_totais_por_moeda"
                        :key="row.currency"
                        class="text-xl font-bold text-zinc-900 dark:text-white sm:text-2xl"
                    >
                        {{ displayMoney(row.total, row.currency) }}
                    </p>
                </div>
                <p v-else class="mt-3 text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ displayMoney(0, 'BRL') }}
                </p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Vendas pendentes: {{ displayCurrency(vendas_pendentes) }}
                </p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-400">
                    <div class="dash-metric-icon-md" aria-hidden="true">
                        <CircleDollarSign class="h-5 w-5" />
                    </div>
                    <span class="text-sm font-medium dark:text-zinc-300">Lucro líquido</span>
                </div>
                <div v-if="(lucro_liquido_por_moeda ?? []).length" class="mt-3 space-y-1">
                    <p
                        v-for="row in lucro_liquido_por_moeda"
                        :key="row.currency"
                        class="text-xl font-bold text-emerald-600 dark:text-emerald-400 sm:text-2xl"
                    >
                        {{ displayMoney(row.total, row.currency) }}
                    </p>
                </div>
                <p v-else class="mt-3 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                    {{ displayMoney(0, 'BRL') }}
                </p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Após taxas e comissões
                </p>
            </div>
            <div class="panel-card-md">
                <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-400">
                    <div class="dash-metric-icon-md" aria-hidden="true">
                        <ShoppingCart class="h-5 w-5" />
                    </div>
                    <span class="text-sm font-medium dark:text-zinc-300">Quantidade de vendas</span>
                </div>
                <p class="mt-3 text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ displayNumber(quantidade_vendas) }}
                </p>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Ticket médio: {{ displayCurrency(ticket_medio) }}
                </p>
            </div>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <div class="panel-card-md lg:col-span-2 flex flex-col">
                <h2 class="flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">
                    <div class="dash-metric-icon-sm" aria-hidden="true">
                        <CreditCard class="h-4 w-4" />
                    </div>
                    Formas de pagamento
                </h2>
                <div class="mt-4 flex flex-1 flex-col md:flex-row md:items-center">
                    <div class="flex-1">
                        <ul class="space-y-3">
                            <li
                                v-for="fp in formas_pagamento"
                                :key="fp.metodo"
                                class="flex items-center justify-between border-b border-zinc-200/60 py-2 last:border-0 dark:border-zinc-700/60"
                            >
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ fp.label }}</span>
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ displayCurrency(fp.total) }}
                                    <span class="font-normal text-zinc-500">({{ displayNumber(fp.quantidade) }})</span>
                                </span>
                            </li>
                            <li v-if="!formas_pagamento.length" class="py-4 text-center text-sm text-zinc-500">
                                Nenhum pagamento no período
                            </li>
                        </ul>
                    </div>
                    <div class="mt-6 flex flex-col items-center justify-center border-t border-zinc-200 pt-6 dark:border-zinc-700/60 md:ml-8 md:mt-0 md:border-l md:border-t-0 md:pl-8 md:pt-0">
                        <div class="relative flex h-32 w-32 items-center justify-center">
                            <svg class="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                                <circle cx="50" cy="50" r="40" fill="transparent" stroke="currentColor" stroke-width="12" class="text-zinc-200 dark:text-zinc-800" />
                                <circle
                                    cx="50" cy="50" r="40" fill="transparent" stroke="currentColor" stroke-width="12"
                                    stroke-linecap="round" class="text-[var(--color-primary)]"
                                    :stroke-dasharray="251.2"
                                    :stroke-dashoffset="251.2 - (251.2 * taxa_conversao) / 100"
                                    style="transition: stroke-dashoffset 0.8s ease-out;"
                                />
                            </svg>
                            <div class="absolute flex flex-col items-center text-center">
                                <span class="text-2xl font-bold text-zinc-900 dark:text-white">
                                    {{ valuesVisible ? `${taxa_conversao}%` : '—' }}
                                </span>
                            </div>
                        </div>
                        <p class="mt-3 text-sm font-medium text-zinc-500 dark:text-zinc-400">Taxa de conversão geral</p>
                    </div>
                </div>
            </div>
            <div class="space-y-4">
                <div class="panel-card-sm">
                    <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-400">
                        <div class="dash-metric-icon-sm"><ShoppingBag class="h-4 w-4" /></div>
                        <span class="text-sm font-medium dark:text-zinc-300">Abandono de carrinho</span>
                    </div>
                    <p class="mt-3 text-xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(abandono_carrinho) }}</p>
                </div>
                <div class="panel-card-sm">
                    <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-400">
                        <div class="dash-metric-icon-sm"><RotateCcw class="h-4 w-4" /></div>
                        <span class="text-sm font-medium dark:text-zinc-300">Reembolso</span>
                    </div>
                    <div class="mt-3 flex items-baseline gap-2">
                        <p class="text-xl font-bold text-zinc-900 dark:text-white">{{ displayCurrency(reembolsos_total) }}</p>
                        <p class="text-xs text-zinc-500">{{ displayNumber(reembolsos_count) }} pedido(s)</p>
                    </div>
                </div>
                <div class="panel-card-sm">
                    <div class="flex items-center gap-3 text-zinc-600 dark:text-zinc-400">
                        <div class="dash-metric-icon-sm"><Package class="h-4 w-4" /></div>
                        <span class="text-sm font-medium dark:text-zinc-300">Produtos</span>
                    </div>
                    <p class="mt-3 text-xl font-bold text-zinc-900 dark:text-white">{{ displayNumber(quantidade_produtos) }}</p>
                </div>
            </div>
        </div>

        <div class="panel-card-md">
            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Desempenho de vendas</h2>
            <div class="mt-4 min-h-[280px]">
                <VueApexCharts
                    v-if="grafico_vendas.length"
                    :key="chartPrimaryColor"
                    type="area"
                    height="280"
                    :options="chartOptions"
                    :series="chartSeries"
                />
                <p v-else class="flex h-[280px] items-center justify-center text-sm text-zinc-500 dark:text-zinc-400">
                    Nenhum dado de vendas no período
                </p>
            </div>
        </div>
    </div>
</template>
