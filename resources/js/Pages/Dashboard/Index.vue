<script setup>
import { ref, watch, computed } from 'vue';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import DashboardClassicView from '@/components/dashboard/DashboardClassicView.vue';
import TrackingPanel from '@/components/dashboard/tracking/TrackingPanel.vue';
import { useTrackingPanel } from '@/composables/useTrackingPanel';
import PluginSlotHost from '@/components/plugins/PluginSlotHost.vue';

defineOptions({ layout: LayoutInfoprodutor });

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
    plugin_dashboard_widgets: { type: Array, default: () => [] },
});

const valuesVisible = ref(true);

const dashboardLinkWidgets = computed(() =>
    (props.plugin_dashboard_widgets || []).filter((w) => w.route && w.ui_mode !== 'runtime'),
);
const dashboardRuntimeWidgets = computed(() =>
    (props.plugin_dashboard_widgets || []).filter((w) => w.ui_mode === 'runtime' && w.ui_export),
);
const {
    isOpen,
    loading,
    error,
    data,
    period: trackingPeriod,
    open,
    close,
    setPeriod,
    fetchData,
    saveDailyAdSpend,
    savePeriodAdSpend,
    clearPeriodAdSpend,
} = useTrackingPanel();

watch(
    () => props.period,
    (p) => {
        if (!isOpen.value) {
            trackingPeriod.value = p;
        }
    },
    { immediate: true }
);

function openTracking() {
    trackingPeriod.value = props.period;
    open();
}

async function handleSaveDaily({ date, amount }) {
    await saveDailyAdSpend(date, amount);
}

async function handleSavePeriod(amount) {
    await savePeriodAdSpend(amount);
}
</script>

<template>
    <div class="relative w-full min-w-0 max-w-full overflow-x-hidden">
        <Transition
            enter-active-class="transition-opacity duration-300 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="pointer-events-none absolute inset-0 z-0 w-full transition-opacity duration-200 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <TrackingPanel
                v-if="isOpen"
                key="tracking"
                class="relative z-10 w-full min-w-0 max-w-full"
                :data="data"
                :loading="loading"
                :error="error"
                :period="trackingPeriod"
                :values-visible="valuesVisible"
                @close="close()"
                @update:period="setPeriod"
                @save-daily="handleSaveDaily"
                @save-period="handleSavePeriod"
                @clear-period="clearPeriodAdSpend()"
                @retry="fetchData()"
            />
            <div v-else key="classic" class="w-full min-w-0 max-w-full space-y-6">
                <DashboardClassicView
                    v-model:values-visible="valuesVisible"
                    v-bind="props"
                    @open-tracking="openTracking"
                />
                <div
                    v-if="dashboardLinkWidgets.length || dashboardRuntimeWidgets.length"
                    class="space-y-4"
                >
                    <div
                        v-if="dashboardLinkWidgets.length"
                        class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3"
                    >
                        <a
                            v-for="w in dashboardLinkWidgets"
                            :key="w.id"
                            :href="w.route"
                            class="panel-card block p-4 text-sm font-medium text-zinc-800 dark:text-zinc-100"
                        >
                            {{ w.label || w.id }}
                        </a>
                    </div>
                    <PluginSlotHost
                        v-if="dashboardRuntimeWidgets.length"
                        layout="grid"
                        :items="dashboardRuntimeWidgets"
                    />
                </div>
            </div>
        </Transition>
    </div>
</template>
