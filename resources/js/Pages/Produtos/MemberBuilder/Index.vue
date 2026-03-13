<script setup>
import { ref, computed, reactive, onMounted, onUnmounted } from 'vue';
import { useForm, Link, router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import MemberBuilderPreview from '@/components/member-builder/MemberBuilderPreview.vue';

defineOptions({ layout: null });

const BODY_CLASS = 'member-builder-active';
onMounted(() => document.body.classList.add(BODY_CLASS));
onUnmounted(() => document.body.classList.remove(BODY_CLASS));
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import {
    Palette,
    LayoutList,
    LogIn,
    Layers,
    ShoppingBag,
    Users,
    MessageSquare,
    Globe,
    Award,
    Smartphone,
    Bell,
    Plus,
    Trash2,
    ChevronDown,
    ExternalLink,
    X,
    Trophy,
    Lock,
    Unlock,
    Clock,
} from 'lucide-vue-next';

const props = defineProps({
    produto: { type: Object, required: true },
    tenant_products: { type: Array, default: () => [] },
    dns_target_host: { type: String, default: null },
    dns_target_ip: { type: String, default: null },
    app_url: { type: String, default: '' },
});

const activeTab = ref('aparencia');

const configForm = useForm({
    member_area_config: {
        theme: { ...props.produto.member_area_config?.theme },
        hero: { ...props.produto.member_area_config?.hero },
        logos: { favicon: '', ...props.produto.member_area_config?.logos },
        sidebar: { ...props.produto.member_area_config?.sidebar },
        login: {
            title: '',
            subtitle: '',
            primary_color: '#0ea5e9',
            background_color: '#18181b',
            logo: '',
            background_image: '',
            password_mode: props.produto.member_area_config?.login?.password_mode ?? 'auto',
            default_password: props.produto.member_area_config?.login?.default_password ?? '',
            login_without_password: props.produto.member_area_config?.login?.login_without_password ?? false,
            ...props.produto.member_area_config?.login,
        },
        pwa: { name: '', short_name: '', theme_color: '#0ea5e9', push_enabled: false, ...props.produto.member_area_config?.pwa },
        certificate: { ...props.produto.member_area_config?.certificate },
        community_enabled: props.produto.member_area_config?.community_enabled ?? false,
        gamification: { enabled: false, achievements: [], ...props.produto.member_area_config?.gamification },
    },
    domain_type: props.produto.member_area_domain?.type ?? 'path',
    domain_value: props.produto.member_area_domain?.value ?? props.produto.checkout_slug ?? '',
});

const base = computed(() => `/produtos/${props.produto.id}/member-builder`);

const loginAccessMode = computed({
    get() {
        const login = configForm.member_area_config?.login ?? {};
        return login.login_without_password ? 'email_only' : (login.password_mode || 'auto');
    },
    set(value) {
        configForm.member_area_config.login.login_without_password = value === 'email_only';
        configForm.member_area_config.login.password_mode = value === 'email_only' ? 'auto' : value;
    },
});

const pushSubscribersCount = computed(() => props.produto.push_subscribers_count ?? null);
const pushForm = reactive({ title: '', body: '' });
const pushSending = ref(false);
const pushSendResult = ref(null);
const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
async function sendPushNotification() {
    if (pushSending.value || !pushForm.title.trim() || !pushForm.body.trim()) return;
    pushSendResult.value = null;
    pushSending.value = true;
    try {
        const { data } = await axios.post(
            `${base.value}/send-push`,
            { title: pushForm.title.trim(), body: pushForm.body.trim() },
            { headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } }
        );
        pushSendResult.value = { success: true, message: data?.message ?? `Notificação enviada para ${data?.sent ?? 0} destinatário(s).` };
        pushForm.title = '';
        pushForm.body = '';
    } catch (e) {
        const msg = e.response?.data?.message ?? e.response?.data?.errors?.title?.[0] ?? e.message ?? 'Erro ao enviar.';
        pushSendResult.value = { success: false, message: msg };
    } finally {
        pushSending.value = false;
    }
}

const page = usePage();
const baseUrlForLink = computed(() => props.app_url || (page.props.app_url ?? '') || (typeof window !== 'undefined' ? window.location.origin : '') || '');
const memberAreaFullLink = computed(() => {
    const type = configForm.domain_type;
    const val = String(configForm.domain_value || props.produto.checkout_slug || '').trim().toLowerCase();
    const base = baseUrlForLink.value;
    const protocol = base.startsWith('https') ? 'https' : 'http';

    if (type === 'path') {
        const seg = val.replace(/[^a-z0-9]/g, '') || props.produto.checkout_slug;
        return seg ? `${base}/m/${seg}` : `${base}/m/${props.produto.checkout_slug}`;
    }
    if (type === 'custom' && val) {
        const host = val.replace(/^https?:\/\//, '').split('/')[0].trim();
        return host ? `${protocol}://${host}` : '';
    }
    return props.produto.member_area_url || `${base}/m/${props.produto.checkout_slug}`;
});

const tabs = [
    { id: 'aparencia', label: 'Aparência', icon: Palette, hasPreview: true, previewMode: 'area' },
    { id: 'sidebar', label: 'Sidebar', icon: LayoutList, hasPreview: true, previewMode: 'sidebar' },
    { id: 'login', label: 'Login', icon: LogIn, hasPreview: true, previewMode: 'login' },
    { id: 'modulos', label: 'Módulos', icon: Layers, hasPreview: false },
    { id: 'loja', label: 'Loja interna', icon: ShoppingBag, hasPreview: false },
    { id: 'turmas', label: 'Turmas', icon: Users, hasPreview: false },
    { id: 'comentarios', label: 'Comentários', icon: MessageSquare, hasPreview: false },
    { id: 'comunidade', label: 'Comunidade', icon: Globe, hasPreview: false },
    { id: 'certificado', label: 'Certificado', icon: Award, hasPreview: false },
    { id: 'gamificacao', label: 'Gamificação', icon: Trophy, hasPreview: false },
    { id: 'pwa', label: 'PWA e URL', icon: Smartphone, hasPreview: false },
];

const currentTab = computed(() => tabs.find((t) => t.id === activeTab.value));
const showPreview = computed(() => currentTab.value?.hasPreview ?? false);
const previewMode = computed(() => currentTab.value?.previewMode ?? 'area');

function saveConfig() {
    const payload = {
        member_area_config: configForm.member_area_config,
    };
    // Evita bloquear salvamento de outras abas por validação de domínio.
    if (activeTab.value === 'pwa') {
        payload.domain_type = configForm.domain_type ?? 'path';
        payload.domain_value = configForm.domain_value ?? '';
    }

    configForm.transform(() => ({ ...payload, _method: 'PUT' })).post(`${base.value}/config`, {
        preserveScroll: true,
        onSuccess: () => (activeTab.value = activeTab.value),
    });
}

function addSection() {
    const title = prompt('Título da seção:');
    if (!title) return;
    router.post(`${base.value}/sections`, { title }, { preserveScroll: true });
}
function deleteSection(sectionId) {
    if (!confirm('Remover esta seção e todo o conteúdo?')) return;
    router.delete(`${base.value}/sections/${sectionId}`, { preserveScroll: true });
}
function addModule(sectionId) {
    const title = prompt('Título do módulo:');
    if (!title) return;
    router.post(`${base.value}/sections/${sectionId}/modules`, { title }, { preserveScroll: true });
}
function deleteModule(moduleId) {
    if (!confirm('Remover este módulo e todas as aulas?')) return;
    router.delete(`${base.value}/modules/${moduleId}`, { preserveScroll: true });
}
function addLesson(moduleId) {
    const title = prompt('Título da aula:');
    if (!title) return;
    router.post(`${base.value}/modules/${moduleId}/lessons`, { title, type: 'video', content_url: '', watermark_enabled: false }, { preserveScroll: true });
}
function deleteLesson(lessonId) {
    if (!confirm('Remover esta aula?')) return;
    router.delete(`${base.value}/lessons/${lessonId}`, { preserveScroll: true });
}
function addInternalProduct() {
    const id = prompt('ID do produto relacionado:');
    if (!id) return;
    const relatedId = parseInt(id, 10);
    if (!relatedId) return;
    router.post(`${base.value}/internal-products`, { related_product_id: relatedId }, { preserveScroll: true });
}
function removeInternalProduct(internalProductId) {
    router.delete(`${base.value}/internal-products/${internalProductId}`, { preserveScroll: true });
}
function addTurma() {
    const name = prompt('Nome da turma:');
    if (!name) return;
    router.post(`${base.value}/turmas`, { name }, { preserveScroll: true });
}
function deleteTurma(turmaId) {
    if (!confirm('Remover esta turma?')) return;
    router.delete(`${base.value}/turmas/${turmaId}`, { preserveScroll: true });
}
function addCommunityPage() {
    const title = prompt('Título da página:');
    if (!title) return;
    router.post(`${base.value}/community-pages`, { title, is_public_posting: true }, { preserveScroll: true });
}
function deleteCommunityPage(pageId) {
    if (!confirm('Remover esta página e todos os posts?')) return;
    router.delete(`${base.value}/community-pages/${pageId}`, { preserveScroll: true });
}

const inputClass = 'block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200';

// ─── Drip Content ──────────────────────────────────────────
function updateModuleDrip(moduleId, days) {
    const value = Math.max(0, Math.min(365, parseInt(days, 10) || 0));
    axios.put(`${base.value}/modules/${moduleId}`, { release_after_days: value }, {
        headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
    }).catch(() => {});
}

const unlockModal = reactive({ open: false, moduleId: null, moduleTitle: '', unlocks: [], loading: false });
const unlockForm = reactive({ user_id: '' });

async function openUnlockModal(mod) {
    unlockModal.moduleId = mod.id;
    unlockModal.moduleTitle = mod.title;
    unlockModal.open = true;
    unlockModal.loading = true;
    unlockForm.user_id = '';
    try {
        const { data } = await axios.get(`${base.value}/modules/${mod.id}/unlocks`, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        unlockModal.unlocks = data.unlocks ?? [];
    } catch {
        unlockModal.unlocks = [];
    } finally {
        unlockModal.loading = false;
    }
}

async function doUnlock() {
    if (!unlockForm.user_id) return;
    try {
        await axios.post(`${base.value}/modules/${unlockModal.moduleId}/unlock`, { user_id: unlockForm.user_id }, {
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        unlockForm.user_id = '';
        await openUnlockModal({ id: unlockModal.moduleId, title: unlockModal.moduleTitle });
    } catch (e) {
        alert(e.response?.data?.message ?? 'Erro ao desbloquear.');
    }
}

async function doLock(userId) {
    if (!confirm('Remover desbloqueio manual?')) return;
    try {
        await axios.delete(`${base.value}/modules/${unlockModal.moduleId}/unlock/${userId}`, {
            headers: { 'X-CSRF-TOKEN': csrfToken(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        await openUnlockModal({ id: unlockModal.moduleId, title: unlockModal.moduleTitle });
    } catch {
        alert('Erro ao remover desbloqueio.');
    }
}
</script>

<template>
    <Teleport to="body">
    <div
        class="fixed inset-0 z-[100010] flex flex-col bg-zinc-100 dark:bg-zinc-950"
        style="pointer-events: auto"
    >
        <!-- Header: abas + fechar -->
        <header class="flex h-14 shrink-0 items-center justify-between border-b border-zinc-200 bg-white px-4 dark:border-zinc-800 dark:bg-zinc-900">
            <nav class="flex flex-1 items-center gap-1 overflow-x-auto">
                <button
                    v-for="tab in tabs"
                    :key="tab.id"
                    type="button"
                    :class="[
                        'flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition',
                        activeTab === tab.id
                            ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                            : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-100',
                    ]"
                    @click="activeTab = tab.id"
                >
                    <component :is="tab.icon" class="h-4 w-4 shrink-0" />
                    {{ tab.label }}
                </button>
            </nav>
            <div class="flex shrink-0 items-center gap-2">
                <a
                    :href="produto.member_area_url"
                    target="_blank"
                    rel="noopener"
                    class="hidden items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 sm:flex"
                >
                    <ExternalLink class="h-4 w-4" />
                    Ver área
                </a>
                <Link
                    :href="`/produtos/${produto.id}/edit?tab=geral`"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-200 hover:text-zinc-900 dark:hover:bg-zinc-700 dark:hover:text-zinc-100"
                    title="Fechar Member Builder"
                >
                    <X class="h-5 w-5" />
                </Link>
            </div>
        </header>

        <!-- Conteúdo: sidebar config + preview -->
        <div :class="['flex min-h-0 flex-1 flex-col overflow-hidden', showPreview ? 'lg:flex-row' : '']">
        <!-- Painel de configuração (esquerda) -->
        <aside
            :class="[
                'min-h-0 shrink-0 overflow-y-auto border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900',
                showPreview ? 'lg:w-80 lg:border-b-0 lg:border-r' : 'flex-1 w-full min-w-0',
            ]"
        >
            <div class="p-4">
                <!-- Aparência -->
                <template v-if="activeTab === 'aparencia'">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tema e hero</h2>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Favicon (ícone da aba do navegador)</label>
                            <input v-model="configForm.member_area_config.logos.favicon" type="url" :class="inputClass" class="w-full" placeholder="https://... ou /storage/..." />
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">URL do ícone. Usado na aba do navegador e no PWA. 192×192 ou 512×512 px.</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor primária</label>
                            <input v-model="configForm.member_area_config.theme.primary" type="color" class="h-9 w-full cursor-pointer rounded-lg border dark:border-zinc-600" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Fundo</label>
                            <input v-model="configForm.member_area_config.theme.background" type="color" class="h-9 w-full cursor-pointer rounded-lg border dark:border-zinc-600" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título do hero</label>
                            <input v-model="configForm.member_area_config.hero.title" type="text" :class="inputClass" placeholder="Título" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Subtítulo</label>
                            <input v-model="configForm.member_area_config.hero.subtitle" type="text" :class="inputClass" placeholder="Subtítulo" />
                        </div>
                    </div>
                    <Button type="button" class="mt-4" @click="saveConfig" :disabled="configForm.processing">Salvar</Button>
                </template>

                <!-- Sidebar -->
                <template v-else-if="activeTab === 'sidebar'">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Menu da sidebar</h2>
                    <div class="space-y-4">
                        <Toggle v-model="configForm.member_area_config.sidebar.collapsible" label="Sidebar colapsável" />
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Itens do menu em sidebar.items (edição avançada).</p>
                    </div>
                    <Button type="button" class="mt-4" @click="saveConfig" :disabled="configForm.processing">Salvar</Button>
                </template>

                <!-- Login -->
                <template v-else-if="activeTab === 'login'">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tela de login</h2>
                    <div class="space-y-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Logo (URL)</label>
                            <input v-model="configForm.member_area_config.login.logo" type="url" :class="inputClass" placeholder="https://..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Imagem de fundo (URL)</label>
                            <input v-model="configForm.member_area_config.login.background_image" type="url" :class="inputClass" placeholder="https://..." />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor de fundo (sem imagem)</label>
                            <div class="flex items-center gap-2">
                                <input v-model="configForm.member_area_config.login.background_color" type="color" class="h-9 w-20 cursor-pointer rounded-lg border dark:border-zinc-600" />
                                <input v-model="configForm.member_area_config.login.background_color" type="text" :class="inputClass" class="font-mono text-sm" placeholder="#18181b" />
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título</label>
                            <input v-model="configForm.member_area_config.login.title" type="text" :class="inputClass" placeholder="Área de Membros" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Subtítulo</label>
                            <input v-model="configForm.member_area_config.login.subtitle" type="text" :class="inputClass" placeholder="Entre com seu e-mail e senha" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor primária (botão e links)</label>
                            <input v-model="configForm.member_area_config.login.primary_color" type="color" class="h-9 w-full cursor-pointer rounded-lg border dark:border-zinc-600" />
                        </div>
                        <div class="border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <label class="mb-2 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Definir senha (novos acessos)</label>
                            <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">Escolha apenas uma opção.</p>
                            <div class="space-y-2">
                                <label class="flex items-center gap-2">
                                    <input v-model="loginAccessMode" type="radio" value="auto" class="rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                                    <span class="text-sm">Gerada automaticamente (aleatória) — enviada no e-mail de acesso</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input v-model="loginAccessMode" type="radio" value="default" class="rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                                    <span class="text-sm">Senha padrão — todos os novos acessos usam a mesma senha</span>
                                </label>
                                <label class="flex items-center gap-2">
                                    <input v-model="loginAccessMode" type="radio" value="email_only" class="rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                                    <span class="text-sm">Permitir login apenas com e-mail (menos seguro) — campo de senha não é exibido</span>
                                </label>
                            </div>
                            <div v-if="loginAccessMode === 'default'" class="mt-3">
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Senha padrão</label>
                                <input v-model="configForm.member_area_config.login.default_password" type="password" autocomplete="new-password" :class="inputClass" placeholder="Digite a senha padrão" class="max-w-xs" />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Será usada por todos os alunos ao acessar esta área. Pode ser incluída no e-mail com a variável {senha} no template.</p>
                            </div>
                        </div>
                    </div>
                    <Button type="button" class="mt-4" @click="saveConfig" :disabled="configForm.processing">Salvar</Button>
                </template>

                <!-- Módulos -->
                <template v-else-if="activeTab === 'modulos'">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Seções e módulos</h2>
                        <Button size="sm" @click="addSection"><Plus class="h-4 w-4" /> Seção</Button>
                    </div>
                    <div class="space-y-4">
                        <div v-for="section in produto.sections" :key="section.id" class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                            <div class="mb-2 flex items-center justify-between">
                                <span class="text-sm font-medium">{{ section.title }}</span>
                                <div class="flex gap-1">
                                    <Button size="sm" variant="outline" @click="addModule(section.id)">+ Módulo</Button>
                                    <button type="button" class="p-1 text-red-600 hover:underline" @click="deleteSection(section.id)"><Trash2 class="h-4 w-4" /></button>
                                </div>
                            </div>
                            <div v-for="mod in section.modules" :key="mod.id" class="ml-2 space-y-1 border-l-2 border-zinc-200 pl-2 dark:border-zinc-700">
                                <div class="flex items-center justify-between py-1">
                                    <span class="text-xs">{{ mod.title }}</span>
                                    <div class="flex gap-1">
                                        <Button size="sm" variant="outline" class="!py-1 !text-xs" @click="addLesson(mod.id)">+ Aula</Button>
                                        <button type="button" class="text-red-600 hover:underline" @click="deleteModule(mod.id)"><Trash2 class="h-3 w-3" /></button>
                                    </div>
                                </div>
                                <!-- Drip content: liberar após X dias -->
                                <div v-if="(section.section_type ?? 'courses') === 'courses'" class="flex items-center gap-2 py-1 pl-2">
                                    <Clock class="h-3.5 w-3.5 text-zinc-400 shrink-0" />
                                    <label class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap">Liberar após</label>
                                    <input
                                        type="number"
                                        min="0"
                                        max="365"
                                        :value="mod.release_after_days ?? 0"
                                        class="w-16 rounded border border-zinc-300 bg-white px-2 py-0.5 text-xs dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
                                        @change="(e) => updateModuleDrip(mod.id, e.target.value)"
                                    />
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">dias</span>
                                    <button
                                        v-if="(mod.release_after_days ?? 0) > 0"
                                        type="button"
                                        class="ml-1 flex items-center gap-1 rounded px-1.5 py-0.5 text-xs text-sky-600 hover:bg-sky-50 dark:text-sky-400 dark:hover:bg-sky-900/30"
                                        @click="openUnlockModal(mod)"
                                        title="Gerenciar desbloqueios manuais"
                                    >
                                        <Unlock class="h-3 w-3" /> Desbloqueios
                                    </button>
                                </div>
                                <div v-for="lesson in mod.lessons" :key="lesson.id" class="flex items-center justify-between py-0.5 pl-2 text-xs text-zinc-500">
                                    <span>— {{ lesson.title }}</span>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteLesson(lesson.id)"><Trash2 class="h-3 w-3" /></button>
                                </div>
                            </div>
                        </div>
                        <p v-if="!produto.sections?.length" class="text-xs text-zinc-500">Nenhuma seção. Clique em "Seção".</p>
                    </div>
                </template>

                <!-- Loja -->
                <template v-else-if="activeTab === 'loja'">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase text-zinc-500 dark:text-zinc-400">Loja interna</h2>
                        <Button size="sm" @click="addInternalProduct">Adicionar</Button>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="ip in produto.internal_products" :key="ip.id" class="flex items-center justify-between rounded-lg bg-zinc-50 py-2 px-3 text-sm dark:bg-zinc-800/50">
                            <span>{{ ip.related_product?.name ?? '#' + ip.related_product_id }}</span>
                            <button type="button" class="text-red-600 hover:underline" @click="removeInternalProduct(ip.id)">Remover</button>
                        </li>
                    </ul>
                    <p v-if="!produto.internal_products?.length" class="mt-2 text-xs text-zinc-500">Nenhum produto.</p>
                </template>

                <!-- Turmas -->
                <template v-else-if="activeTab === 'turmas'">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase text-zinc-500 dark:text-zinc-400">Turmas</h2>
                        <Button size="sm" @click="addTurma">Nova turma</Button>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="t in produto.turmas" :key="t.id" class="flex items-center justify-between rounded-lg bg-zinc-50 py-2 px-3 text-sm dark:bg-zinc-800/50">
                            <span>{{ t.name }} ({{ t.users_count }})</span>
                            <button type="button" class="text-red-600 hover:underline" @click="deleteTurma(t.id)">Remover</button>
                        </li>
                    </ul>
                </template>

                <!-- Comentários -->
                <template v-else-if="activeTab === 'comentarios'">
                    <h2 class="mb-4 text-sm font-semibold uppercase text-zinc-500 dark:text-zinc-400">Comentários</h2>
                    <Link :href="`/produtos/${produto.id}/member-builder/comments`" class="text-sm text-[var(--color-primary)] hover:underline">
                        Ver e aprovar comentários <ChevronDown class="inline h-4 w-4 rotate-[270deg]" />
                    </Link>
                </template>

                <!-- Comunidade -->
                <template v-else-if="activeTab === 'comunidade'">
                    <Toggle v-model="configForm.member_area_config.community_enabled" label="Habilitar comunidade" class="mb-4" />
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-semibold uppercase text-zinc-500 dark:text-zinc-400">Páginas</h2>
                        <Button size="sm" @click="addCommunityPage">Nova página</Button>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="p in produto.community_pages" :key="p.id" class="flex items-center justify-between rounded-lg bg-zinc-50 py-2 px-3 text-sm dark:bg-zinc-800/50">
                            <span>{{ p.title }} ({{ p.is_public_posting ? 'público' : 'só instrutor' }})</span>
                            <button type="button" class="text-red-600 hover:underline" @click="deleteCommunityPage(p.id)">Remover</button>
                        </li>
                    </ul>
                    <Button type="button" class="mt-4" @click="saveConfig" :disabled="configForm.processing">Salvar</Button>
                </template>

                <!-- Certificado -->
                <template v-else-if="activeTab === 'certificado'">
                    <h2 class="mb-4 text-sm font-semibold uppercase text-zinc-500 dark:text-zinc-400">Certificado</h2>
                    <div class="space-y-4">
                        <Toggle v-model="configForm.member_area_config.certificate.enabled" label="Habilitar certificado" />
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">% conclusão mínima</label>
                            <input v-model.number="configForm.member_area_config.certificate.completion_percent" type="number" min="0" max="100" :class="inputClass" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Assinatura</label>
                            <input v-model="configForm.member_area_config.certificate.signature_text" type="text" :class="inputClass" />
                        </div>
                    </div>
                    <Button type="button" class="mt-4" @click="saveConfig" :disabled="configForm.processing">Salvar</Button>
                </template>

                <!-- Gamificação -->
                <template v-else-if="activeTab === 'gamificacao'">
                    <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Gamificação</h2>
                    <div class="space-y-4">
                        <Toggle v-model="configForm.member_area_config.gamification.enabled" label="Habilitar gamificação" />
                        <p v-if="configForm.member_area_config.gamification.enabled" class="text-xs text-zinc-600 dark:text-zinc-400">Configure conquistas no Member Builder completo (abrir produto e clicar em Member Builder) para personalizar badges, gatilhos e descrições.</p>
                    </div>
                    <Button type="button" class="mt-4" @click="saveConfig" :disabled="configForm.processing">Salvar</Button>
                </template>

                <!-- PWA e URL -->
                <template v-else-if="activeTab === 'pwa'">
                    <div class="mx-auto max-w-3xl space-y-6">
                        <div class="min-w-0 flex-1 space-y-6">
                            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                                <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                    <Globe class="h-4 w-4 text-sky-500" />
                                    URL da área
                                </h3>
                                <div class="space-y-4">
                                    <div>
                                        <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Tipo de URL</label>
                                        <select v-model="configForm.domain_type" :class="inputClass" class="w-full">
                                            <option value="path">Slug</option>
                                            <option value="custom">Domínio</option>
                                        </select>
                                    </div>
                                    <div v-if="configForm.domain_type === 'path'">
                                        <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Segmento da URL</label>
                                        <input v-model="configForm.domain_value" type="text" :class="inputClass" class="w-full" placeholder="Ex.: meucurso" maxlength="16" />
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Vazio = {{ produto.checkout_slug }}</p>
                                    </div>
                                    <div v-else-if="configForm.domain_type === 'custom'">
                                        <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Domínio ou subdomínio</label>
                                        <input v-model="configForm.domain_value" type="text" :class="inputClass" class="w-full" placeholder="membros.empresa.com ou area.empresa.com.br" />
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Ex.: membros.seudominio.com ou area.seudominio.com.br</p>
                                        <div v-if="dns_target_host || dns_target_ip" class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-800 dark:bg-amber-950/40">
                                            <p class="mb-2 text-xs font-semibold text-amber-800 dark:text-amber-200">Configure o DNS do seu domínio</p>
                                            <p class="mb-2 text-xs text-amber-700 dark:text-amber-300">Aponte o subdomínio ou domínio para o mesmo servidor desta aplicação:</p>
                                            <ul class="space-y-1.5 text-xs text-amber-800 dark:text-amber-200">
                                                <li v-if="dns_target_ip" class="flex flex-wrap items-center gap-1.5">
                                                    <span class="font-medium">Registro A:</span>
                                                    <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono dark:bg-amber-900/60">{{ dns_target_ip }}</code>
                                                    <span class="text-amber-700 dark:text-amber-300">(aponte o nome para este IP)</span>
                                                </li>
                                                <li v-if="dns_target_host && dns_target_host !== dns_target_ip" class="flex flex-wrap items-center gap-1.5">
                                                    <span class="font-medium">Ou CNAME:</span>
                                                    <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono dark:bg-amber-900/60">{{ dns_target_host }}</code>
                                                    <span class="text-amber-700 dark:text-amber-300">(aponte o nome para este host)</span>
                                                </li>
                                                <li v-else-if="dns_target_host" class="flex flex-wrap items-center gap-1.5">
                                                    <span class="font-medium">CNAME:</span>
                                                    <code class="rounded bg-amber-100 px-1.5 py-0.5 font-mono dark:bg-amber-900/60">{{ dns_target_host }}</code>
                                                </li>
                                            </ul>
                                            <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">Após propagar o DNS, salve e acesse o link abaixo para testar.</p>
                                        </div>
                                    </div>
                                    <div class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                                        <p class="mb-1 text-xs font-medium text-zinc-500 dark:text-zinc-400">Link completo</p>
                                        <p class="break-all text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ memberAreaFullLink }}</p>
                                        <a :href="memberAreaFullLink" target="_blank" rel="noopener" class="mt-2 inline-flex items-center gap-1 text-sm text-sky-600 hover:underline dark:text-sky-400">
                                            <ExternalLink class="h-3.5 w-3.5" /> Abrir link
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                                <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                    <Smartphone class="h-4 w-4 text-emerald-500" />
                                    Aparência do app
                                </h3>
                                <div class="space-y-4">
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">O ícone do PWA (favicon) é configurado na aba <strong>Aparência</strong>.</p>
                                    <div>
                                        <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome do app</label>
                                        <input v-model="configForm.member_area_config.pwa.name" type="text" :class="inputClass" class="w-full" placeholder="Ex: Meu Curso" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome curto</label>
                                        <input v-model="configForm.member_area_config.pwa.short_name" type="text" :class="inputClass" class="w-full" placeholder="Ex: Meu Curso" maxlength="32" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor do tema</label>
                                        <div class="flex items-center gap-3">
                                            <input v-model="configForm.member_area_config.pwa.theme_color" type="color" class="h-10 w-14 cursor-pointer rounded-lg border border-zinc-300 dark:border-zinc-600" />
                                            <input v-model="configForm.member_area_config.pwa.theme_color" type="text" :class="inputClass" class="flex-1 font-mono text-sm" placeholder="#0ea5e9" maxlength="20" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                                <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                    <Bell class="h-4 w-4 text-violet-500" />
                                    Notificações push
                                </h3>
                                <div class="space-y-4">
                                    <Toggle v-model="configForm.member_area_config.pwa.push_enabled" label="Habilitar notificações push" />
                                    <div v-if="configForm.member_area_config.pwa.push_enabled" class="rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        <p class="font-medium text-zinc-700 dark:text-zinc-300">Chaves VAPID</p>
                                        <p class="mt-1">As chaves são geradas e armazenadas automaticamente para este produto. Ative e salve para gerar.</p>
                                    </div>
                                    <div v-if="configForm.member_area_config.pwa.push_enabled" class="space-y-3 border-t border-zinc-200 pt-4 dark:border-zinc-600">
                                        <p class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Enviar notificação push</p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Será enviada somente para os inscritos <strong>nesta área de membros</strong>.</p>
                                        <div v-if="pushSubscribersCount !== null" class="text-xs text-zinc-600 dark:text-zinc-400">
                                            {{ pushSubscribersCount }} {{ pushSubscribersCount === 1 ? 'inscrito' : 'inscritos' }} neste produto.
                                        </div>
                                        <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                                            <div class="min-w-0 flex-1 space-y-2">
                                                <input
                                                    v-model="pushForm.title"
                                                    type="text"
                                                    :class="inputClass"
                                                    class="w-full"
                                                    placeholder="Título da notificação"
                                                    maxlength="100"
                                                />
                                                <input
                                                    v-model="pushForm.body"
                                                    type="text"
                                                    :class="inputClass"
                                                    class="w-full"
                                                    placeholder="Mensagem"
                                                    maxlength="200"
                                                />
                                            </div>
                                            <button
                                                type="button"
                                                :disabled="pushSending || !pushForm.title.trim() || !pushForm.body.trim()"
                                                class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-medium text-white hover:bg-sky-600 disabled:opacity-50"
                                                @click="sendPushNotification"
                                            >
                                                {{ pushSending ? 'Enviando…' : 'Enviar notificação' }}
                                            </button>
                                        </div>
                                        <p v-if="pushSendResult" class="text-xs" :class="pushSendResult.success ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                            {{ pushSendResult.message }}
                                        </p>
                                    </div>
                                </div>
                            </div>
                            <Button type="button" class="w-full" @click="saveConfig" :disabled="configForm.processing">Salvar alterações</Button>
                        </div>
                    </div>
                </template>
            </div>
        </aside>

        <!-- Preview (direita) - só nas abas com preview -->
        <div
            v-if="showPreview"
            class="flex min-h-0 flex-1 flex-col overflow-hidden bg-zinc-200 p-4 dark:bg-zinc-900"
        >
            <p class="mb-2 shrink-0 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Preview</p>
            <div class="min-h-0 flex-1 overflow-auto">
                <MemberBuilderPreview
                    :mode="previewMode"
                    :config="configForm.member_area_config"
                    :product-name="produto.name"
                />
            </div>
        </div>
    </div>
    </div>

    <!-- Modal: Desbloqueio manual de módulo -->
    <Teleport to="body">
    <div v-if="unlockModal.open" class="fixed inset-0 z-[100020] flex items-center justify-center bg-black/50" @click.self="unlockModal.open = false">
        <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-zinc-900">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-semibold">
                    <Lock class="mr-1 inline h-4 w-4 text-amber-500" />
                    Desbloqueios manuais — {{ unlockModal.moduleTitle }}
                </h3>
                <button type="button" class="text-zinc-400 hover:text-zinc-600" @click="unlockModal.open = false"><X class="h-5 w-5" /></button>
            </div>

            <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">
                Desbloqueia este módulo imediatamente para um aluno, ignorando o prazo de liberação.
            </p>

            <!-- Formulário de desbloqueio -->
            <div class="mb-4 flex gap-2">
                <select v-model="unlockForm.user_id" :class="inputClass" class="flex-1">
                    <option value="">Selecione um aluno...</option>
                    <option v-for="u in produto.product_users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                </select>
                <Button size="sm" @click="doUnlock" :disabled="!unlockForm.user_id">
                    <Unlock class="h-4 w-4" /> Desbloquear
                </Button>
            </div>

            <!-- Lista de desbloqueios -->
            <div v-if="unlockModal.loading" class="py-4 text-center text-xs text-zinc-500">Carregando...</div>
            <div v-else-if="unlockModal.unlocks.length" class="max-h-60 space-y-2 overflow-y-auto">
                <div v-for="u in unlockModal.unlocks" :key="u.id" class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-zinc-800/50">
                    <div>
                        <span class="font-medium">{{ u.user?.name ?? 'Aluno removido' }}</span>
                        <span class="ml-1 text-zinc-500">{{ u.user?.email }}</span>
                        <span class="ml-2 text-zinc-400">{{ u.created_at }}</span>
                        <span v-if="u.unlocked_by" class="ml-1 text-zinc-400">(por {{ u.unlocked_by.name }})</span>
                    </div>
                    <button type="button" class="text-red-500 hover:underline" @click="doLock(u.user?.id)">Remover</button>
                </div>
            </div>
            <p v-else class="py-2 text-center text-xs text-zinc-500">Nenhum desbloqueio manual.</p>
        </div>
    </div>
    </Teleport>

    </Teleport>
</template>
