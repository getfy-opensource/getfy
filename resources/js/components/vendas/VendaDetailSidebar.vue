<script setup>
import { ref, computed } from 'vue';
import { X, ExternalLink } from 'lucide-vue-next';
import PluginSlotHost from '@/components/plugins/PluginSlotHost.vue';
import PluginRenderZone from '@/components/plugins/PluginRenderZone.vue';
import HorizontalScrollTabs from '@/components/ui/HorizontalScrollTabs.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    venda: { type: Object, default: null },
    plugin_order_detail_panels: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const activeTab = ref('venda');

function checkoutSessionFromVenda(v) {
    if (!v) return null;
    return v.checkout_session ?? v.checkoutSession ?? null;
}

function metadataFromVenda(v) {
    if (!v || v.metadata == null) return null;
    return typeof v.metadata === 'object' ? v.metadata : null;
}

const utmSource = computed(() => {
    const v = props.venda;
    if (!v) return '';
    const cs = checkoutSessionFromVenda(v);
    const meta = metadataFromVenda(v);
    return (cs?.utm_source || meta?.utm_source || '').trim();
});
const utmCampaign = computed(() => {
    const v = props.venda;
    if (!v) return '';
    const cs = checkoutSessionFromVenda(v);
    const meta = metadataFromVenda(v);
    return (cs?.utm_campaign || meta?.utm_campaign || '').trim();
});
const utmMedium = computed(() => {
    const v = props.venda;
    if (!v) return '';
    const cs = checkoutSessionFromVenda(v);
    const meta = metadataFromVenda(v);
    return (cs?.utm_medium || meta?.utm_medium || '').trim();
});

function close() {
    emit('close');
}

function formatMoney(value, currency = 'BRL') {
    const code = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
    const locale = code === 'BRL' ? 'pt-BR' : code === 'EUR' ? 'de-DE' : 'en-US';
    return new Intl.NumberFormat(locale, { style: 'currency', currency: code }).format(value ?? 0);
}

function formatBRL(value) {
    return formatMoney(value, 'BRL');
}

function vendaDisplayAmount(v) {
    if (v?.display_amount_is_producer_share && v.display_amount != null) {
        return v.display_amount;
    }
    return v?.amount_total ?? v?.amount ?? 0;
}

function vendaGrossAmount(v) {
    return v?.gross_amount ?? v?.amount_total ?? v?.amount ?? 0;
}

function formatDate(value) {
    if (!value) return '–';
    const d = new Date(value);
    return d.toLocaleDateString('pt-BR', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function statusLabel(status) {
    const map = {
        completed: 'Pago',
        pending: 'Pendente',
        disputed: 'MED',
        cancelled: 'Cancelado',
        refunded: 'Reembolsado',
    };
    return map[status] ?? status ?? '–';
}

function itemLabel(item) {
    const isBump = Number(item?.position ?? 0) > 0;
    const baseName =
        item?.product?.name ??
        item?.product_offer?.name ??
        item?.subscription_plan?.name ??
        'Item';
    return isBump ? `${baseName} (Bump)` : baseName;
}
</script>

<template>
    <Teleport to="body">
        <div
            v-if="open"
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
                class="relative z-[100001] flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900 sm:w-[420px]"
            >
                <div class="flex items-center justify-between rounded-tl-2xl px-5 py-5">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Detalhes da venda
                    </h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div v-if="!venda" class="flex flex-1 items-center justify-center p-8">
                    <p class="text-sm text-zinc-500">Nenhuma venda selecionada.</p>
                </div>

                <div v-else class="flex min-w-0 flex-1 flex-col overflow-hidden">
                    <HorizontalScrollTabs
                        aria-label="Abas"
                        :bleed="false"
                        nav-class="gap-1 bg-zinc-50/80 px-4 py-2 dark:bg-zinc-800/50"
                    >
                        <button
                            type="button"
                            :class="[
                                'rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === 'venda'
                                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-800 dark:text-[var(--color-primary)]'
                                    : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200',
                            ]"
                            @click="activeTab = 'venda'"
                        >
                            Venda
                        </button>
                        <button
                            type="button"
                            :class="[
                                'rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === 'cliente'
                                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-800 dark:text-[var(--color-primary)]'
                                    : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200',
                            ]"
                            @click="activeTab = 'cliente'"
                        >
                            Cliente
                        </button>
                        <button
                            v-for="panel in plugin_order_detail_panels"
                            :key="panel.id || panel.plugin_slug"
                            type="button"
                            :class="[
                                'rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                                activeTab === `plugin-${panel.id}`
                                    ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-800 dark:text-[var(--color-primary)]'
                                    : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200',
                            ]"
                            @click="activeTab = `plugin-${panel.id}`"
                        >
                            {{ panel.label || panel.id }}
                        </button>
                    </HorizontalScrollTabs>

                    <div class="flex-1 overflow-y-auto p-5">
                        <!-- Aba Venda -->
                        <div v-show="activeTab === 'venda'" class="space-y-5">
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">ID da venda</p>
                                <p class="font-mono text-sm text-zinc-700 dark:text-zinc-300">{{ String(venda.id) }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ statusLabel(venda.status) }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tipo</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.payment_type_label ?? 'Pagamento único' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Valor bruto
                                </p>
                                <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ formatMoney(vendaGrossAmount(venda), venda.currency) }}
                                </p>
                            </div>
                            <div
                                v-if="venda.status === 'completed'"
                                class="space-y-2 rounded-lg border border-zinc-200/80 p-3 dark:border-zinc-700/80"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Financeiro (gateway)
                                </p>
                                <div class="grid grid-cols-3 gap-2 text-sm">
                                    <div>
                                        <p class="text-[11px] text-zinc-500">Bruto</p>
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ formatMoney(venda.gross_amount ?? vendaGrossAmount(venda), venda.currency) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] text-zinc-500">Taxa</p>
                                        <p class="font-medium text-zinc-900 dark:text-white">
                                            {{ formatMoney(venda.gateway_fee ?? 0, venda.currency) }}
                                            <span
                                                v-if="venda.fee_source === 'gateway_webhook' || venda.fee_source === 'cajupay_webhook'"
                                                class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-300"
                                                title="Taxa informada pelo gateway"
                                            >real</span>
                                            <span
                                                v-else-if="venda.fee_source === 'estimated'"
                                                class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-950/50 dark:text-amber-300"
                                                title="Taxa estimada conforme configuração do gateway"
                                            >est.</span>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] text-zinc-500">Líquido</p>
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ formatMoney(venda.net_amount ?? venda.gross_amount ?? vendaGrossAmount(venda), venda.currency) }}</p>
                                    </div>
                                </div>
                            </div>
                            <div
                                v-else
                                class="space-y-2 rounded-lg border border-dashed border-zinc-200/80 p-3 dark:border-zinc-700/80"
                            >
                                <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Financeiro estimado
                                </p>
                                <div class="grid grid-cols-3 gap-2 text-sm">
                                    <div>
                                        <p class="text-[11px] text-zinc-500">Bruto</p>
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ formatMoney(vendaGrossAmount(venda), venda.currency) }}</p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] text-zinc-500">Taxa</p>
                                        <p class="font-medium text-zinc-900 dark:text-white">
                                            {{ formatMoney(venda.gateway_fee ?? 0, venda.currency) }}
                                            <span class="text-[10px] font-normal text-zinc-500"> (est.)</span>
                                        </p>
                                    </div>
                                    <div>
                                        <p class="text-[11px] text-zinc-500">Líquido</p>
                                        <p class="font-medium text-zinc-900 dark:text-white">
                                            {{ formatMoney(venda.net_amount ?? Math.max(0, vendaGrossAmount(venda) - Number(venda.gateway_fee ?? 0)), venda.currency) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div v-if="venda.has_partner_split && venda.display_amount_is_producer_share" class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Sua parte
                                    <span v-if="venda.display_amount_is_estimated"> (estimada)</span>
                                </p>
                                <p class="text-sm text-zinc-900 dark:text-white">
                                    {{ formatMoney(vendaDisplayAmount(venda), venda.currency) }}
                                    <span
                                        v-if="venda.display_amount_is_estimated"
                                        class="text-xs font-normal text-zinc-500"
                                        title="Estimativa até confirmação do pagamento e alocação de comissões"
                                    > *</span>
                                </p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Valor que fica com você após taxas do gateway e comissões de afiliados/co-produtores.
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Produto</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.product_display_name ?? venda.product?.name ?? '–' }}</p>
                            </div>
                            <div v-if="venda.is_affiliate_sale" class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Afiliado</p>
                                <p class="text-sm text-zinc-900 dark:text-white">
                                    {{ venda.affiliate?.name ?? '—' }}
                                    <span v-if="venda.affiliate?.code" class="mt-0.5 block font-mono text-xs text-zinc-500">
                                        ref {{ venda.affiliate.code }}
                                    </span>
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Método de pagamento</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.gateway_label ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Parcelas</p>
                                <p class="text-sm text-zinc-900 dark:text-white">1</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Recorrência</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.subscription_plan_id ? 'Assinatura' : '–' }}</p>
                            </div>
                            <div class="space-y-2" v-if="(venda.order_items ?? []).length">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Itens da compra</p>
                                <div class="divide-y divide-zinc-100 overflow-hidden rounded-xl border border-zinc-200 bg-white dark:divide-zinc-800 dark:border-zinc-800 dark:bg-zinc-900">
                                    <div
                                        v-for="(item, idx) in (venda.order_items ?? [])"
                                        :key="idx"
                                        class="flex items-center justify-between gap-3 px-4 py-3"
                                    >
                                        <p class="text-sm text-zinc-900 dark:text-white">
                                            {{ itemLabel(item) }}
                                        </p>
                                        <p class="text-sm font-medium text-zinc-900 dark:text-white">
                                            {{ formatMoney(item.amount, venda.currency) }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">URL do Checkout</p>
                                <a
                                    v-if="venda.checkout_url"
                                    :href="venda.checkout_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-start gap-1 break-all text-sm text-[var(--color-primary)] hover:underline"
                                >
                                    {{ venda.checkout_url }}
                                    <ExternalLink class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                                </a>
                                <p v-else class="text-sm text-zinc-500">–</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Comprovação
                                    <span
                                        class="ml-1 inline-flex h-4 w-4 items-center justify-center rounded-full border border-zinc-200 text-[10px] text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
                                        title="Gera um dossiê com dados do comprador + evidências de entrega/atividade (progresso, logs, IP). Útil para comprovar a venda em gateways (MED/chargeback/auditoria)."
                                    >
                                        ?
                                    </span>
                                </p>
                                <a
                                    :href="`/vendas/${venda.id}/comprovacao`"
                                    class="inline-flex items-center gap-1 text-sm text-[var(--color-primary)] hover:underline"
                                    title="Abrir dossiê de comprovação (documento para comprovar a venda e o acesso/atividade do aluno)"
                                >
                                    Abrir dossiê de comprovação
                                    <ExternalLink class="h-3.5 w-3.5 shrink-0" />
                                </a>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_source</p>
                                <p class="text-sm" :class="utmSource ? 'text-zinc-900 dark:text-white' : 'text-zinc-500'">
                                    {{ utmSource || 'Não informado' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_campaign</p>
                                <p class="text-sm" :class="utmCampaign ? 'text-zinc-900 dark:text-white' : 'text-zinc-500'">
                                    {{ utmCampaign || 'Não informado' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_medium</p>
                                <p class="text-sm" :class="utmMedium ? 'text-zinc-900 dark:text-white' : 'text-zinc-500'">
                                    {{ utmMedium || 'Não informado' }}
                                </p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data de criação</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ formatDate(venda.created_at) }}</p>
                            </div>
                        </div>

                        <div
                            v-for="panel in plugin_order_detail_panels"
                            :key="`panel-${panel.id}`"
                            v-show="activeTab === `plugin-${panel.id}`"
                        >
                            <PluginSlotHost
                                layout="stack"
                                :items="[panel]"
                                :context="{ venda, order: venda }"
                            />
                            <PluginRenderZone
                                zone="vendas.detail.after_panel"
                                :context="{ venda, order: venda }"
                            />
                        </div>

                        <!-- Aba Cliente -->
                        <div v-show="activeTab === 'cliente'" class="space-y-5">
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Nome</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.user?.name ?? venda.email ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">E-mail</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.email ?? venda.user?.email ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Celular</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.phone ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">CPF</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.cpf ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">IP</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.customer_ip ?? '–' }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
