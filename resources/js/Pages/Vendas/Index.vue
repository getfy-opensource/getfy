<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import VendasTabs from '@/components/vendas/VendasTabs.vue';
import VendaDetailSidebar from '@/components/vendas/VendaDetailSidebar.vue';
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
    Search,
    X,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    vendas: { type: Object, default: () => ({ data: [], links: [] }) },
    stats: { type: Object, default: () => ({}) },
    status_filter: { type: String, default: 'todas' },
    filters: { type: Object, default: () => ({}) },
    products: { type: Array, default: () => [] },
    offers: { type: Array, default: () => [] },
});

const vendasList = computed(() => props.vendas?.data ?? props.vendas ?? []);

const valuesVisible = ref(true);
const sidebarOpen = ref(false);
const selectedVenda = ref(null);
const openMenuId = ref(null);
const menuAnchorEl = ref(null);
const menuEl = ref(null);
const menuPos = ref({ top: 0, left: 0 });
const resendingId = ref(null);
const approvingId = ref(null);
const toast = ref({ message: null, type: null });
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

function displayCurrency(value) {
    return valuesVisible.value ? formatBRL(value) : '••••••';
}

function displayNumber(value) {
    return valuesVisible.value ? String(value) : '—';
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
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        applyFilters();
        searchTimer = null;
    }, 350);
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
</script>

<template>
    <div class="space-y-6">
        <VendasTabs />

        <!-- Cards de métricas -->
        <div class="space-y-3">
            <div class="flex justify-end">
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
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div
                    class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
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
                    class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
                >
                    <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                        <CircleDollarSign class="h-5 w-5" />
                        <span class="text-sm font-medium">Valor líquido</span>
                    </div>
                    <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                        {{ displayCurrency(stats.valor_liquido ?? 0) }}
                    </p>
                </div>
                <div
                    class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
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
                    class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
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
        <div class="flex flex-wrap items-center justify-between gap-3">
            <nav
                class="inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
                aria-label="Filtrar vendas"
            >
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
            </nav>
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
        <div class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 dark:bg-zinc-800/80">
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
                            Valor líquido
                        </th>
                        <th class="relative w-12 px-2 py-3">
                            <span class="sr-only">Ações</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr
                        v-for="v in vendasList"
                        :key="v.id"
                        class="cursor-pointer bg-white transition hover:bg-zinc-50 dark:bg-zinc-800/60 dark:hover:bg-zinc-700/80"
                        @click="openDetail(v)"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                            {{ new Date(v.created_at).toLocaleDateString('pt-BR') }}
                        </td>
                        <td class="px-4 py-3 text-sm text-zinc-900 dark:text-white">
                            {{ v.product_display_name ?? v.product?.name ?? '–' }}
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
                            {{ formatBRL(v.amount) }}
                        </td>
                        <td class="relative whitespace-nowrap px-2 py-3" @click.stop>
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
                        </td>
                    </tr>
                    <tr v-if="!vendasList.length" class="dark:bg-zinc-800/60">
                        <td colspan="6" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                            Nenhuma venda encontrada.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <nav
            v-if="vendas?.links?.length > 3"
            class="flex items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in vendas.links"
                :key="link.label"
                :href="link.url"
                :aria-current="link.active ? 'page' : undefined"
                :aria-disabled="!link.url"
                :class="[
                    'relative inline-flex items-center rounded-lg px-3 py-2 text-sm font-medium transition',
                    link.active
                        ? 'z-10 bg-[var(--color-primary)] text-white'
                        : link.url
                          ? 'text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-700'
                          : 'cursor-not-allowed text-zinc-400 dark:text-zinc-500',
                ]"
                v-html="link.label"
                @click.prevent="link.url && router.visit(link.url, { preserveState: true })"
            />
        </nav>

        <!-- Sidebar de detalhes -->
        <VendaDetailSidebar
            :open="sidebarOpen"
            :venda="selectedVenda"
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
                    type="button"
                    class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-zinc-700 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800 disabled:opacity-50 disabled:cursor-not-allowed"
                    :disabled="resendingId === openMenuId || menuVenda.status === 'pending'"
                    title="Indisponível para pagamentos pendentes"
                    @click="resendEmail(menuVenda)"
                >
                    <Mail class="h-4 w-4 shrink-0" />
                    {{ resendingId === openMenuId ? 'Enviando...' : 'Reenviar e-mail de compra' }}
                </button>
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
