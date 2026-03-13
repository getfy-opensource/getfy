<script setup>
import { ref } from 'vue';
import { X, ExternalLink } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    venda: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const activeTab = ref('venda');

function close() {
    emit('close');
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
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
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
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
                class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900"
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

                <div v-else class="flex flex-1 flex-col overflow-hidden">
                    <nav
                        class="flex gap-1 bg-zinc-50/80 px-4 py-2 dark:bg-zinc-800/50"
                        aria-label="Abas"
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
                    </nav>

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
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Valor líquido</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ formatBRL(venda.amount) }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Produto</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.product_display_name ?? venda.product?.name ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Gateway</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.gateway_label ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Método de pagamento</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.payment_method_label ?? '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Parcelas</p>
                                <p class="text-sm text-zinc-900 dark:text-white">1</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Recorrência</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ venda.subscription_plan_id ? 'Assinatura' : '–' }}</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">URL do Checkout</p>
                                <a
                                    v-if="venda.checkout_url"
                                    :href="venda.checkout_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 text-sm text-[var(--color-primary)] hover:underline"
                                >
                                    {{ venda.checkout_url }}
                                    <ExternalLink class="h-3.5 w-3.5 shrink-0" />
                                </a>
                                <p v-else class="text-sm text-zinc-500">–</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_source</p>
                                <p class="text-sm text-zinc-500">Não informado</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_campaign</p>
                                <p class="text-sm text-zinc-500">Não informado</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_medium</p>
                                <p class="text-sm text-zinc-500">Não informado</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_content</p>
                                <p class="text-sm text-zinc-500">Não informado</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_term</p>
                                <p class="text-sm text-zinc-500">Não informado</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">sck</p>
                                <p class="text-sm text-zinc-500">Não informado</p>
                            </div>
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Data de criação</p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ formatDate(venda.created_at) }}</p>
                            </div>
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
