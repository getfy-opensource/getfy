<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import FinanceiroDashboard from '@/components/financeiro/FinanceiroDashboard.vue';
import FinanceiroPartnerPayoutsTab from '@/components/financeiro/FinanceiroPartnerPayoutsTab.vue';
import FinanceiroCajupayGate from '@/components/financeiro/FinanceiroCajupayGate.vue';
import FinanceiroCajupayHint from '@/components/financeiro/FinanceiroCajupayHint.vue';
import BetaBadge from '@/components/ui/BetaBadge.vue';
import PluginSlotHost from '@/components/plugins/PluginSlotHost.vue';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    cajupay_connected: { type: Boolean, default: false },
    balances: { type: Object, default: () => ({ by_wallet: {}, totals: {} }) },
    wallet_labels: { type: Object, default: () => ({}) },
    transactions: { type: Array, default: () => [] },
    payouts: { type: Array, default: () => [] },
    pix_key: { type: String, default: '' },
    pix_key_type: { type: String, default: '' },
    pix_owner_document: { type: String, default: '' },
    min_payout_cents: { type: Number, default: 100 },
    summary: { type: Object, default: () => ({}) },
    partner_payouts: { type: Object, default: () => ({ items: [], summary: {} }) },
    plugin_financeiro_tabs: { type: Array, default: () => [] },
});

const activeTab = ref('wallet');

const page = usePage();
const canManageFinanceiro = computed(() => {
    const role = page.props.auth?.user?.role;
    if (role === 'admin' || role === 'infoprodutor') {
        return true;
    }
    return !!page.props.auth?.permissions?.['financeiro.manage'];
});

function onReload() {
    router.reload();
}
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="flex flex-wrap items-center gap-2 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Financeiro
                <BetaBadge />
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                Acompanhe seu saldo e movimentações das vendas na plataforma.
            </p>
            <FinanceiroCajupayHint v-if="cajupay_connected" variant="producer" class="mt-3" />
        </div>

        <div
            v-if="!cajupay_connected"
            class="flex items-start gap-3 rounded-xl border border-amber-200/80 bg-amber-50 px-4 py-3 text-sm text-amber-950 dark:border-amber-900/40 dark:bg-amber-950/25 dark:text-amber-100"
            role="status"
        >
            <span class="mt-0.5 shrink-0 font-semibold">Atenção</span>
            <p class="leading-relaxed">
                Saques e aprovações de parceiros dependem da
                <strong>CajuPay</strong> configurada. Conecte em Integrações para liberar o painel abaixo.
                Vende pela <strong>Pagar.me</strong>? Cadastre a chave financeira em Integrações → Gateways → Pagar.me.
            </p>
        </div>

        <FinanceiroCajupayGate :connected="cajupay_connected" variant="producer">
        <div class="space-y-6">
            <nav
                class="flex gap-1 rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/50"
                aria-label="Seções do financeiro"
            >
                <button
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition"
                    :class="activeTab === 'wallet'
                        ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white'
                        : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    @click="activeTab = 'wallet'"
                >
                    Minha carteira
                </button>
                <button
                    type="button"
                    class="relative rounded-lg px-4 py-2 text-sm font-medium transition"
                    :class="activeTab === 'partners'
                        ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white'
                        : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    @click="activeTab = 'partners'"
                >
                    Saques de parceiros
                    <span
                        v-if="partner_payouts.summary?.pending_count > 0"
                        class="ml-1.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-zinc-900"
                    >
                        {{ partner_payouts.summary.pending_count }}
                    </span>
                </button>
                <button
                    v-for="tab in plugin_financeiro_tabs"
                    :key="tab.id || tab.plugin_slug"
                    type="button"
                    class="rounded-lg px-4 py-2 text-sm font-medium transition"
                    :class="activeTab === `plugin-${tab.id}`
                        ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white'
                        : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    @click="activeTab = `plugin-${tab.id}`"
                >
                    {{ tab.label || tab.id }}
                </button>
            </nav>

            <div v-show="activeTab === 'wallet'" class="min-h-0">
        <FinanceiroDashboard
            :balances="balances"
            :wallet-labels="wallet_labels"
            :transactions="transactions"
            :commissions="[]"
            :payouts="payouts"
            :pix-key="pix_key"
            :pix-key-type="pix_key_type"
            :pix-owner-document="pix_owner_document"
            :min-payout-cents="min_payout_cents"
            :cajupay-connected="cajupay_connected"
            payout-base-url="/financeiro/payout"
            pix-base-url="/financeiro/pix"
            :partner-summary="summary"
            :show-commissions-table="false"
            :can-manage="canManageFinanceiro"
            @reload="onReload"
        />
            </div>

            <div v-show="activeTab === 'partners'" class="min-h-0">
        <FinanceiroPartnerPayoutsTab
            :partner-payouts="partner_payouts"
            :can-manage="canManageFinanceiro"
        />
            </div>

            <div
                v-for="tab in plugin_financeiro_tabs"
                :key="`panel-${tab.id}`"
                v-show="activeTab === `plugin-${tab.id}`"
                class="min-h-0"
            >
                <PluginSlotHost
                    layout="stack"
                    :items="[tab]"
                    :context="{ tab }"
                />
            </div>
        </div>
        </FinanceiroCajupayGate>
    </div>
</template>
