<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import ProdutosTabs from '@/components/produtos/ProdutosTabs.vue';
import AlunoDetailSidebar from '@/components/alunos/AlunoDetailSidebar.vue';
import Button from '@/components/ui/Button.vue';
import Checkbox from '@/components/ui/Checkbox.vue';
import { Users, BookOpen, Package, UserPlus, Plus, ChevronDown, X, Upload, Download, Search } from 'lucide-vue-next';
import axios from 'axios';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    alunos: { type: [Array, Object], default: () => [] },
    produtos: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    filter: { type: String, default: 'todos' },
    product_ids_filter: { type: Array, default: () => [] },
    q: { type: String, default: '' },
});

const sidebarOpen = ref(false);
const selectedAluno = ref(null);
const novoAlunoModalOpen = ref(false);
const importModalOpen = ref(false);
const productFilterOpen = ref(false);
const novoAlunoForm = ref({
    name: '',
    email: '',
    password: '',
    product_ids: [],
    send_access_email: true,
});
const savingNovo = ref(false);
const importForm = ref({ file: null, product_ids: [], send_access_email: true });
const importing = ref(false);
const toast = ref({ message: null, type: null });
let toastTimer = null;
let searchTimer = null;

const search = ref(props.q ?? '');

const filterOptions = [
    { value: 'todos', label: 'Todos' },
    { value: 'novos_30', label: 'Novos 30 dias' },
];

const alunosList = computed(() => props.alunos?.data ?? (Array.isArray(props.alunos) ? props.alunos : []));

const selectedProdutosLabels = computed(() => {
    const ids = props.product_ids_filter;
    return props.produtos.filter((p) => ids.includes(p.id)).map((p) => ({ id: p.id, name: p.name }));
});

function setFilter(value) {
    applyQuery({ filter: value });
}

function setProductFilter(ids) {
    applyQuery({ product_ids: ids });
}

function buildQuery(overrides = {}) {
    const q = {
        filter: props.filter,
        product_ids: props.product_ids_filter,
        q: search.value,
        ...overrides,
    };

    const cleaned = {};
    Object.entries(q).forEach(([k, v]) => {
        if (v === null || v === undefined) return;
        if (Array.isArray(v) && v.length === 0) return;
        if (typeof v === 'string' && v.trim() === '') return;
        cleaned[k] = v;
    });
    return cleaned;
}

function applyQuery(overrides = {}) {
    router.get('/produtos/alunos', buildQuery(overrides), { preserveState: false, preserveScroll: true, replace: true });
}

function onSearchInput() {
    if (searchTimer) clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        applyQuery();
        searchTimer = null;
    }, 350);
}

function toggleProductFilter(id) {
    const current = [...(props.product_ids_filter ?? [])];
    const idx = current.indexOf(id);
    if (idx >= 0) {
        current.splice(idx, 1);
    } else {
        current.push(id);
    }
    setProductFilter(current);
}

function removeProductFilter(id) {
    const current = [...(props.product_ids_filter ?? [])].filter((x) => x !== id);
    setProductFilter(current);
}

function openDetail(a) {
    selectedAluno.value = a;
    sidebarOpen.value = true;
}

function closeSidebar() {
    sidebarOpen.value = false;
    selectedAluno.value = null;
}

function handleAlunoUpdated(updated) {
    const list = [...alunosList.value];
    const idx = list.findIndex((a) => a.id === updated.id);
    if (idx >= 0) {
        list[idx] = { ...list[idx], ...updated };
        router.reload({ only: ['alunos'], preserveState: false });
    }
}

function handleAlunoDeleted(id) {
    closeSidebar();
    router.reload({ only: ['alunos', 'stats'], preserveState: false });
}

function openNovoAluno() {
    novoAlunoForm.value = { name: '', email: '', password: '', product_ids: [], send_access_email: true };
    novoAlunoModalOpen.value = true;
}

function closeNovoAluno() {
    novoAlunoModalOpen.value = false;
}

function openImportModal() {
    importForm.value = { file: null, product_ids: [], send_access_email: true };
    importModalOpen.value = true;
}

function closeImportModal() {
    importModalOpen.value = false;
}

function onImportFileChange(e) {
    const f = e.target?.files?.[0];
    importForm.value.file = f || null;
}

function toggleImportProduct(id) {
    const ids = importForm.value.product_ids;
    if (ids.includes(id)) {
        importForm.value.product_ids = ids.filter((x) => x !== id);
    } else {
        importForm.value.product_ids = [...ids, id];
    }
}

async function saveImport() {
    if (!importForm.value.file) {
        showToast('Selecione um arquivo CSV.', 'error');
        return;
    }
    if (!importForm.value.product_ids?.length) {
        showToast('Selecione ao menos um produto para dar acesso.', 'error');
        return;
    }
    importing.value = true;
    try {
        const formData = new FormData();
        formData.append('file', importForm.value.file);
        formData.append('send_access_email', importForm.value.send_access_email ? '1' : '0');
        importForm.value.product_ids.forEach((id) => formData.append('product_ids[]', id));

        const { data } = await axios.post('/produtos/alunos/import', formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        showToast(data.message ?? 'Importação concluída.', 'success');
        if (data.errors?.length) {
            showToast(data.errors.slice(0, 3).join(' '), 'error');
        }
        closeImportModal();
        router.reload({ only: ['alunos', 'stats'], preserveState: false });
    } catch (err) {
        showToast(
            err.response?.data?.message ?? err.response?.data?.errors?.file?.[0] ?? 'Erro na importação. Verifique o formato do CSV.',
            'error'
        );
    } finally {
        importing.value = false;
    }
}

function toggleNovoProduct(id) {
    const ids = novoAlunoForm.value.product_ids;
    if (ids.includes(id)) {
        novoAlunoForm.value.product_ids = ids.filter((x) => x !== id);
    } else {
        novoAlunoForm.value.product_ids = [...ids, id];
    }
}

async function saveNovoAluno() {
    if (!novoAlunoForm.value.name?.trim() || !novoAlunoForm.value.email?.trim() || !novoAlunoForm.value.password) {
        showToast('Preencha nome, e-mail e senha.', 'error');
        return;
    }
    savingNovo.value = true;
    try {
        const { data } = await axios.post('/produtos/alunos', {
            ...novoAlunoForm.value,
            send_access_email: novoAlunoForm.value.send_access_email ?? true,
        });
        showToast(data.message ?? 'Aluno cadastrado com sucesso.', 'success');
        closeNovoAluno();
        router.reload({ only: ['alunos', 'stats'], preserveState: false });
    } catch (err) {
        showToast(
            err.response?.data?.message ?? err.response?.data?.errors?.email?.[0] ?? 'Erro ao cadastrar. Tente novamente.',
            'error'
        );
    } finally {
        savingNovo.value = false;
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

function handleClickOutside(event) {
    if (productFilterOpen.value) {
        const el = document.querySelector('[data-product-filter]');
        if (el && !el.contains(event.target)) {
            productFilterOpen.value = false;
        }
    }
}

function displayNumber(value) {
    return String(value ?? 0);
}

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
    if (toastTimer) clearTimeout(toastTimer);
    if (searchTimer) clearTimeout(searchTimer);
});
</script>

<template>
    <div class="space-y-6">
        <ProdutosTabs />

        <!-- Cards de métricas -->
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
            >
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <Users class="h-5 w-5" />
                    <span class="text-sm font-medium">Total de alunos</span>
                </div>
                <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ displayNumber(stats.total_alunos) }}
                </p>
            </div>
            <div
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
            >
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <BookOpen class="h-5 w-5" />
                    <span class="text-sm font-medium">Total de inscrições</span>
                </div>
                <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ displayNumber(stats.total_inscricoes) }}
                </p>
            </div>
            <div
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
            >
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <Package class="h-5 w-5" />
                    <span class="text-sm font-medium">Produtos com alunos</span>
                </div>
                <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ displayNumber(stats.produtos_ativos) }}
                </p>
            </div>
            <div
                class="rounded-xl border border-zinc-200 bg-zinc-50 p-5 dark:border-zinc-700 dark:bg-zinc-800/50"
            >
                <div class="flex items-center gap-2 text-zinc-600 dark:text-zinc-400">
                    <UserPlus class="h-5 w-5" />
                    <span class="text-sm font-medium">Novos (30 dias)</span>
                </div>
                <p class="mt-2 text-2xl font-bold text-zinc-900 dark:text-white">
                    {{ displayNumber(stats.alunos_novos_30dias) }}
                </p>
            </div>
        </div>

        <!-- Abas de filtro + Filtro por produto + Novo aluno -->
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex flex-col flex-wrap gap-3 sm:flex-row sm:flex-nowrap sm:items-center">
                <nav
                    class="inline-flex rounded-xl bg-zinc-100/80 p-1 dark:bg-zinc-800/80"
                    aria-label="Filtrar alunos"
                >
                    <button
                        v-for="opt in filterOptions"
                        :key="opt.value"
                        type="button"
                        :aria-current="filter === opt.value ? 'true' : undefined"
                        :class="[
                            'rounded-lg px-4 py-2.5 text-sm font-medium transition-all duration-200',
                            filter === opt.value
                                ? 'bg-white text-[var(--color-primary)] shadow-sm dark:bg-zinc-700 dark:text-[var(--color-primary)]'
                                : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white',
                        ]"
                        @click="setFilter(opt.value)"
                    >
                        {{ opt.label }}
                    </button>
                </nav>
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                    <input
                        v-model="search"
                        type="text"
                        class="w-full rounded-xl border border-zinc-200 bg-white py-2 pl-10 pr-10 text-sm text-zinc-900 shadow-sm transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white"
                        placeholder="Buscar aluno por nome ou e-mail..."
                        @input="onSearchInput"
                    />
                    <button
                        v-if="search"
                        type="button"
                        class="absolute right-2 top-1/2 -translate-y-1/2 rounded-lg p-2 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                        aria-label="Limpar busca"
                        @click="search = ''; applyQuery()"
                    >
                        <X class="h-4 w-4" />
                    </button>
                </div>
                <div class="relative shrink-0" data-product-filter>
                    <button
                        type="button"
                        class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                        :class="product_ids_filter?.length ? 'border-[var(--color-primary)] text-[var(--color-primary)] dark:border-[var(--color-primary)] dark:text-[var(--color-primary)]' : ''"
                        aria-expanded="productFilterOpen"
                        @click="productFilterOpen = !productFilterOpen"
                    >
                        <Package class="h-4 w-4 shrink-0" />
                        Produtos
                        <span v-if="product_ids_filter?.length" class="ml-1 rounded-full bg-[var(--color-primary)]/20 px-1.5 py-0.5 text-xs">
                            {{ product_ids_filter.length }}
                        </span>
                        <ChevronDown class="h-4 w-4 shrink-0" :class="productFilterOpen && 'rotate-180'" />
                    </button>
                    <div
                        v-show="productFilterOpen"
                        class="absolute left-0 top-full z-50 mt-1 max-h-64 w-64 overflow-y-auto rounded-xl border border-zinc-200 bg-white py-1 text-left shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                    >
                        <div v-for="p in produtos" :key="p.id" class="px-2 py-1">
                            <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/80">
                                <span class="shrink-0 w-fit">
                                    <Checkbox
                                        :model-value="product_ids_filter?.includes(p.id)"
                                        @update:model-value="toggleProductFilter(p.id)"
                                    />
                                </span>
                                <span class="flex-1 text-left text-sm text-zinc-900 dark:text-white">{{ p.name }}</span>
                            </label>
                        </div>
                        <p v-if="!produtos.length" class="px-3 py-2 text-sm text-zinc-500">
                            Nenhum produto
                        </p>
                    </div>
                </div>
                <div v-if="selectedProdutosLabels.length" class="flex w-full flex-wrap justify-start gap-1.5 sm:w-auto">
                    <span
                        v-for="p in selectedProdutosLabels"
                        :key="p.id"
                        class="inline-flex items-center gap-1 rounded-lg bg-zinc-100 px-2 py-1 text-xs font-medium text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300"
                    >
                        {{ p.name }}
                        <button
                            type="button"
                            class="rounded p-0.5 hover:bg-zinc-200 dark:hover:bg-zinc-600"
                            aria-label="Remover filtro"
                            @click="removeProductFilter(p.id)"
                        >
                            <X class="h-3 w-3" />
                        </button>
                    </span>
                </div>
            </div>
            <div class="flex gap-2">
                <Button variant="outline" @click="openImportModal">
                    <Upload class="h-4 w-4" />
                    Importar
                </Button>
                <Button variant="primary" @click="openNovoAluno">
                    <Plus class="h-4 w-4" />
                    Novo aluno
                </Button>
            </div>
        </div>

        <!-- Tabela de alunos -->
        <div
            class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 dark:bg-zinc-800/80"
        >
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Nome
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            E-mail
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Produtos
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr
                        v-for="a in alunosList"
                        :key="a.id"
                        class="cursor-pointer bg-white transition hover:bg-zinc-50 dark:bg-zinc-800/60 dark:hover:bg-zinc-700/80"
                        @click="openDetail(a)"
                    >
                        <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-white">
                            {{ a.name }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                            {{ a.email }}
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-300">
                            {{ a.products_count ?? 0 }}
                        </td>
                    </tr>
                    <tr v-if="!alunosList.length" class="dark:bg-zinc-800/60">
                        <td colspan="3" class="px-4 py-12 text-center text-zinc-500 dark:text-zinc-400">
                            Nenhum aluno com acesso ainda.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Paginação -->
        <nav
            v-if="alunos?.links?.length > 3"
            class="flex items-center justify-center gap-2"
            aria-label="Paginação"
        >
            <a
                v-for="link in alunos.links"
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

        <!-- Sidebar detalhes -->
        <AlunoDetailSidebar
            :open="sidebarOpen"
            :aluno="selectedAluno"
            :produtos="produtos"
            @close="closeSidebar"
            @updated="handleAlunoUpdated"
            @deleted="handleAlunoDeleted"
        />

        <!-- Modal Novo aluno -->
        <Teleport to="body">
            <div
                v-show="novoAlunoModalOpen"
                class="fixed inset-0 z-[100001] flex items-center justify-center p-4"
                aria-modal="true"
                role="dialog"
            >
                <div
                    class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                    @click="closeNovoAluno"
                />
                <div
                    class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <h3 class="mb-5 text-lg font-semibold text-zinc-900 dark:text-white">
                        Cadastrar novo aluno
                    </h3>
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Nome
                            </label>
                            <input
                                v-model="novoAlunoForm.name"
                                type="text"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                placeholder="Nome do aluno"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                E-mail
                            </label>
                            <input
                                v-model="novoAlunoForm.email"
                                type="email"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                placeholder="email@exemplo.com"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Senha
                            </label>
                            <input
                                v-model="novoAlunoForm.password"
                                type="password"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                placeholder="Mínimo 6 caracteres"
                            />
                        </div>
                        <div class="space-y-2">
                            <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-left">
                                <span class="shrink-0 w-fit">
                                    <Checkbox :model-value="novoAlunoForm.send_access_email" @update:model-value="novoAlunoForm.send_access_email = $event" />
                                </span>
                                <span class="flex-1 text-left text-sm text-zinc-900 dark:text-white">Enviar e-mail de acesso ao criar</span>
                            </label>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Produtos com acesso (opcional)
                            </p>
                            <div class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <label
                                    v-for="p in produtos"
                                    :key="p.id"
                                    class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                >
                                    <span class="shrink-0 w-fit">
                                        <Checkbox
                                            :model-value="novoAlunoForm.product_ids.includes(p.id)"
                                            @update:model-value="(v) => { if (v) novoAlunoForm.product_ids = [...novoAlunoForm.product_ids, p.id]; else novoAlunoForm.product_ids = novoAlunoForm.product_ids.filter(x => x !== p.id); }"
                                        />
                                    </span>
                                    <span class="flex-1 text-left text-sm text-zinc-900 dark:text-white">{{ p.name }}</span>
                                </label>
                                <p v-if="!produtos.length" class="text-sm text-zinc-500">Nenhum produto disponível</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline" :disabled="savingNovo" @click="closeNovoAluno">
                            Cancelar
                        </Button>
                        <Button variant="primary" :disabled="savingNovo" @click="saveNovoAluno">
                            Cadastrar
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Modal Importar -->
        <Teleport to="body">
            <div
                v-show="importModalOpen"
                class="fixed inset-0 z-[100001] flex items-center justify-center p-4"
                aria-modal="true"
                role="dialog"
            >
                <div class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60" @click="closeImportModal" />
                <div
                    class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
                >
                    <h3 class="mb-5 text-lg font-semibold text-zinc-900 dark:text-white">
                        Importar alunos em massa
                    </h3>
                    <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                        Envie um arquivo CSV com as colunas: <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">nome</code>, <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">email</code>, <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">senha</code> (opcional). Use <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">;</code> ou <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">,</code> como separador.
                    </p>
                    <a
                        href="/produtos/alunos/import-example"
                        download
                        class="mb-4 inline-flex items-center gap-2 text-sm text-[var(--color-primary)] hover:underline"
                    >
                        <Download class="h-4 w-4 shrink-0" />
                        Baixar CSV de exemplo
                    </a>
                    <div class="space-y-4">
                        <div class="space-y-2">
                            <label class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Arquivo CSV
                            </label>
                            <input
                                type="file"
                                accept=".csv,.txt"
                                class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                @change="onImportFileChange"
                            />
                            <p v-if="importForm.file" class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ importForm.file.name }}
                            </p>
                        </div>
                        <div class="space-y-2">
                            <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-left">
                                <span class="shrink-0 w-fit">
                                    <Checkbox :model-value="importForm.send_access_email" @update:model-value="importForm.send_access_email = $event" />
                                </span>
                                <span class="flex-1 text-left text-sm text-zinc-900 dark:text-white">Enviar e-mail de acesso aos importados</span>
                            </label>
                        </div>
                        <div class="space-y-2">
                            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                Produtos para dar acesso (obrigatório)
                            </p>
                            <div class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <label
                                    v-for="p in produtos"
                                    :key="p.id"
                                    class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-left hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
                                >
                                    <span class="shrink-0 w-fit">
                                        <Checkbox
                                            :model-value="importForm.product_ids.includes(p.id)"
                                            @update:model-value="(v) => { if (v) importForm.product_ids = [...importForm.product_ids, p.id]; else importForm.product_ids = importForm.product_ids.filter(x => x !== p.id); }"
                                        />
                                    </span>
                                    <span class="flex-1 text-left text-sm text-zinc-900 dark:text-white">{{ p.name }}</span>
                                </label>
                                <p v-if="!produtos.length" class="text-sm text-zinc-500">Nenhum produto disponível</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end gap-2">
                        <Button variant="outline" :disabled="importing" @click="closeImportModal">
                            Cancelar
                        </Button>
                        <Button variant="primary" :disabled="importing" @click="saveImport">
                            Importar
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Toast -->
        <Teleport to="body">
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
                        'fixed bottom-4 right-4 z-[100002] max-w-sm rounded-xl border px-4 py-3 shadow-lg',
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
