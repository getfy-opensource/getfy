<script setup>
import { Link } from '@inertiajs/vue3';
import { ShoppingBag, ArrowLeft, ChevronLeft, ChevronRight, Download } from 'lucide-vue-next';
import LayoutGuest from '@/Layouts/LayoutGuest.vue';

defineOptions({ layout: LayoutGuest });

const props = defineProps({
    pedidos: { type: Object, default: () => ({ data: [], links: [] }) },
    hasAreaMembros: { type: Boolean, default: false },
});

function statusClass(status) {
    const map = {
        completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
        pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        disputed: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
        cancelled: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300',
        refunded: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    };
    return map[status] ?? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300';
}

function statusLabel(status) {
    const map = {
        completed: 'Pago',
        pending: 'Pendente',
        disputed: 'Em disputa',
        cancelled: 'Cancelado',
        refunded: 'Reembolsado',
    };
    return map[status] ?? status;
}

function typeLabel(type) {
    const map = {
        area_membros: 'Área de Membros',
        link: 'Link',
        link_pagamento: 'Link de Pagamento',
        aplicativo: 'Aplicativo',
    };
    return map[type] ?? type;
}

const currency = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });
function formatBRL(value) {
    return currency.format(value ?? 0);
}
</script>

<template>
    <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Meus Pedidos</h1>
                    <p class="mt-1 text-zinc-600 dark:text-zinc-400">Histórico de todas as suas compras.</p>
                </div>
                <div class="flex items-center gap-3">
                    <Link
                        v-if="hasAreaMembros"
                        href="/area-membros"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                    >
                        <ArrowLeft class="h-4 w-4" />
                        Área de Membros
                    </Link>
                    <Link href="/logout" method="post" as="button" class="text-sm text-zinc-600 hover:underline dark:text-zinc-400">Sair</Link>
                </div>
            </div>

            <!-- Desktop table -->
            <div v-if="pedidos.data.length" class="hidden md:block rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 overflow-hidden">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/50">
                            <th class="px-4 py-3 text-left font-medium text-zinc-600 dark:text-zinc-400">Produto</th>
                            <th class="px-4 py-3 text-left font-medium text-zinc-600 dark:text-zinc-400">Tipo</th>
                            <th class="px-4 py-3 text-left font-medium text-zinc-600 dark:text-zinc-400">Valor</th>
                            <th class="px-4 py-3 text-left font-medium text-zinc-600 dark:text-zinc-400">Pagamento</th>
                            <th class="px-4 py-3 text-left font-medium text-zinc-600 dark:text-zinc-400">Status</th>
                            <th class="px-4 py-3 text-left font-medium text-zinc-600 dark:text-zinc-400">Data</th>
                            <th class="px-4 py-3 text-center font-medium text-zinc-600 dark:text-zinc-400">Recibo</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr v-for="p in pedidos.data" :key="p.id" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30 transition-colors">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <img
                                        v-if="p.product_image"
                                        :src="p.product_image"
                                        :alt="p.product_name"
                                        class="h-9 w-9 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700"
                                    />
                                    <div v-else class="h-9 w-9 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                        <ShoppingBag class="h-4 w-4 text-zinc-400" />
                                    </div>
                                    <div>
                                        <p class="font-medium text-zinc-900 dark:text-white">{{ p.product_name }}</p>
                                        <p v-if="p.offer_name" class="text-xs text-zinc-500">{{ p.offer_name }}</p>
                                        <p v-if="p.plan_name" class="text-xs text-zinc-500">{{ p.plan_name }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">{{ typeLabel(p.product_type) }}</td>
                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-white">{{ formatBRL(p.amount) }}</td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400">
                                <div>{{ p.gateway }}</div>
                                <div v-if="p.payment_method && p.payment_method !== '—'" class="text-xs text-zinc-500">{{ p.payment_method }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <span :class="statusClass(p.status)" class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium">
                                    {{ statusLabel(p.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ p.created_at }}</td>
                            <td class="px-4 py-3 text-center">
                                <a
                                    v-if="p.status === 'completed'"
                                    :href="`/meus-pedidos/${p.id}/recibo`"
                                    target="_blank"
                                    class="inline-flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                                    title="Abrir recibo"
                                >
                                    <Download class="h-3.5 w-3.5" />
                                    PDF
                                </a>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Mobile cards -->
            <div v-if="pedidos.data.length" class="md:hidden space-y-3">
                <div
                    v-for="p in pedidos.data"
                    :key="p.id"
                    class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 space-y-3"
                >
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <img
                                v-if="p.product_image"
                                :src="p.product_image"
                                :alt="p.product_name"
                                class="h-10 w-10 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700"
                            />
                            <div v-else class="h-10 w-10 rounded-lg bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                <ShoppingBag class="h-5 w-5 text-zinc-400" />
                            </div>
                            <div>
                                <p class="font-medium text-zinc-900 dark:text-white">{{ p.product_name }}</p>
                                <p v-if="p.offer_name" class="text-xs text-zinc-500">{{ p.offer_name }}</p>
                                <p v-if="p.plan_name" class="text-xs text-zinc-500">{{ p.plan_name }}</p>
                            </div>
                        </div>
                        <span :class="statusClass(p.status)" class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium">
                            {{ statusLabel(p.status) }}
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-sm text-zinc-600 dark:text-zinc-400">
                        <span>{{ typeLabel(p.product_type) }}</span>
                        <span class="font-medium text-zinc-900 dark:text-white">{{ formatBRL(p.amount) }}</span>
                    </div>
                    <div class="flex items-center justify-between text-xs text-zinc-500">
                        <span>{{ p.gateway }}<template v-if="p.payment_method && p.payment_method !== '—'"> — {{ p.payment_method }}</template></span>
                        <span>{{ p.created_at }}</span>
                    </div>
                    <div v-if="p.coupon_code" class="text-xs text-zinc-500">
                        Cupom: <span class="font-mono">{{ p.coupon_code }}</span>
                    </div>
                    <a
                        v-if="p.status === 'completed'"
                        :href="`/meus-pedidos/${p.id}/recibo`"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-xs font-medium text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition w-full justify-center"
                    >
                        <Download class="h-3.5 w-3.5" />
                        Ver Recibo
                    </a>
                </div>
            </div>

            <!-- Empty state -->
            <div v-if="!pedidos.data.length" class="flex flex-col items-center justify-center py-16 text-center">
                <div class="h-16 w-16 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center mb-4">
                    <ShoppingBag class="h-8 w-8 text-zinc-400" />
                </div>
                <p class="text-zinc-600 dark:text-zinc-400 font-medium">Nenhum pedido encontrado.</p>
                <p class="mt-1 text-sm text-zinc-500">Suas compras aparecerão aqui.</p>
            </div>

            <!-- Pagination -->
            <nav v-if="pedidos.links && pedidos.links.length > 3" class="flex items-center justify-center gap-1">
                <template v-for="(link, i) in pedidos.links" :key="i">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        class="inline-flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-3 text-sm font-medium transition"
                        :class="link.active
                            ? 'bg-[var(--color-primary,#0ea5e9)] text-white'
                            : 'text-zinc-600 dark:text-zinc-400 hover:bg-zinc-100 dark:hover:bg-zinc-800'"
                        v-html="link.label"
                        preserve-scroll
                    />
                    <span
                        v-else
                        class="inline-flex h-9 min-w-[2.25rem] items-center justify-center rounded-lg px-3 text-sm text-zinc-400 dark:text-zinc-600"
                        v-html="link.label"
                    />
                </template>
            </nav>
        </div>
</template>
