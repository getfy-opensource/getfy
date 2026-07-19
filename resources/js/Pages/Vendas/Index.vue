<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { useInertiaPagination } from '@/composables/useInertiaPagination';
import { whatsappUrlForPhone } from '@/lib/utils';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import HorizontalScrollTabs from '@/components/ui/HorizontalScrollTabs.vue';
import VendaDetailSidebar from '@/components/vendas/VendaDetailSidebar.vue';
import PluginRuntimeMount from '@/components/plugins/PluginRuntimeMount.vue';
import PluginRenderZone from '@/components/plugins/PluginRenderZone.vue';
import {
    Eye,
    EyeOff,
    CircleDollarSign,
    CreditCard,
    Banknote,
    ShoppingCart,
    MoreVertical,
    FileText,
    Mail,
    Download,
    CheckCircle,
    RotateCcw,
    Search,
    X,
    ChevronLeft,
    ChevronRight,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    vendas: { type: Object, default: () => ({ data: [], links: [] }) },
    stats: { type: Object, default: () => ({}) },
    status_filter: { type: String, default: 'todas' },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    offers: { type: Array, default: () => [] },
    plugin_fulfillment_providers: { type: Array, default: () => [] },
    plugin_vendas_row_actions: { type: Array, default: () => [] },
    plugin_order_detail_panels: { type: Array, default: () => [] },
});

const vendasList = computed(() => props.vendas?.data ?? props.vendas ?? []);

const {
    paginationPrev,
    paginationNext,
    paginationPages,
    paginationSummary,
    hasPagination,
    isEllipsisLink,
    visitPaginationPage,
    paginationLinkClass,
} = useInertiaPagination(() => props.vendas);

const valuesVisible = ref(true);
const sidebarOpen = ref(false);
const selectedVenda = ref(null);
const openMenuId = ref(null);
const menuAnchorEl = ref(null);
const menuEl = ref(null);
const menuPos = ref({ top: 0, left: 0 });
const page = usePage();
const resendingId = ref(null);
const approvingId = ref(null);
const refundingId = ref(null);
const refundModalOpen = ref(false);
const refundTarget = ref(null);
const refundAdminNotes = ref('');
const toast = ref({ message: null, type: null });

const canManageRefund = computed(() => {
    const role = page.props.auth?.user?.role;
    if (role === 'admin' || role === 'infoprodutor') return true;
    return !!page.props.auth?.permissions?.['reembolsos.manage'];
});
let toastTimer = null;

const filterOptions = [
    { value: 'aprovadas', label: 'Aprovadas' },
    { value: 'med', label: 'MED' },
    { value: 'todas', label: 'Todas' },
];

const periodOptions = [
    { value: 'all', label: 'Todo período' },
    { value: 'today', label: 'Hoje' },
    { value: '7d', label: 'Últimos 7 dias' },
    { value: '30d', label: 'Últimos 30 dias' },
    { value: 'this_month', label: 'Este mês' },
    { value: 'last_month', label: 'Mês passado' },
    { value: 'custom', label: 'Personalizado' },
];

const paymentMethodOptions = [
    { value: 'all', label: 'Todos métodos' },
    { value: 'pix', label: 'PIX' },
    { value: 'card', label: 'Cartão' },
    { value: 'boleto', label: 'Boleto' },
];

const paymentStatusOptions = [
    { value: 'all', label: 'Todos status' },
    { value: 'completed', label: 'Pago' },
    { value: 'pending', label: 'Pendente' },
    { value: 'disputed', label: 'MED' },
    { value: 'cancelled', label: 'Cancelado' },
    { value: 'refunded', label: 'Reembolsado' },
];

const filterForm = ref({
    q: props.filters?.q ?? '',
    period: props.filters?.period ?? 'all',
    date_from: props.filters?.date_from ?? '',
    date_to: props.filters?.date_to ?? '',
    product_id: props.filters?.product_id ?? '',
    offer_id: props.filters?.offer_id ?? '',
    payment_method: props.filters?.payment_method ?? 'all',
    payment_status: props.filters?.payment_status ?? 'all',
    utm_source: props.filters?.utm_source ?? '',
    utm_medium: props.filters?.utm_medium ?? '',
    utm_campaign: props.filters?.utm_campaign ?? '',
});

watch(
    () => props.filters,
    (filters) => {
        if (!filters || typeof filters !== 'object') return;
        filterForm.value = {
            q: filters.q ?? '',
            period: filters.period ?? 'all',
            date_from: filters.date_from ?? '',
            date_to: filters.date_to ?? '',
            product_id: filters.product_id ?? '',
            offer_id: filters.offer_id ?? '',
            payment_method: filters.payment_method ?? 'all',
            payment_status: filters.payment_status ?? 'all',
            utm_source: filters.utm_source ?? '',
            utm_medium: filters.utm_medium ?? '',
            utm_campaign: filters.utm_campaign ?? '',
        };
    },
    { deep: true }
);

const advancedFiltersOpen = ref(false);
let searchTimer = null;

const offersForSelectedProduct = computed(() => {
    const pid = filterForm.value.product_id;
    if (!pid) return props.offers ?? [];
    return (props.offers ?? []).filter((o) => String(o.product_id) === String(pid));
});

function buildQuery(overrides = {}) {
    const f = { ...filterForm.value, ...overrides };
    const q = { status_filter: props.status_filter, ...f };

    const cleaned = {};
    Object.entries(q).forEach(([k, v]) => {
        if (v === null || v === undefined) return;
        if (typeof v === 'string' && v.trim() === '') return;
        if ((k === 'period' || k === 'payment_method' || k === 'payment_status') && v === 'all') return;
        cleaned[k] = v;
    });
    if (cleaned.period !== 'custom') {
        delete cleaned.date_from;
        delete cleaned.date_to;
    }
    return cleaned;
}

function applyFilters(overrides = {}) {
    router.get('/vendas', buildQuery(overrides), {
        preserveState: false,
        preserveScroll: true,
        replace: true,
    });
}

const menuVenda = computed(() => {
    if (openMenuId.value == null) return null;
    const list = vendasList.value ?? [];
    return list.find((x) => x.id === openMenuId.value) ?? null;
});

function setFilter(value) {
    applyFilters({ status_filter: value });
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value ?? 0);
}

function formatMoney(value, currency = 'BRL') {
    const code = typeof currency === 'string' && currency.trim() ? currency.trim().toUpperCase() : 'BRL';
    const locale = code === 'BRL' ? 'pt-BR' : code === 'EUR' ? 'de-DE' : 'en-US';
    return new Intl.NumberFormat(locale, { style: 'currency', currency: code }).format(value ?? 0);
}

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function vendaBilledAmount(v) {
    return v?.billed_amount ?? v?.amount_total ?? v?.amount ?? 0;
}

function vendaNetProfitAmount(v) {
    return v?.net_profit_amount ?? v?.display_amount ?? 0;
}

function hasNetProfitAmount(v) {
    return v?.net_profit_amount !== null && v?.net_profit_amount !== undefined;
}

function displayNetProfit(v) {
    return hasNetProfitAmount(v) ? displayMoney(vendaNetProfitAmount(v), v.currency) : '–';
}

function displayMoney(value, currency = 'BRL') {
    return valuesVisible.value ? formatMoney(value, currency) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
}

function whatsappUrl(venda) {
    return whatsappUrlForPhone(venda?.phone);
}

function statusBadgeClass(status) {
    const map = {
        completed: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-300',
        pending: 'bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300',
        disputed: 'bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300',
        cancelled: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300',
        refunded: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    };
    return map[status] ?? 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700/50 dark:text-zinc-300';
}

function statusBadgeLabel(status) {
    const map = {
        completed: 'Pago',
        pending: 'Pendente',
        disputed: 'MED',
        cancelled: 'Cancelado',
        refunded: 'Reembolsado',
    };
    return map[status] ?? status ?? '–';
}

function openDetail(v) {
    selectedVenda.value = v;
    sidebarOpen.value = true;
    closeMenu();
}

function closeSidebar() {
    sidebarOpen.value = false;
    selectedVenda.value = null;
}

async function updateMenuPosition() {
    const anchor = menuAnchorEl.value;
    if (!anchor || openMenuId.value == null) return;

    const rect = anchor.getBoundingClientRect();
    const minMargin = 8;
    const desiredWidth = 192;
    const viewportW = window.innerWidth || 0;
    const viewportH = window.innerHeight || 0;

    let left = rect.right - desiredWidth;
    left = Math.max(minMargin, Math.min(left, Math.max(minMargin, viewportW - desiredWidth - minMargin)));

    let top = rect.bottom + 4;
    top = Math.max(minMargin, Math.min(top, Math.max(minMargin, viewportH - minMargin)));

    menuPos.value = { top, left };

    await nextTick();
    const menu = menuEl.value;
    if (!menu) return;

    const menuRect = menu.getBoundingClientRect();
    const spaceBelow = viewportH - rect.bottom;
    const spaceAbove = rect.top;
    const shouldOpenUp = menuRect.height + 8 > spaceBelow && spaceAbove >= menuRect.height + 8;

    if (shouldOpenUp) {
        const newTop = Math.max(minMargin, rect.top - menuRect.height - 4);
        menuPos.value = { top: newTop, left: menuPos.value.left };
    }
}

async function toggleMenu(id, event) {
    if (openMenuId.value === id) {
        closeMenu();
        return;
    }
    openMenuId.value = id;
    menuAnchorEl.value = event?.currentTarget ?? null;
    await nextTick();
    await updateMenuPosition();
}

function pluginActionHref(action, venda) {
    const raw = action.href ?? action.route ?? '';
    const base = String(raw).startsWith('/') ? String(raw) : `/${action.plugin_slug}/${String(raw).replace(/^\//, '')}`;
    return base.replace(':order_id', String(venda?.id ?? ''));
}

function closeMenu() {
    openMenuId.value = null;
    menuAnchorEl.value = null;
}

function handleClickOutside(event) {
    if (openMenuId.value == null) return;
    const el = document.querySelector(`[data-venda-menu="${openMenuId.value}"]`);
    const menu = menuEl.value;
    if (el && el.contains(event.target)) return;
    if (menu && menu.contains(event.target)) return;
    closeMenu();
}

async function resendEmail(v) {
    closeMenu();
    if (resendingId.value) return;
    resendingId.value = v.id;
    try {
        const { data } = await axios.post(`/vendas/${v.id}/resend-access-email`);
        if (data.success) {
            showToast('E-mail de compra reenviado com sucesso.', 'success');
        } else {
            showToast(data.message ?? 'Não foi possível reenviar o e-mail.', 'error');
        }
    } catch (err) {
        showToast(
            err.response?.data?.message ?? 'Erro ao reenviar e-mail. Tente novamente.',
            'error'
        );
    } finally {
        resendingId.value = null;
    }
}

function openRefundModal(v) {
    closeMenu();
    refundTarget.value = v;
    refundAdminNotes.value = '';
    refundModalOpen.value = true;
}

function closeRefundModal() {
    refundModalOpen.value = false;
    refundTarget.value = null;
    refundAdminNotes.value = '';
}

async function confirmRefund() {
    const v = refundTarget.value;
    if (!v || refundingId.value) return;
    refundingId.value = v.id;
    try {
        const { data } = await axios.post(`/vendas/${v.id}/refund`, {
            admin_notes: refundAdminNotes.value.trim() || null,
        });
        if (data.success) {
            closeRefundModal();
            showToast(data.message ?? 'Reembolso processado.', 'success');
            router.reload({ preserveScroll: true });
        } else {
            showToast(data.message ?? 'Não foi possível reembolsar.', 'error');
        }
    } catch (err) {
        showToast(
            err.response?.data?.message ?? 'Erro ao reembolsar. Tente novamente.',
            'error'
        );
    } finally {
        refundingId.value = null;
    }
}

async function approveManually(v) {
    closeMenu();
    if (approvingId.value) return;
    approvingId.value = v.id;
    try {
        const { data } = await axios.post(`/vendas/${v.id}/approve-manually`);
        if (data.success) {
            showToast(data.message ?? 'Pedido aprovado com sucesso.', 'success');
            router.reload({ preserveScroll: true });
        } else {
            showToast(data.message ?? 'Não foi possível aprovar o pedido.', 'error');
        }
    } catch (err) {
        showToast(
            err.response?.data?.message ?? 'Erro ao aprovar pedido. Tente novamente.',
            'error'
        );
    } finally {
        approvingId.value = null;
    }
}

function showToast(message, type) {
    toast.value = { message, type };
    if (toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
        toast.value = { message: null, type: null };
        toastTimer = null;
    }, 4000);
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
    window.addEventListener('resize', updateMenuPosition);
    window.addEventListener('scroll', updateMenuPosition, true);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    window.removeEventListener('resize', updateMenuPosition);
    window.removeEventListener('scroll', updateMenuPosition, true);
    if (toastTimer) clearTimeout(toastTimer);
    if (searchTimer) clearTimeout(searchTimer);
});

function onSearchInput() {
    const q = (filterForm.value.q ?? '').trim();
    if (q !== '' && q.length < 3) {
        if (searchTimer) clearTimeout(searchTimer);
        searchTimer = null;
        return;
    }
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        applyFilters();
        searchTimer = null;
    }, 600);
}

function onFilterChange() {
    applyFilters();
}

function clearFilters() {
    filterForm.value = {
        q: '',
        period: 'all',
        date_from: '',
        date_to: '',
        product_id: '',
        offer_id: '',
        payment_method: 'all',
        payment_status: 'all',
        utm_source: '',
        utm_medium: '',
        utm_campaign: '',
    };
    applyFilters();
}

const exportCsvUrl = computed(() => {
    const params = new URLSearchParams({ ...buildQuery(), format: 'csv' });
    return `/vendas/export?${params.toString()}`;
});

const exportXlsUrl = computed(() => {
    const params = new URLSearchParams({ ...buildQuery(), format: 'xls' });
    return `/vendas/export?${params.toString()}`;
});

function openProofExport() {
    window.open('/vendas/comprovacao/exportar', '_blank', 'noopener,noreferrer');
}
</script>

<template>
    <div class="space-y-6">
        <VendasTabs />
        <PluginRenderZone zone="vendas.index.after_toolbar" />

        <!-- Cards de métricas -->
        <div class="space-y-3">
            <div class="flex items-center justify-end gap-2">
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 text-sm font-medium text-zinc-900 transition-colors hover:bg-zinc-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:hover:bg-zinc-800"
                    @click="openProofExport"
                    title="Exportar comprovações (ZIP)"
                >
                    <FileText class="h-4 w-4" />
                    Comprovação
                </button>
                <button
                    type="button"
                    :aria-label="valuesVisible ? 'Ocultar valores' : 'Mostrar valores'"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                    @click="valuesVisible = !valuesVisible"
                >
                    <Eye v-if="valuesVisible" class="h-5 w-5" aria-hidden="true" />
                    <EyeOff v-else class="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
                <div
                    class="panel-card-md"
                >
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <ShoppingCart class="h-5 w-5" />
                        <span class="text-sm font-medium">Vendas encontradas</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                        {{ displayNumber(stats.vendas_encontradas ?? 0) }}
                    </p>
                </div>
                <div
                    class="panel-card-md"
                >
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <CircleDollarSign class="h-5 w-5" />
                        <span class="text-sm font-medium">Valor faturado</span>
                    </div>
                    <div v-if="(stats.valor_por_moeda ?? []).length" class="mt-2 space-y-1">
                        <p
                            v-for="row in stats.valor_por_moeda"
                            :key="row.currency"
                            class="text-lg font-bold text-zinc-900 dark:text-white sm:text-2xl"
                        >
                            {{ displayMoney(row.total, row.currency) }}
                        </p>
                    </div>
                    <p v-else class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                        {{ displayMoney(0, 'BRL') }}
                    </p>
                </div>
                <div
                    class="panel-card-md"
                >
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <Banknote class="h-5 w-5" />
                        <span class="text-sm font-medium">Lucro líquido</span>
                    </div>
                    <div v-if="(stats.lucro_liquido_por_moeda ?? []).length" class="mt-2 space-y-1">
                        <p
                            v-for="row in stats.lucro_liquido_por_moeda"
                            :key="row.currency"
                            class="text-lg font-bold text-emerald-600 dark:text-emerald-400 sm:text-2xl"
                        >
                            {{ displayMoney(row.total, row.currency) }}
                        </p>
                    </div>
                    <p v-else class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                        {{ displayMoney(0, 'BRL') }}
                    </p>
                </div>
                <div
                    class="panel-card-md"
                >
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <Banknote class="h-5 w-5" />
                        <span class="text-sm font-medium">Vendas no PIX</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                        {{ displayNumber(stats.vendas_pix ?? 0) }}
                    </p>
                </div>
                <div
                    class="panel-card-md"
                >
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <CreditCard class="h-5 w-5" />
                        <span class="text-sm font-medium">Vendas no cartão</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                        {{ displayNumber(stats.vendas_cartao ?? 0) }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Abas de filtro e exportação -->
        <div class="flex min-w-0 flex-wrap items-center justify-between gap-3">
            <HorizontalScrollTabs aria-label="Filtrar vendas" class="min-w-0 max-w-full flex-1 sm:max-w-none">
                <button
                    v-for="opt in filterOptions"
                    :key="opt.value"
                    type="button"
                    :aria-current="status_filter === opt.value ? 'true' : undefined"
                    :class="[
                        'rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                        status_filter === opt.value
                            ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                            : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                    ]"
                    @click="setFilter(opt.value)"
                >
                    {{ opt.label }}
                </button>
            </HorizontalScrollTabs>
            <div class="flex items-center gap-2">
                <a
                    :href="exportCsvUrl"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                >
                    <Download class="h-4 w-4" />
                    Exportar CSV
                </a>
                <a
                    :href="exportXlsUrl"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                >
                    <Download class="h-4 w-4" />
                    Exportar XLS
                </a>
            </div>
        </div>

        <!-- Busca e filtros -->
        <div class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="relative w-full max-w-xl">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                    <input
                        v-model="filterForm.q"
                        type="text"
                        class="w-full rounded-xl border border-zinc-200 bg-white py-2 pl-10 pr-10 text-sm text-zinc-900 shadow-sm transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        placeholder="Buscar por cliente, e-mail, pedido, produto..."
                        @input="onSearchInput"
                    />
                    <button
                        v-if="filterForm.q"
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                        aria-label="Limpar busca"
                        @click="filterForm.q = ''; onFilterChange()"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-2 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="clearFilters"
                >
                    Limpar filtros
                </button>
            </div>

            <div class="grid gap-3 lg:grid-cols-6">
                <div class="lg:col-span-2">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Período</label>
                    <select
                        v-model="filterForm.period"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option v-for="p in periodOptions" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                </div>

                <div v-if="filterForm.period === 'custom'">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">De</label>
                    <input
                        v-model="filterForm.date_from"
                        type="date"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    />
                </div>
                <div v-if="filterForm.period === 'custom'">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Até</label>
                    <input
                        v-model="filterForm.date_to"
                        type="date"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    />
                </div>

                <div :class="filterForm.period === 'custom' ? 'lg:col-span-2' : ''">
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Produto</label>
                    <select
                        v-model="filterForm.product_id"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="() => { if (filterForm.offer_id && !offersForSelectedProduct.some(o => String(o.id) === String(filterForm.offer_id))) filterForm.offer_id = ''; onFilterChange(); }"
                    >
                        <option value="">Todos produtos</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Oferta</label>
                    <select
                        v-model="filterForm.offer_id"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option value="">Todas ofertas</option>
                        <option v-for="o in offersForSelectedProduct" :key="o.id" :value="o.id">
                            {{ o.product_name ? `${o.product_name} - ${o.name}` : o.name }}
                        </option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Método</label>
                    <select
                        v-model="filterForm.payment_method"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option v-for="m in paymentMethodOptions" :key="m.value" :value="m.value">{{ m.label }}</option>
                    </select>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Status</label>
                    <select
                        v-model="filterForm.payment_status"
                        class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        @change="onFilterChange"
                    >
                        <option v-for="s in paymentStatusOptions" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
            </div>

            <div>
                <button
                    type="button"
                    class="text-sm font-medium text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                    @click="advancedFiltersOpen = !advancedFiltersOpen"
                >
                    {{ advancedFiltersOpen ? 'Ocultar filtros avançados' : 'Mostrar filtros avançados' }}
                </button>
                <div v-if="advancedFiltersOpen" class="mt-3 grid gap-3 lg:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_source</label>
                        <input
                            v-model="filterForm.utm_source"
                            type="text"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_medium</label>
                        <input
                            v-model="filterForm.utm_medium"
                            type="text"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">utm_campaign</label>
                        <input
                            v-model="filterForm.utm_campaign"
                            type="text"
                            class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                            @change="onFilterChange"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Tabela de vendas -->
        <div class="sm:hidden space-y-3">
            <div
                v-for="v in vendasList"
                :key="v.id"
                class="panel-card-sm transition hover:opacity-95 dark:hover:opacity-95"
                role="button"
                tabindex="0"
                @click="openDetail(v)"
                @keydown.enter.prevent="openDetail(v)"
                @keydown.space.prevent="openDetail(v)"
            >
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ new Date(v.created_at).toLocaleDateString('pt-BR') }}
                        </p>
                        <p class="mt-1 break-words text-sm font-semibold leading-snug text-zinc-900 dark:text-white">
                            {{ v.product_display_name ?? v.product?.name ?? '–' }}
                        </p>
                        <p
                            v-if="v.is_affiliate_sale"
                            class="mt-1 inline-flex max-w-full flex-wrap items-center gap-1 text-xs text-violet-700 dark:text-violet-300"
                        >
                            <span class="rounded-full bg-violet-100 px-2 py-0.5 font-medium dark:bg-violet-950/50">Afiliado</span>
                            <span v-if="v.affiliate?.name" class="truncate">{{ v.affiliate.name }}</span>
                            <span v-else-if="v.affiliate?.code" class="font-mono">{{ v.affiliate.code }}</span>
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-1" @click.stop>
                        <a
                            v-if="whatsappUrl(v)"
                            :href="whatsappUrl(v)"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="flex h-9 w-9 items-center justify-center rounded-lg text-[#25D366] transition hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
                            :aria-label="`WhatsApp de ${v.user?.name ?? 'cliente'}`"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                            </svg>
                        </a>
                        <div class="relative" :data-venda-menu="v.id">
                            <button
                                type="button"
                                class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                aria-label="Abrir menu"
                                aria-expanded="openMenuId === v.id"
                                @click="toggleMenu(v.id, $event)"
                            >
                                <MoreVertical class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-4 rounded-lg bg-zinc-50/60 p-3 dark:bg-zinc-900/30">
                    <div class="grid grid-cols-2 gap-x-4 gap-y-3">
                        <div class="min-w-0">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Cliente
                            </p>
                            <p class="mt-1 break-words text-sm font-medium leading-snug text-zinc-900 dark:text-white">
                                {{ v.user?.name ?? '–' }}
                            </p>
                            <p class="mt-0.5 break-words text-xs leading-snug text-zinc-500 dark:text-zinc-400">
                                {{ v.email ?? v.user?.email ?? '–' }}
                            </p>
                        </div>
                        <div class="min-w-0 text-right">
                            <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Status
                            </p>
                            <div class="mt-1 flex flex-col items-end gap-1">
                                <span
                                    :class="[
                                        'inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium',
                                        statusBadgeClass(v.status),
                                    ]"
                                >
                                    {{ statusBadgeLabel(v.status) }}
                                </span>
                                <span class="break-words text-xs leading-snug text-zinc-500 dark:text-zinc-400">
                                    {{ v.gateway_label ?? '–' }}
                                </span>
                            </div>
                        </div>
                        <div class="col-span-2 grid grid-cols-2 gap-3">
                            <div>
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Valor faturado
                                </p>
                                <p class="mt-1 text-base font-semibold tabular-nums text-zinc-900 dark:text-white">
                                    {{ displayMoney(vendaBilledAmount(v), v.currency) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Lucro líquido
                                </p>
                                <p class="mt-1 text-base font-semibold tabular-nums text-emerald-600 dark:text-emerald-400">
                                    {{ displayNetProfit(v) }}
                                    <span
                                        v-if="hasNetProfitAmount(v) && v.net_profit_amount_is_estimated"
                                        title="Estimativa com base nas taxas configuradas"
                                    >*</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div
                v-if="!vendasList.length"
                class="panel-card px-4 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400"
            >
                Nenhuma venda encontrada.
            </div>
        </div>

        <div class="hidden panel-table sm:block">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Data
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Produto
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Cliente
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Status
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Valor faturado
                        </th>
                        <th
                            class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                        >
                            Lucro líquido
                        </th>
                        <th class="relative w-20 px-2 py-3">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr
                        v-for="v in vendasList"
                        :key="v.id"
                        class="cursor-pointer transition hover:bg-zinc-100/80 dark:hover:bg-zinc-700/50"
                        @click="openDetail(v)"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                            {{ new Date(v.created_at).toLocaleDateString('pt-BR') }}
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-900 dark:text-white">
                            <p>{{ v.product_display_name ?? v.product?.name ?? '–' }}</p>
                            <p
                                v-if="v.is_affiliate_sale"
                                class="mt-1 flex flex-wrap items-center gap-1 text-xs text-violet-700 dark:text-violet-300"
                            >
                                <span class="rounded-full bg-violet-100 px-2 py-0.5 font-medium dark:bg-violet-950/50">Afiliado</span>
                                <span v-if="v.affiliate?.name">{{ v.affiliate.name }}</span>
                                <span v-else-if="v.affiliate?.code" class="font-mono">{{ v.affiliate.code }}</span>
                            </p>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-0.5">
                                <span class="text-sm font-medium text-zinc-900 dark:text-white">
                                    {{ v.user?.name ?? '–' }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ v.email ?? v.user?.email ?? '–' }}
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex flex-col gap-0.5">
                                <span
                                    :class="[
                                        'inline-flex w-fit rounded-full px-2 py-0.5 text-xs font-medium',
                                        statusBadgeClass(v.status),
                                    ]"
                                >
                                    {{ statusBadgeLabel(v.status) }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ v.gateway_label ?? '–' }}
                                </span>
                            </div>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">
                            <p>{{ displayMoney(vendaBilledAmount(v), v.currency) }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-emerald-700 dark:text-emerald-300">
                            <p>
                                {{ displayNetProfit(v) }}
                                <span
                                    v-if="hasNetProfitAmount(v) && v.net_profit_amount_is_estimated"
                                    class="text-xs font-normal text-zinc-500"
                                    title="Estimativa com base nas taxas configuradas"
                                >*</span>
                            </p>
                        </td>
                        <td class="relative whitespace-nowrap px-2 py-3" @click.stop>
                            <div class="flex items-center justify-end gap-1">
                                <a
                                    v-if="whatsappUrl(v)"
                                    :href="whatsappUrl(v)"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="flex h-8 w-8 items-center justify-center rounded-lg text-[#25D366] transition hover:bg-emerald-50 dark:hover:bg-emerald-950/40"
                                    :aria-label="`WhatsApp de ${v.user?.name ?? 'cliente'}`"
                                >
                                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.435 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z" />
                                    </svg>
                                </a>
                                <div class="relative" :data-venda-menu="v.id">
                                    <button
                                        type="button"
                                        class="flex h-8 w-8 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                        aria-label="Abrir menu"
                                        aria-expanded="openMenuId === v.id"
                                        @click="toggleMenu(v.id, $event)"
                                    >
                                        <MoreVertical class="h-4 w-4" />
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!vendasList.length" class="dark:bg-zinc-800/60">
                        <td colspan="7" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                            Nenhuma venda encontrada.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <nav
            v-if="hasPagination"
            class="relative z-10 flex w-full items-center gap-2 py-2"
            aria-label="Paginação"
        >
            <button
                type="button"
                :disabled="!paginationPrev?.url"
                :class="paginationLinkClass(paginationPrev, { iconOnly: true })"
                aria-label="Página anterior"
                @click="visitPaginationPage(paginationPrev?.url)"
            >
                <ChevronLeft class="h-5 w-5" aria-hidden="true" />
            </button>

            <span class="min-w-0 flex-1 text-center text-sm font-medium text-zinc-600 dark:text-zinc-400 sm:hidden">
                {{ paginationSummary }}
            </span>

            <div class="hidden min-w-0 flex-1 items-center justify-center gap-1 overflow-x-auto no-scrollbar sm:flex">
                <template v-for="(link, index) in paginationPages" :key="`page-${index}-${link.label}`">
                    <span
                        v-if="isEllipsisLink(link)"
                        class="inline-flex min-w-9 items-center justify-center px-2 py-2 text-sm text-zinc-400 dark:text-zinc-500"
                        aria-hidden="true"
                    >…</span>
                    <button
                        v-else
                        type="button"
                        :disabled="!link.url"
                        :aria-current="link.active ? 'page' : undefined"
                        :class="paginationLinkClass(link)"
                        @click="visitPaginationPage(link.url)"
                    >
                        {{ link.label }}
                    </button>
                </template>
            </div>

            <button
                type="button"
                :disabled="!paginationNext?.url"
                :class="paginationLinkClass(paginationNext, { iconOnly: true })"
                aria-label="Próxima página"
                @click="visitPaginationPage(paginationNext?.url)"
            >
                <ChevronRight class="h-5 w-5" aria-hidden="true" />
            </button>
        </nav>

        <!-- Sidebar de detalhes -->
        <VendaDetailSidebar
            :open="sidebarOpen"
            :venda="selectedVenda"
            :plugin_order_detail_panels="plugin_order_detail_panels"
            @close="closeSidebar"
        />

        <!-- Toast local -->
        <Teleport to="body">
            <div
                v-if="openMenuId != null && menuVenda"
                ref="menuEl"
                class="fixed z-[100000] w-48 rounded-xl border border-zinc-200 bg-white py-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                :style="{ top: `${menuPos.top}px`, left: `${menuPos.left}px` }"
                role="menu"
                aria-label="Ações da venda"
            >
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                    @click="openDetail(menuVenda)"
                >
                    <FileText class="h-4 w-4 shrink-0" />
                    Detalhes
                </button>
                <button
                    v-if="menuVenda.status === 'pending'"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300 dark:hover:bg-emerald-900/20 disabled:opacity-50"
                    :disabled="approvingId === openMenuId"
                    @click="approveManually(menuVenda)"
                >
                    <CheckCircle class="h-4 w-4 shrink-0" />
                    {{ approvingId === openMenuId ? 'Aprovando...' : 'Aprovar manualmente' }}
                </button>
                <button
                    v-if="canManageRefund && menuVenda.can_refund"
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-amber-700 hover:bg-amber-50 dark:text-amber-300 dark:hover:bg-amber-900/20"
                    @click="openRefundModal(menuVenda)"
                >
                    <RotateCcw class="h-4 w-4 shrink-0" />
                    Reembolsar
                </button>
                <button
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="resendingId === openMenuId || menuVenda.status === 'pending'"
                    title="Indisponível para pagamentos pendentes"
                    @click="resendEmail(menuVenda)"
                >
                    <Mail class="h-4 w-4 shrink-0" />
                    {{ resendingId === openMenuId ? 'Enviando...' : 'Reenviar e-mail de compra' }}
                </button>
                <template v-for="fp in plugin_fulfillment_providers" :key="`fp-${fp.plugin_slug}-${fp.id}`">
                    <div
                        v-if="fp.ui_mode === 'runtime' && menuVenda.status === 'completed'"
                        class="px-3 py-2"
                        @click="closeMenu"
                    >
                        <PluginRuntimeMount :item="fp" :context="{ order: menuVenda }" />
                    </div>
                    <a
                        v-else-if="(fp.href || fp.route) && menuVenda.status === 'completed'"
                        :href="pluginActionHref(fp, menuVenda)"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="closeMenu"
                    >
                        {{ fp.label ?? 'Enviar' }}
                    </a>
                </template>
                <template v-for="act in plugin_vendas_row_actions" :key="`act-${act.plugin_slug}-${act.id}`">
                    <div
                        v-if="act.ui_mode === 'runtime'"
                        class="px-3 py-2"
                        @click="closeMenu"
                    >
                        <PluginRuntimeMount :item="act" :context="{ order: menuVenda }" />
                    </div>
                    <a
                        v-else-if="act.href || act.route"
                        :href="pluginActionHref(act, menuVenda)"
                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        @click="closeMenu"
                    >
                        {{ act.label ?? 'Ação' }}
                    </a>
                </template>
            </div>
            <div
                v-if="refundModalOpen && refundTarget"
                class="fixed inset-0 z-[100002] flex items-center justify-center bg-black/50 p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="refund-venda-title"
                @click.self="closeRefundModal"
            >
                <div class="w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900">
                    <h2 id="refund-venda-title" class="text-lg font-semibold text-zinc-900 dark:text-white">
                        Confirmar reembolso
                    </h2>
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                        Pedido <strong>#{{ refundTarget.id }}</strong> —
                        {{ displayMoney(refundTarget.amount_total ?? refundTarget.amount, refundTarget.currency) }}
                        ({{ refundTarget.gateway_label ?? refundTarget.gateway ?? '–' }})
                    </p>
                    <p
                        v-if="refundTarget.refund_auto_cajupay_pix"
                        class="mt-3 rounded-lg bg-sky-50 px-3 py-2 text-sm text-sky-800 dark:bg-sky-950/40 dark:text-sky-200"
                    >
                        Pagamento PIX via CajuPay: o estorno será solicitado automaticamente na API. O status do pedido
                        será atualizado quando o gateway confirmar.
                    </p>
                    <p
                        v-else-if="refundTarget.gateway === 'cajupay'"
                        class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        CajuPay (cartão/outro): registre o reembolso aqui e conclua o estorno no painel CajuPay. O acesso
                        do aluno será revogado ao confirmar o webhook.
                    </p>
                    <p
                        v-else
                        class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/40 dark:text-amber-200"
                    >
                        O pedido será marcado como reembolsado e o acesso do aluno será revogado imediatamente.
                    </p>
                    <label class="mt-4 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Observação (opcional)
                    </label>
                    <textarea
                        v-model="refundAdminNotes"
                        rows="2"
                        class="mt-1 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="Motivo ou nota interna…"
                    />
                    <div class="mt-6 flex gap-2">
                        <button
                            type="button"
                            class="flex-1 rounded-lg border border-zinc-300 px-4 py-2.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-800"
                            :disabled="refundingId === refundTarget.id"
                            @click="closeRefundModal"
                        >
                            Cancelar
                        </button>
                        <button
                            type="button"
                            class="flex-1 rounded-lg bg-red-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                            :disabled="refundingId === refundTarget.id"
                            @click="confirmRefund"
                        >
                            {{ refundingId === refundTarget.id ? 'Processando…' : 'Confirmar reembolso' }}
                        </button>
                    </div>
                </div>
            </div>
            <Transition
                enter-active-class="transition duration-200 ease-out"
                enter-from-class="translate-y-2 opacity-0"
                enter-to-class="translate-y-0 opacity-100"
                leave-active-class="transition duration-150 ease-in"
                leave-from-class="translate-y-0 opacity-100"
                leave-to-class="translate-y-2 opacity-0"
            >
                <div
                    v-if="toast.message"
                    role="alert"
                    :class="[
                        'fixed bottom-4 right-4 z-[100001] max-w-sm rounded-xl border px-4 py-3 shadow-lg',
                        toast.type === 'error'
                            ? 'border-red-200 bg-red-50 text-red-800 dark:border-red-900/50 dark:bg-red-900/20 dark:text-red-200'
                            : 'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-900/20 dark:text-emerald-200',
                    ]"
                >
                    <p class="text-sm font-medium">{{ toast.message }}</p>
                </div>
            </Transition>
        </Teleport>
    </div>
</template>
