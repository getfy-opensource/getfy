<script setup>
import { ref } from 'vue';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import BetaBadge from '@/components/ui/BetaBadge.vue';
import PluginSlotHost from '@/components/plugins/PluginSlotHost.vue';
import PagarmeOverview from '@/components/financeiro/pagarme/PagarmeOverview.vue';
import PagarmePayablesTable from '@/components/financeiro/pagarme/PagarmePayablesTable.vue';
import PagarmeAccountPanel from '@/components/financeiro/pagarme/PagarmeAccountPanel.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    period: { type: Object, required: true },
    overview: { type: Object, required: true },
    by_method: { type: Array, default: () => [] },
    schedule: { type: Object, default: () => ({ weeks: [], months: [] }) },
    payables: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    account: { type: Object, required: true },
    shared_tenants_count: { type: Number, default: 1 },
    can_manage: { type: Boolean, default: false },
    write_enabled: { type: Boolean, default: false },
    plugin_financeiro_tabs: { type: Array, default: () => [] },
});

const TABS = [
    { id: 'visao-geral', label: 'Visão geral' },
    { id: 'recebiveis', label: 'Recebíveis' },
    { id: 'conta', label: 'Conta Pagar.me' },
];

const initialTab = new URLSearchParams(globalThis.location?.search ?? '').get('tab');
const hasPayableFilters = Object.values(props.filters).some(Boolean);
const activeTab = ref(
    TABS.some((t) => t.id === initialTab) ? initialTab : hasPayableFilters ? 'recebiveis' : 'visao-geral',
);

const tabClass = (id) =>
    activeTab.value === id
        ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white'
        : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300';
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex flex-wrap items-center gap-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    Financeiro
                    <BetaBadge />
                </h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Vendas, taxas reais e recebíveis da Pagar.me ({{ account.label }}).
                </p>
            </div>
            <a
                href="/integracoes?tab=gateways&gateway=pagarme"
                class="rounded-lg border border-zinc-200 px-3 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-zinc-800"
            >
                Chave da Pagar.me
            </a>
        </div>

        <nav
            class="flex flex-wrap gap-1 rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/50"
            aria-label="Seções do financeiro"
        >
            <button
                v-for="tab in TABS"
                :key="tab.id"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium transition"
                :class="tabClass(tab.id)"
                :aria-current="activeTab === tab.id ? 'page' : undefined"
                @click="activeTab = tab.id"
            >
                {{ tab.label }}
            </button>
            <button
                v-for="tab in plugin_financeiro_tabs"
                :key="tab.id || tab.plugin_slug"
                type="button"
                class="rounded-lg px-4 py-2 text-sm font-medium transition"
                :class="tabClass(`plugin-${tab.id}`)"
                @click="activeTab = `plugin-${tab.id}`"
            >
                {{ tab.label || tab.id }}
            </button>
        </nav>

        <PagarmeOverview
            v-show="activeTab === 'visao-geral'"
            :period="period"
            :overview="overview"
            :by-method="by_method"
            :schedule="schedule"
        />

        <PagarmePayablesTable
            v-show="activeTab === 'recebiveis'"
            :payables="payables"
            :filters="filters"
            :period="period"
        />

        <PagarmeAccountPanel
            v-show="activeTab === 'conta'"
            :account="account"
            :actions-base-url="can_manage ? '/financeiro/pagarme' : ''"
            :write-enabled="write_enabled"
            :shared-tenants-count="shared_tenants_count"
        />

        <div
            v-for="tab in plugin_financeiro_tabs"
            :key="`panel-${tab.id}`"
            v-show="activeTab === `plugin-${tab.id}`"
        >
            <PluginSlotHost layout="stack" :items="[tab]" :context="{ tab }" />
        </div>
    </div>
</template>
