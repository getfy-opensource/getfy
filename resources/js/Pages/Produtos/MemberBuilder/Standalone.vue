<script setup>
import { ref, computed, reactive, nextTick, onMounted, watch } from 'vue';
import axios from 'axios';
import MemberBuilderPreview from '@/components/member-builder/MemberBuilderPreview.vue';
import MemberBuilderModulesTab from '@/components/member-builder/MemberBuilderModulesTab.vue';
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
    ChevronRight,
    ChevronUp,
    ExternalLink,
    X,
    FileVideo,
    Link,
    FileText,
    Pencil,
    FolderOpen,
    BookOpen,
    Trophy,
    BarChart3,
    Presentation,
} from 'lucide-vue-next';
import {
    communityPageIconComponents,
    communityPageIconNames,
    communityPageEmojis,
    getCommunityPageIconComponent,
} from '@/utils/communityPageIcons';
import { COMMUNITY_BANNER_CONTAINER_CLASS, COMMUNITY_BANNER_IMAGE_CLASS, COMMUNITY_BANNER_RECOMMENDED } from '@/utils/communityBanner';

const props = defineProps({
    produto: { type: Object, required: true },
    tenant_products: { type: Array, default: () => [] },
    app_url: { type: String, default: '' },
    dns_target_host: { type: String, default: null },
    dns_target_ip: { type: String, default: null },
    /** Limites exibidos e usados nas mensagens (valores reais vêm do backend / .env). */
    upload_limits: {
        type: Object,
        default: () => ({ image_max_mb: 10, badge_max_mb: 5, pdf_max_mb: 50 }),
    },
});

const uploadLimits = computed(() => ({
    image_max_mb: props.upload_limits?.image_max_mb ?? 10,
    badge_max_mb: props.upload_limits?.badge_max_mb ?? 5,
    pdf_max_mb: props.upload_limits?.pdf_max_mb ?? 50,
}));

function memberBuilderImageUploadError(e, fallbackLabel = 'imagem') {
    const m = uploadLimits.value.image_max_mb;
    return e?.response?.data?.message || `Falha ao enviar ${fallbackLabel}. Verifique o tamanho (máx. ${m} MB) e o formato.`;
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const activeTab = ref('aparencia');
const processing = ref(false);
const heroSlideUploading = ref({});
const heroSlideFileInputs = ref({});
const certBgFileInput = ref(null);
const headerLogoUploading = ref(false);
const headerLogoFileInput = ref(null);
const loginLogoUploading = ref(false);
const loginLogoFileInput = ref(null);
const loginBackgroundUploading = ref(false);
const loginBackgroundFileInput = ref(null);
const faviconUploading = ref(false);
const faviconFileInput = ref(null);

const pushSubscribersCount = computed(() => props.produto.push_subscribers_count ?? null);
const pushForm = reactive({ title: '', body: '' });
const pushSending = ref(false);
const pushSendResult = ref(null);
/** Incrementar para forçar o preview a atualizar em tempo real (ex.: após adicionar módulo) */
const previewKey = ref(0);
async function sendPushNotification() {
    if (pushSending.value || !pushForm.title.trim() || !pushForm.body.trim()) return;
    pushSendResult.value = null;
    pushSending.value = true;
    try {
        const { data } = await axios.post(
            `${base.value}/send-push`,
            { title: pushForm.title.trim(), body: pushForm.body.trim() },
            { headers: headers() }
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

const base = computed(() => `/produtos/${props.produto.id}/member-builder`);
const uploadUrl = computed(() => `${window.location.origin}${base.value}/upload`);
const uploadPdfUrl = computed(() => `${window.location.origin}${base.value}/upload-pdf`);

const baseUrlForLink = computed(() => props.app_url || (typeof window !== 'undefined' ? window.location.origin : '') || '');

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

function newHeroSlideId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return `slide-${Date.now()}-${Math.random().toString(36).slice(2, 8)}`;
}

function normalizeHeroConfig(heroInput = {}) {
    const hero = {
        title: '',
        subtitle: '',
        image_url: '',
        image_url_desktop: '',
        image_url_mobile: '',
        overlay: true,
        overlay_opacity: 50,
        autoplay: true,
        autoplay_interval: 5,
        slides: [],
        ...heroInput,
    };
    let slides = Array.isArray(hero.slides) ? [...hero.slides] : [];
    if (!slides.length && (hero.image_url_desktop || hero.image_url_mobile || hero.image_url)) {
        slides = [{
            id: newHeroSlideId(),
            image_url_desktop: hero.image_url_desktop || hero.image_url || '',
            image_url_mobile: hero.image_url_mobile || '',
            title: hero.title || '',
            subtitle: hero.subtitle || '',
        }];
    }
    hero.slides = slides.map((s) => ({
        id: s?.id || newHeroSlideId(),
        image_url_desktop: s?.image_url_desktop || s?.image_url || '',
        image_url_mobile: s?.image_url_mobile || '',
        title: s?.title || '',
        subtitle: s?.subtitle || '',
    }));
    if (hero.autoplay === undefined || hero.autoplay === null) hero.autoplay = true;
    const interval = Number(hero.autoplay_interval ?? 5);
    hero.autoplay_interval = Math.max(2, Math.min(30, Number.isFinite(interval) && interval > 0 ? interval : 5));
    return hero;
}

function syncHeroLegacyFields() {
    const hero = configForm.member_area_config.hero;
    if (!hero) return;
    const first = Array.isArray(hero.slides) ? hero.slides[0] : null;
    if (first) {
        hero.image_url_desktop = first.image_url_desktop || '';
        hero.image_url_mobile = first.image_url_mobile || '';
        hero.image_url = first.image_url_desktop || first.image_url_mobile || '';
        if (!hero.title && first.title) hero.title = first.title;
        if (!hero.subtitle && first.subtitle) hero.subtitle = first.subtitle;
    } else {
        hero.image_url_desktop = '';
        hero.image_url_mobile = '';
        hero.image_url = '';
    }
}

const defaultConfig = () => ({
    theme: { primary: '#0ea5e9', background: '#18181b', text: '#f8fafc', sidebar_bg: '#27272a', ...props.produto.member_area_config?.theme },
    hero: normalizeHeroConfig({ title: '', subtitle: '', image_url: '', image_url_desktop: '', image_url_mobile: '', overlay: true, overlay_opacity: 50, slides: [], autoplay: true, autoplay_interval: 5, ...props.produto.member_area_config?.hero }),
    header: { logo_url: '', ...props.produto.member_area_config?.header },
    logos: props.produto.member_area_config?.logos ?? {},
    sidebar: { collapsible: false, items: [], ...props.produto.member_area_config?.sidebar },
        login: {
            template: 'v1',
            title: '',
            subtitle: '',
            primary_color: '#0ea5e9',
            background_color: '#18181b',
            logo: '',
            background_image: '',
            background_overlay_opacity: 50,
            password_mode: props.produto.member_area_config?.login?.password_mode ?? 'auto',
            default_password: props.produto.member_area_config?.login?.default_password ?? '',
            login_without_password: props.produto.member_area_config?.login?.login_without_password ?? false,
            ...props.produto.member_area_config?.login,
        },
    pwa: { name: '', short_name: '', theme_color: '#0ea5e9', push_enabled: false, ...props.produto.member_area_config?.pwa },
    certificate: { enabled: false, title: '', completion_percent: 100, signature_text: '', font_family: 'sans-serif', duration_text: '', platform_name: '', primary_color: '', background_image_url: '', background_overlay_enabled: false, background_overlay_color: '#000000', background_overlay_opacity: 50, text_color: '', title_color: '', signature_font_family: 'Dancing Script', print_format: 'A4', ...props.produto.member_area_config?.certificate },
    community_enabled: props.produto.member_area_config?.community_enabled ?? false,
    community_users_can_delete_own_posts: props.produto.member_area_config?.community_users_can_delete_own_posts ?? true,
    comments_enabled: props.produto.member_area_config?.comments_enabled ?? false,
    comments_require_approval: props.produto.member_area_config?.comments_require_approval ?? true,
    gamification: { enabled: false, achievements: [], ...props.produto.member_area_config?.gamification },
    refund: {
        enabled: false,
        days: 7,
        mode: 'manual',
        ...props.produto.member_area_config?.refund,
    },
});

const configForm = reactive({
    member_area_config: defaultConfig(),
    domain_type: props.produto.member_area_domain?.type ?? 'path',
    domain_value: props.produto.member_area_domain?.value ?? props.produto.checkout_slug ?? '',
});

const tabs = [
    { id: 'aparencia', label: 'Aparência', icon: Palette, hasPreview: true, previewMode: 'area' },
    { id: 'header', label: 'Header', icon: LayoutList, hasPreview: true, previewMode: 'area' },
    { id: 'modulos', label: 'Módulos e Aulas', icon: Layers, hasPreview: true, previewMode: 'area' },
    { id: 'turmas', label: 'Turmas', icon: Users, hasPreview: false },
    { id: 'progresso', label: 'Progresso', icon: BarChart3, hasPreview: false },
    { id: 'comentarios', label: 'Comentários', icon: MessageSquare, hasPreview: false },
    { id: 'comunidade', label: 'Comunidade', icon: Globe, hasPreview: true, previewMode: 'comunidade' },
    { id: 'certificado', label: 'Certificado', icon: Award, hasPreview: true, previewMode: 'certificate' },
    { id: 'gamificacao', label: 'Gamificação', icon: Trophy, hasPreview: false },
    { id: 'login', label: 'Login', icon: LogIn, hasPreview: true, previewMode: 'login' },
    { id: 'pwa', label: 'PWA e URL', icon: Smartphone, hasPreview: false },
];

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

const currentTab = computed(() => tabs.find((t) => t.id === activeTab.value));
const showPreview = computed(() => currentTab.value?.hasPreview ?? false);
const previewMode = computed(() => currentTab.value?.previewMode ?? 'area');

const modulosPreviewOpen = ref(true);
try {
    const savedPreview = localStorage.getItem(`member_builder_modulos_preview_${props.produto.id}`);
    if (savedPreview === '0') modulosPreviewOpen.value = false;
} catch (_) {}
watch(modulosPreviewOpen, (open) => {
    try { localStorage.setItem(`member_builder_modulos_preview_${props.produto.id}`, open ? '1' : '0'); } catch (_) {}
});

function cloneMemberSectionsStructure(sections) {
    try {
        const parsed = JSON.parse(JSON.stringify(sections ?? []));
        for (const s of parsed) {
            if (!Array.isArray(s.modules)) s.modules = [];
            for (const m of s.modules) {
                if (!Array.isArray(m.lessons)) m.lessons = [];
            }
        }
        return parsed;
    } catch {
        return [];
    }
}

const courseStructureSections = ref(cloneMemberSectionsStructure(props.produto.sections));

const initialModuleSelection = typeof window === 'undefined'
    ? null
    : new URLSearchParams(window.location.search);

watch(
    () => props.produto.sections,
    (next) => {
        courseStructureSections.value = cloneMemberSectionsStructure(next);
    },
    { deep: true },
);

const modulosSelectedSectionId = ref(Number(initialModuleSelection?.get('member_section')) || null);
const modulosSelectedModuleId = ref(Number(initialModuleSelection?.get('member_module')) || null);

const effectiveShowPreview = computed(() => {
    if (!showPreview.value) return false;
    if (activeTab.value === 'modulos') return modulosPreviewOpen.value;
    return true;
});

const previewSections = computed(() =>
    activeTab.value === 'modulos' ? courseStructureSections.value : (props.produto.sections ?? []),
);

const tabIds = tabs.map((t) => t.id);

const totalLessonsProgress = computed(() => Number(props.produto.total_lessons ?? 0));
const studentProgressRows = computed(() => {
    const rows = props.produto.student_progress ?? [];
    return [...rows].sort((a, b) =>
        String(a.name || a.email || '').localeCompare(String(b.name || b.email || ''), 'pt', { sensitivity: 'base' })
    );
});

const commentStatusFilter = ref('all');
const commentActionId = ref(null);
const commentsFiltered = computed(() => {
    const list = props.produto?.comments ?? [];
    if (commentStatusFilter.value === 'all') return list;
    return list.filter((c) => c.status === commentStatusFilter.value);
});
function setCommentStatus(s) {
    commentStatusFilter.value = s;
}
function approveComment(commentId) {
    commentActionId.value = commentId;
    axios
        .put(`${base.value}/comments/${commentId}`, { status: 'approved' }, { headers: headers() })
        .then(() => {
            window.location.href = `${base.value}?tab=comentarios`;
        })
        .finally(() => {
            commentActionId.value = null;
        });
}
function rejectComment(commentId) {
    commentActionId.value = commentId;
    axios
        .put(`${base.value}/comments/${commentId}`, { status: 'rejected' }, { headers: headers() })
        .then(() => {
            window.location.href = `${base.value}?tab=comentarios`;
        })
        .finally(() => {
            commentActionId.value = null;
        });
}
onMounted(() => {
    const p = new URLSearchParams(window.location.search);
    const t = p.get('tab');
    if (t && tabIds.includes(t)) activeTab.value = t;
});
watch(activeTab, (id) => {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    window.history.replaceState({}, '', url.toString());
}, { immediate: false });
// Persist selected tab in localStorage as fallback
onMounted(() => {
    try {
        const saved = localStorage.getItem(`member_builder_tab_${props.produto.id}`);
        const p = new URLSearchParams(window.location.search);
        const t = p.get('tab');
        if (!t && saved && tabIds.includes(saved)) {
            activeTab.value = saved;
        }
    } catch (_) {}
});
watch(activeTab, (id) => {
    try { localStorage.setItem(`member_builder_tab_${props.produto.id}`, id); } catch (_) {}
});

watch(
    [activeTab, () => courseStructureSections.value],
    () => {
        if (activeTab.value !== 'modulos') return;
        if (!courseStructureSections.value.length) {
            modulosSelectedSectionId.value = null;
            modulosSelectedModuleId.value = null;
            return;
        }
        if (!modulosSelectedSectionId.value || !courseStructureSections.value.some((s) => s.id === modulosSelectedSectionId.value)) {
            modulosSelectedSectionId.value = courseStructureSections.value[0].id;
        }
        const section = courseStructureSections.value.find((s) => s.id === modulosSelectedSectionId.value);
        if (section?.modules?.length) {
            if (!modulosSelectedModuleId.value || !section.modules.some((m) => m.id === modulosSelectedModuleId.value)) {
                modulosSelectedModuleId.value = section.modules[0].id;
            }
        } else {
            modulosSelectedModuleId.value = null;
        }
    },
    { immediate: true },
);

const headerItems = computed({
    get: () => {
        const items = configForm.member_area_config.sidebar?.items;
        return Array.isArray(items) ? items : [];
    },
    set: () => {},
});

function addHeaderItem() {
    if (!configForm.member_area_config.sidebar) configForm.member_area_config.sidebar = { collapsible: false, items: [] };
    if (!Array.isArray(configForm.member_area_config.sidebar.items)) configForm.member_area_config.sidebar.items = [];
    configForm.member_area_config.sidebar.items.push({
        title: 'Novo menu',
        link: '/',
        open_external: false,
    });
    saveConfig();
}

function removeHeaderItem(index) {
    const items = configForm.member_area_config.sidebar?.items;
    if (!Array.isArray(items) || index < 0 || index >= items.length) return;
    items.splice(index, 1);
    saveConfig();
}

const GAMIFICATION_TRIGGERS = [
    { value: 'first_lesson', label: 'Primeira aula concluída' },
    { value: 'lessons_count', label: 'N aulas concluídas' },
    { value: 'completion_percent', label: 'X% do curso' },
    { value: 'course_complete', label: 'Curso completo (100%)' },
    { value: 'first_comment', label: 'Primeiro comentário aprovado' },
    { value: 'certificate_earned', label: 'Certificado emitido' },
];
const BADGE_LIBRARY = [
    '/images/level-badge/color fill/badge.png',
    '/images/level-badge/color fill/badge (1).png',
    '/images/level-badge/color fill/badge (2).png',
    '/images/level-badge/color fill/badge (3).png',
    '/images/level-badge/color fill/badge (4).png',
    '/images/level-badge/color fill/badge (5).png',
    '/images/level-badge/color fill/badge (6).png',
    '/images/level-badge/color fill/badge (7).png',
    '/images/level-badge/color fill/badge (8).png',
    '/images/level-badge/color fill/coin.png',
    '/images/level-badge/color fill/level-badge.png',
    '/images/level-badge/color fill/medal.png',
    '/images/level-badge/color fill/rank-badge.png',
    '/images/level-badge/color fill/ranking-badge.png',
    '/images/level-badge/color fill/ranking-badge (1).png',
    '/images/level-badge/color fill/ranking-badge (2).png',
    '/images/level-badge/color fill/second-prize.png',
];

function ensureGamificationAchievements() {
    if (!configForm.member_area_config.gamification) configForm.member_area_config.gamification = { enabled: false, achievements: [] };
    if (!Array.isArray(configForm.member_area_config.gamification.achievements)) configForm.member_area_config.gamification.achievements = [];
}

function addGamificationAchievement() {
    ensureGamificationAchievements();
    const list = configForm.member_area_config.gamification.achievements;
    const nextIndex = list.length;
    list.push({
        id: `ach_${nextIndex}`,
        title: '',
        description: '',
        image: '',
        trigger: 'first_lesson',
        trigger_config: {},
        _editing: true,
    });
}

async function removeGamificationAchievement(index) {
    ensureGamificationAchievements();
    const list = configForm.member_area_config.gamification.achievements;
    if (!Array.isArray(list) || index < 0 || index >= list.length) return;
    // remove locally and keep a backup in case save fails
    const [removed] = list.splice(index, 1);
    list.forEach((a, i) => { a.id = `ach_${i}`; });
    try {
        // persist change
        await saveConfig();
        previewKey.value++;
    } catch (err) {
        // revert if save failed
        if (removed) {
            list.splice(index, 0, removed);
            list.forEach((a, i) => { a.id = `ach_${i}`; });
        }
        const msg = err?.response?.data?.message ?? err?.message ?? 'Erro ao remover conquista.';
        alert(msg);
    }
}

const badgeUploadingRef = ref(null);
const badgeFileInputRef = ref(null);
const currentAchievementForBadge = ref(null);
// Modal para nova/editar conquista (melhoria de layout)
const gamificationModalOpen = ref(false);
const gamificationModalSaving = ref(false);
const gamificationModalForm = reactive({
    title: '',
    trigger: 'first_lesson',
    trigger_config: {},
    description: '',
    image: '',
});
const gamificationEditingIndex = ref(null);
const gamificationModalFileRef = ref(null);

function openGamificationModal() {
    gamificationEditingIndex.value = null;
    gamificationModalForm.title = '';
    gamificationModalForm.trigger = 'first_lesson';
    gamificationModalForm.trigger_config = {};
    gamificationModalForm.description = '';
    gamificationModalForm.image = '';
    gamificationModalOpen.value = true;
    nextTick(() => {});
}

function openGamificationModalForEdit(ach, idx) {
    gamificationEditingIndex.value = idx;
    gamificationModalForm.title = ach.title || '';
    gamificationModalForm.trigger = ach.trigger || 'first_lesson';
    gamificationModalForm.trigger_config = ach.trigger_config ? { ...ach.trigger_config } : {};
    gamificationModalForm.description = ach.description || '';
    gamificationModalForm.image = ach.image || '';
    gamificationModalOpen.value = true;
    nextTick(() => {});
}

async function uploadBadgeFile(file) {
    if (!file) return null;
    try {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await axios.post(`${base.value}/upload-badge`, formData, {
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        return data?.url || data?.path || null;
    } catch (_) {
        return null;
    }
}

async function onGamificationModalFileChange(e) {
    const file = e.target?.files?.[0];
    if (!file) return;
    const url = await uploadBadgeFile(file);
    if (url) gamificationModalForm.image = url;
    if (gamificationModalFileRef.value) gamificationModalFileRef.value.value = '';
}

async function confirmAddAchievement() {
    gamificationModalSaving.value = true;
    try {
        const list = configForm.member_area_config.gamification.achievements || [];
        if (gamificationEditingIndex.value != null && list[gamificationEditingIndex.value]) {
            const ach = list[gamificationEditingIndex.value];
            ach.title = gamificationModalForm.title || '';
            ach.trigger = gamificationModalForm.trigger || 'first_lesson';
            ach.trigger_config = gamificationModalForm.trigger_config || {};
            ach.description = gamificationModalForm.description || '';
            ach.image = gamificationModalForm.image || ach.image || '';
            ach._editing = false;
            gamificationEditingIndex.value = null;
        } else {
            addGamificationAchievement();
            const newList = configForm.member_area_config.gamification.achievements;
            const ach = newList[newList.length - 1];
            if (ach) {
                ach.title = gamificationModalForm.title || '';
                ach.trigger = gamificationModalForm.trigger || 'first_lesson';
                ach.trigger_config = gamificationModalForm.trigger_config || {};
                ach.description = gamificationModalForm.description || '';
                ach.image = gamificationModalForm.image || '';
                ach._editing = false;
            }
        }
        // Persist changes to backend (this may reload the page)
        await saveConfig();
        // If saveConfig didn't reload (for some reason), update preview and close modal
        previewKey.value++;
        gamificationModalOpen.value = false;
    } catch (e) {
        gamificationModalOpen.value = false;
        const msg = e?.response?.data?.message ?? e?.message ?? 'Erro ao salvar a conquista.';
        alert(msg);
    } finally {
        gamificationModalSaving.value = false;
    }
}
async function onBadgeUpload(achievement, e) {
    const file = e.target?.files?.[0];
    if (!file || badgeUploadingRef.value) return;
    badgeUploadingRef.value = achievement.id;
    currentAchievementForBadge.value = achievement;
    const formData = new FormData();
    formData.append('file', file);
    try {
        const { data } = await axios.post(`${base.value}/upload-badge`, formData, {
            headers: { 'X-CSRF-TOKEN': csrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
        });
        achievement.image = data.url || data.path || '';
    } finally {
        badgeUploadingRef.value = null;
        currentAchievementForBadge.value = null;
        e.target.value = '';
    }
}
function triggerBadgeUpload(ach) {
    currentAchievementForBadge.value = ach;
    nextTick(() => {
        try {
            const el = document.getElementById(`badge-input-${ach.id}`);
            if (el && typeof el.click === 'function') {
                el.click();
                return;
            }
            if (badgeFileInputRef.value && typeof badgeFileInputRef.value.click === 'function') {
                badgeFileInputRef.value.click();
            }
        } catch (_) {}
    });
}
function selectBadge(ach, src) {
    // ensure array exists
    ensureGamificationAchievements();
    ach.image = src;
    // force Vue to notice change if necessary
    nextTick(() => {});
}

function resolveImageUrl(image) {
    if (!image) return '';
    try {
        if (typeof image !== 'string') return '';
        if (image.startsWith('http')) return image;
        if (image.startsWith('/')) {
            return (typeof window !== 'undefined' ? window.location.origin : '') + image;
        }
        return image;
    } catch (_) {
        return image;
    }
}

// Módulos: expandir/editar/modal
const expandedSections = ref(new Set());
const expandedModules = ref(new Set());
const editingSectionId = ref(null);
const editingModuleId = ref(null);

// Módulos: painel direito (aulas do módulo + formulário nova/editar)
const modulosLessonForm = ref(null);
const modulosLessonFormSaving = ref(false);
const modulosModuleFormSaving = ref(false);
const lessonPdfFileInput = ref(null);
const lessonPdfUploading = ref(false);
const lessonSupportFileInput = ref(null);
const lessonSupportUploading = ref(false);

/** Material (download), apresentação ou leitor PDF — mesmos campos no backend. */
function isLessonPdfContentType(type) {
    return type === 'pdf' || type === 'pdf_presentation' || type === 'pdf_reader';
}

function pdfLessonFileLabel(type) {
    if (type === 'pdf_presentation') return 'Apresentação';
    if (type === 'pdf_reader') return 'Documento';
    return 'Material';
}

const modulosSelectedModule = computed(() => {
    const id = modulosSelectedModuleId.value;
    if (!id) return null;
    for (const s of courseStructureSections.value ?? []) {
        const mod = s.modules?.find((m) => m.id === id);
        if (mod) return mod;
    }
    return null;
});

function selectModuleForAulas(moduleId) {
    modulosSelectedModuleId.value = moduleId;
    modulosLessonForm.value = null;
}

function normalizeLessonFileList(files, defaultName = 'Material') {
    const list = Array.isArray(files) ? files : [];
    return list
        .map((it) => {
            if (typeof it === 'string') return { url: it, name: defaultName };
            const url = (it?.url ?? '').toString().trim();
            if (!url) return null;
            return { url, name: (it?.name ?? defaultName).toString() };
        })
        .filter(Boolean);
}

function normalizeUsefulLinkList(links) {
    const list = Array.isArray(links) ? links : [];
    return list
        .map((it) => {
            const url = (it?.url ?? '').toString().trim();
            if (!url) return null;
            const title = (it?.title ?? '').toString().trim();
            return { url, title: title || 'Link' };
        })
        .filter(Boolean);
}

function openModulosLessonForm(lesson) {
    if (lesson) {
        const existingFiles = Array.isArray(lesson.content_files) ? lesson.content_files : [];
        const fileLabel = pdfLessonFileLabel(lesson.type);
        const normalizedFiles = existingFiles
            .map((it) => {
                if (typeof it === 'string') return { url: it, name: fileLabel };
                const url = (it?.url ?? '').toString().trim();
                if (!url) return null;
                return { url, name: (it?.name ?? fileLabel).toString() };
            })
            .filter(Boolean);
        if (normalizedFiles.length === 0 && lesson.content_url) {
            normalizedFiles.push({ url: lesson.content_url, name: fileLabel });
        }
        modulosLessonForm.value = {
            ...lesson,
            watermark_enabled: !!lesson.watermark_enabled,
            content_files: normalizedFiles,
            support_files: normalizeLessonFileList(lesson.support_files, 'Material de apoio'),
            useful_links: normalizeUsefulLinkList(lesson.useful_links),
            release_mode: lesson.release_at_date ? 'date' : (lesson.release_after_days ? 'days' : 'none'),
            release_after_days: lesson.release_after_days ? String(lesson.release_after_days) : '',
            release_at_date: lesson.release_at_date || '',
            access_duration_days: lesson.access_duration_days ? String(lesson.access_duration_days) : '',
            release_dependency_lesson_ids: Array.isArray(lesson.release_dependency_lesson_ids) ? lesson.release_dependency_lesson_ids.map(Number) : [],
            requires_previous_lessons: Array.isArray(lesson.release_dependency_lesson_ids) && lesson.release_dependency_lesson_ids.length > 0,
        };
    } else {
        modulosLessonForm.value = {
            title: '',
            type: 'video',
            content_url: '',
            link_title: '',
            content_files: [],
            support_files: [],
            useful_links: [],
            content_text: '',
            watermark_enabled: false,
            release_mode: 'none',
            release_after_days: '',
            release_at_date: '',
            access_duration_days: '',
            release_dependency_lesson_ids: [],
            requires_previous_lessons: false,
        };
    }
}

function closeModulosLessonForm() {
    modulosLessonForm.value = null;
}

async function onLessonPdfChange(event) {
    const files = Array.from(event.target?.files ?? []);
    if (!files.length || !modulosLessonForm.value) return;
    lessonPdfUploading.value = true;
    try {
        if (!Array.isArray(modulosLessonForm.value.content_files)) modulosLessonForm.value.content_files = [];
        for (const file of files) {
            if (!file) continue;
            if (file.type !== 'application/pdf') {
                alert('Selecione apenas arquivos em formato PDF.');
                continue;
            }
            const formData = new FormData();
            formData.append('file', file);
            const { data } = await axios.post(uploadPdfUrl.value, formData, { headers: uploadHeaders() });
            if (data?.url) {
                modulosLessonForm.value.content_files.push({ url: data.url, name: file.name });
            }
        }
        const first = modulosLessonForm.value.content_files?.[0]?.url ?? '';
        modulosLessonForm.value.content_url = first || modulosLessonForm.value.content_url || '';
    } catch (e) {
        const msg = e.response?.data?.message ?? e.message ?? `Erro ao enviar material. Tamanho máx. ${uploadLimits.value.pdf_max_mb} MB.`;
        alert(msg);
    } finally {
        lessonPdfUploading.value = false;
        if (lessonPdfFileInput.value) lessonPdfFileInput.value.value = '';
    }
}

function clearLessonPdf() {
    if (modulosLessonForm.value) modulosLessonForm.value.content_url = '';
    if (modulosLessonForm.value) modulosLessonForm.value.content_files = [];
    if (lessonPdfFileInput.value) lessonPdfFileInput.value.value = '';
}

function removeLessonPdfAt(index) {
    if (!modulosLessonForm.value || !Array.isArray(modulosLessonForm.value.content_files)) return;
    modulosLessonForm.value.content_files.splice(index, 1);
    const first = modulosLessonForm.value.content_files?.[0]?.url ?? '';
    modulosLessonForm.value.content_url = first || '';
}

async function onSupportPdfChange(event) {
    const files = Array.from(event.target?.files ?? []);
    if (!files.length || !modulosLessonForm.value) return;
    lessonSupportUploading.value = true;
    try {
        if (!Array.isArray(modulosLessonForm.value.support_files)) modulosLessonForm.value.support_files = [];
        for (const file of files) {
            if (!file) continue;
            if (file.type !== 'application/pdf') {
                alert('Selecione apenas arquivos em formato PDF.');
                continue;
            }
            const formData = new FormData();
            formData.append('file', file);
            const { data } = await axios.post(uploadPdfUrl.value, formData, { headers: uploadHeaders() });
            if (data?.url) {
                modulosLessonForm.value.support_files.push({ url: data.url, name: file.name });
            }
        }
    } catch (e) {
        const msg = e.response?.data?.message ?? e.message ?? `Erro ao enviar material. Tamanho máx. ${uploadLimits.value.pdf_max_mb} MB.`;
        alert(msg);
    } finally {
        lessonSupportUploading.value = false;
        if (lessonSupportFileInput.value) lessonSupportFileInput.value.value = '';
    }
}

function clearSupportFiles() {
    if (modulosLessonForm.value) modulosLessonForm.value.support_files = [];
    if (lessonSupportFileInput.value) lessonSupportFileInput.value.value = '';
}

function removeSupportFileAt(index) {
    if (!modulosLessonForm.value || !Array.isArray(modulosLessonForm.value.support_files)) return;
    modulosLessonForm.value.support_files.splice(index, 1);
}

function addUsefulLink() {
    if (!modulosLessonForm.value) return;
    if (!Array.isArray(modulosLessonForm.value.useful_links)) modulosLessonForm.value.useful_links = [];
    modulosLessonForm.value.useful_links.push({ title: '', url: '' });
}

function removeUsefulLinkAt(index) {
    if (!modulosLessonForm.value || !Array.isArray(modulosLessonForm.value.useful_links)) return;
    modulosLessonForm.value.useful_links.splice(index, 1);
}

function lessonPayload(form) {
    const contentFiles = Array.isArray(form.content_files)
        ? form.content_files
              .map((it) => ({
                  url: (it?.url ?? '').toString().trim(),
                  name: (it?.name ?? '').toString().trim(),
              }))
              .filter((it) => it.url)
        : [];
    const supportFiles = Array.isArray(form.support_files)
        ? form.support_files
              .map((it) => ({
                  url: (it?.url ?? '').toString().trim(),
                  name: (it?.name ?? '').toString().trim(),
              }))
              .filter((it) => it.url)
        : [];
    const usefulLinks = Array.isArray(form.useful_links)
        ? form.useful_links
              .map((it) => ({
                  title: (it?.title ?? '').toString().trim(),
                  url: (it?.url ?? '').toString().trim(),
              }))
              .filter((it) => it.url)
        : [];
    const firstFileUrl = contentFiles[0]?.url ?? '';
    let release_after_days = null;
    let release_at_date = null;
    const accessDurationDays = parseInt(form.access_duration_days, 10);
    if (form.release_mode === 'days') {
        const days = parseInt(form.release_after_days, 10);
        release_after_days = Number.isFinite(days) && days > 0 ? days : null;
    } else if (form.release_mode === 'date') {
        release_at_date = form.release_at_date?.trim() || null;
    }
    return {
        title: (form.title ?? '').trim() || 'Sem título',
        type: form.type ?? 'video',
        content_url: (isLessonPdfContentType(form.type) ? (firstFileUrl || form.content_url) : form.content_url) ?? '',
        link_title: form.link_title != null ? String(form.link_title).trim() : '',
        content_files: isLessonPdfContentType(form.type) ? contentFiles : [],
        support_files: supportFiles,
        useful_links: usefulLinks,
        release_after_days,
        release_at_date,
        access_duration_days: Number.isFinite(accessDurationDays) && accessDurationDays > 0 ? accessDurationDays : null,
        release_dependency_lesson_ids: form.requires_previous_lessons && Array.isArray(form.release_dependency_lesson_ids)
            ? form.release_dependency_lesson_ids.map(Number)
            : [],
        content_text: form.content_text ?? '',
        duration_seconds: 0,
        is_free: false,
        watermark_enabled: !!form.watermark_enabled,
    };
}

async function saveLessonFromSidebar() {
    const form = modulosLessonForm.value;
    const moduleId = modulosSelectedModuleId.value;
    if (!form || !moduleId) return;
    if (form.requires_previous_lessons && !(form.release_dependency_lesson_ids?.length > 0)) {
        alert('Selecione ao menos uma aula anterior concluída.');
        return;
    }
    modulosLessonFormSaving.value = true;
    try {
        const payload = lessonPayload(form);
        if (form.id) {
            await axios.put(`${base.value}/lessons/${form.id}`, payload, { headers: headers() });
        } else {
            await axios.post(`${base.value}/modules/${moduleId}/lessons`, payload, { headers: headers() });
        }
        closeModulosLessonForm();
        reload();
    } catch (e) {
        const msg = e.response?.data?.message ?? e.response?.data?.errors?.title?.[0] ?? e.message ?? 'Erro ao salvar.';
        alert(msg);
    } finally {
        modulosLessonFormSaving.value = false;
    }
}

function toggleSection(sectionId) {
    const next = new Set(expandedSections.value);
    if (next.has(sectionId)) next.delete(sectionId);
    else next.add(sectionId);
    expandedSections.value = next;
}

function toggleModule(moduleId) {
    const next = new Set(expandedModules.value);
    if (next.has(moduleId)) next.delete(moduleId);
    else next.add(moduleId);
    expandedModules.value = next;
}

function expandAllModulos() {
    const sections = props.produto.sections ?? [];
    expandedSections.value = new Set(sections.map((s) => s.id));
    expandedModules.value = new Set(
        sections.flatMap((s) => (s.modules ?? []).map((m) => m.id))
    );
}

function collapseAllModulos() {
    expandedSections.value = new Set();
    expandedModules.value = new Set();
}

function startEditSection(sectionId) {
    editingSectionId.value = sectionId;
    editingModuleId.value = null;
}

function startEditModule(moduleId) {
    editingModuleId.value = moduleId;
    editingSectionId.value = null;
}

function cancelEdit() {
    editingSectionId.value = null;
    editingModuleId.value = null;
}

const editingSectionTitle = ref('');
const editingSectionCoverMode = ref('vertical');
const editingModuleTitle = ref('');
const editingModuleShowTitleOnCover = ref(true);
const editingModuleRelatedProductId = ref(null);
const editingModuleAccessType = ref('paid');
const editingModuleExternalUrl = ref('');
const editingModuleReleaseMode = ref('none'); // none | days | date | progress | modules
const editingModuleReleaseAfterDays = ref('');
const editingModuleReleaseAtDate = ref('');
const editingModuleReleaseProgressPercent = ref('');
const editingModuleReleaseRequiredModuleIds = ref([]);
const editingModuleAccessDurationDays = ref('');

const sectionModalOpen = ref(false);
const sectionModalTitle = ref('');
const sectionModalCoverMode = ref('vertical');
const sectionModalSectionType = ref('courses');
const sectionModalSaving = ref(false);

const moduleModalOpen = ref(false);
const moduleModalSectionId = ref(null);
const moduleModalSectionType = ref('courses'); // courses | products | external_links
const moduleModalCoverMode = ref('vertical'); // modo da seção: vertical | horizontal
const moduleModalTitle = ref('');
const moduleModalShowTitleOnCover = ref(true);
const moduleModalFile = ref(null);
const moduleModalFilePreviewUrl = ref('');
const moduleModalSaving = ref(false);
const moduleModalFileInputRef = ref(null);
const moduleModalRelatedProductId = ref(null);
const moduleModalAccessType = ref('paid');
const moduleModalExternalUrl = ref('');
const moduleModalReleaseMode = ref('none'); // none | days | date | progress | modules
const moduleModalReleaseAfterDays = ref('');
const moduleModalReleaseAtDate = ref('');
const moduleModalReleaseProgressPercent = ref('');
const moduleModalReleaseRequiredModuleIds = ref([]);
const moduleModalAccessDurationDays = ref('');

function openSectionEdit(section) {
    editingSectionTitle.value = section.title;
    editingSectionCoverMode.value = section.cover_mode ?? 'vertical';
    startEditSection(section.id);
}

async function saveSectionTitle() {
    const id = editingSectionId.value;
    if (!id) return;
    try {
        await axios.put(`${base.value}/sections/${id}`, {
            title: editingSectionTitle.value,
            cover_mode: editingSectionCoverMode.value,
        }, { headers: headers() });
        cancelEdit();
        reload();
    } catch (_) {}
}

function openModuleEdit(mod) {
    editingModuleTitle.value = mod.title;
    editingModuleShowTitleOnCover.value = mod.show_title_on_cover !== false;
    editingModuleRelatedProductId.value = mod.related_product_id ?? null;
    editingModuleAccessType.value = mod.access_type ?? 'paid';
    editingModuleExternalUrl.value = mod.external_url ?? '';
    editingModuleReleaseProgressPercent.value = '';
    editingModuleReleaseRequiredModuleIds.value = [];
    if (mod.release_at_date) {
        editingModuleReleaseMode.value = 'date';
        editingModuleReleaseAtDate.value = mod.release_at_date;
        editingModuleReleaseAfterDays.value = '';
    } else if (mod.release_after_days) {
        editingModuleReleaseMode.value = 'days';
        editingModuleReleaseAfterDays.value = String(mod.release_after_days);
        editingModuleReleaseAtDate.value = '';
    } else if (mod.release_progress_percent) {
        editingModuleReleaseMode.value = 'progress';
        editingModuleReleaseProgressPercent.value = String(mod.release_progress_percent);
        editingModuleReleaseAfterDays.value = '';
        editingModuleReleaseAtDate.value = '';
    } else if (Array.isArray(mod.release_required_module_ids) && mod.release_required_module_ids.length) {
        editingModuleReleaseMode.value = 'modules';
        editingModuleReleaseRequiredModuleIds.value = mod.release_required_module_ids.map(Number);
        editingModuleReleaseAfterDays.value = '';
        editingModuleReleaseAtDate.value = '';
    } else {
        editingModuleReleaseMode.value = 'none';
        editingModuleReleaseAfterDays.value = '';
        editingModuleReleaseAtDate.value = '';
    }
    editingModuleAccessDurationDays.value = mod.access_duration_days ? String(mod.access_duration_days) : '';
    startEditModule(mod.id);
}

async function saveModuleTitle() {
    const id = editingModuleId.value ?? modulosSelectedModuleId.value;
    if (!id) return;
    const section = courseStructureSections.value?.find((s) => s.modules?.some((m) => m.id === id));
    const sectionType = section?.section_type ?? 'courses';
    const payload = { title: editingModuleTitle.value };
    if (sectionType === 'courses') {
        payload.show_title_on_cover = editingModuleShowTitleOnCover.value;
        const accessDurationDays = parseInt(editingModuleAccessDurationDays.value, 10);
        payload.access_duration_days = Number.isFinite(accessDurationDays) && accessDurationDays > 0 ? accessDurationDays : null;
        if (editingModuleReleaseMode.value === 'days') {
            const days = parseInt(editingModuleReleaseAfterDays.value, 10);
            payload.release_after_days = Number.isFinite(days) && days > 0 ? days : null;
            payload.release_at_date = null;
            payload.release_progress_percent = null;
            payload.release_required_module_ids = [];
        } else if (editingModuleReleaseMode.value === 'date') {
            payload.release_at_date = editingModuleReleaseAtDate.value?.trim() || null;
            payload.release_after_days = null;
            payload.release_progress_percent = null;
            payload.release_required_module_ids = [];
        } else if (editingModuleReleaseMode.value === 'progress') {
            const pct = parseInt(editingModuleReleaseProgressPercent.value, 10);
            payload.release_progress_percent = Number.isFinite(pct) && pct >= 1 && pct <= 100 ? pct : null;
            payload.release_after_days = null;
            payload.release_at_date = null;
            payload.release_required_module_ids = [];
        } else if (editingModuleReleaseMode.value === 'modules') {
            payload.release_required_module_ids = (editingModuleReleaseRequiredModuleIds.value || [])
                .map((x) => Number(x))
                .filter((n) => Number.isFinite(n) && n > 0);
            payload.release_after_days = null;
            payload.release_at_date = null;
            payload.release_progress_percent = null;
            if (!payload.release_required_module_ids.length) {
                window.alert('Selecione ao menos um módulo pré-requisito.');
                return;
            }
        } else {
            payload.release_after_days = null;
            payload.release_at_date = null;
            payload.release_progress_percent = null;
            payload.release_required_module_ids = [];
        }
    } else if (sectionType === 'products') {
        payload.related_product_id = editingModuleRelatedProductId.value;
        payload.access_type = editingModuleAccessType.value;
        payload.show_title_on_cover = editingModuleShowTitleOnCover.value;
    } else if (sectionType === 'external_links') {
        payload.external_url = editingModuleExternalUrl.value?.trim() ?? '';
        payload.show_title_on_cover = editingModuleShowTitleOnCover.value;
    }
    modulosModuleFormSaving.value = true;
    try {
        await axios.put(`${base.value}/modules/${id}`, payload, { headers: headers() });
        cancelEdit();
        reload();
    } catch (err) {
        const msg =
            err?.response?.data?.message
            || err?.response?.data?.errors?.release_required_module_ids?.[0]
            || err?.message
            || 'Não foi possível salvar o módulo.';
        window.alert(msg);
    } finally {
        modulosModuleFormSaving.value = false;
    }
}

async function setModuleShowTitleOnCover(value) {
    const id = editingModuleId.value;
    if (!id) return;
    try {
        await axios.put(`${base.value}/modules/${id}`, { show_title_on_cover: value }, { headers: headers() });
        const mod = props.produto.sections?.flatMap((s) => s.modules ?? []).find((m) => m.id === id);
        if (mod) mod.show_title_on_cover = value;
    } catch (_) {}
}

const editingModule = computed(() => {
    const id = editingModuleId.value;
    if (!id) return null;
    for (const s of courseStructureSections.value ?? []) {
        const mod = s.modules?.find((m) => m.id === id);
        if (mod) return mod;
    }
    return null;
});

const editingModuleSection = computed(() => {
    const id = editingModuleId.value ?? modulosSelectedModuleId.value;
    if (!id) return null;
    return courseStructureSections.value?.find((s) => s.modules?.some((m) => m.id === id)) ?? null;
});

const moduleThumbnailUploading = ref(false);
const moduleThumbnailFileInput = ref(null);

async function onModuleThumbnailChange(event) {
    const file = event.target?.files?.[0];
    const id = editingModuleId.value ?? modulosSelectedModuleId.value;
    if (!file || !file.type.startsWith('image/') || !id) return;
    moduleThumbnailUploading.value = true;
    try {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await axios.post(uploadUrl.value, formData, { headers: uploadHeaders() });
        if (data?.url) {
            await axios.put(`${base.value}/modules/${id}`, { thumbnail: data.url }, { headers: headers() });
            reload();
        }
    } catch (_) {}
    finally {
        moduleThumbnailUploading.value = false;
        if (moduleThumbnailFileInput.value) moduleThumbnailFileInput.value.value = '';
    }
}

function removeModuleThumbnail() {
    const id = editingModuleId.value ?? modulosSelectedModuleId.value;
    if (!id) return;
    axios.put(`${base.value}/modules/${id}`, { thumbnail: '' }, { headers: headers() }).then(() => reload()).catch(() => {});
}

const headers = () => ({
    'X-CSRF-TOKEN': csrfToken(),
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
});

const memberReorderSaving = ref(false);

function memberReorderIdsEqual(a, b) {
    return a.length === b.length && a.every((id, i) => id === b[i]);
}

async function persistMemberStructureReorder(body) {
    if (memberReorderSaving.value) return;
    memberReorderSaving.value = true;
    try {
        await axios.post(`${base.value}/reorder`, body, { headers: headers() });
        previewKey.value++;
    } catch (_) {
        courseStructureSections.value = cloneMemberSectionsStructure(props.produto.sections);
    } finally {
        memberReorderSaving.value = false;
    }
}

function onMemberSectionsReorderEnd() {
    const ids = courseStructureSections.value.map((s) => s.id);
    const original = (props.produto.sections ?? []).map((s) => s.id);
    if (memberReorderIdsEqual(ids, original)) return;
    persistMemberStructureReorder({ scope: 'sections', ordered_ids: ids });
}

function onMemberModulesReorderEnd(sectionId) {
    const section = courseStructureSections.value.find((s) => s.id === sectionId);
    if (!section?.modules) return;
    const ids = section.modules.map((m) => m.id);
    const origSection = (props.produto.sections ?? []).find((s) => s.id === sectionId);
    const origIds = (origSection?.modules ?? []).map((m) => m.id);
    if (memberReorderIdsEqual(ids, origIds)) return;
    persistMemberStructureReorder({ scope: 'modules', section_id: sectionId, ordered_ids: ids });
}

function onMemberLessonsReorderEnd() {
    const mid = modulosSelectedModuleId.value;
    if (!mid || !modulosSelectedModule.value?.lessons) return;
    const ids = modulosSelectedModule.value.lessons.map((l) => l.id);
    const origMod = (props.produto.sections ?? []).flatMap((s) => s.modules ?? []).find((m) => m.id === mid);
    const origIds = (origMod?.lessons ?? []).map((l) => l.id);
    if (memberReorderIdsEqual(ids, origIds)) return;
    persistMemberStructureReorder({ scope: 'lessons', module_id: mid, ordered_ids: ids });
}

function handleModulesTabOpenModuleEdit(mod) {
    modulosSelectedModuleId.value = mod.id;
    modulosLessonForm.value = null;
    openModuleEdit(mod);
}

watch(
    [activeTab, modulosSelectedModuleId],
    ([tab, id]) => {
        if (tab !== 'modulos' || !id) return;
        const mod = modulosSelectedModule.value;
        if (mod) openModuleEdit(mod);
    },
);

function handleModulesTabEditSection(section) {
    openSectionEdit(section);
}

async function saveConfig() {
    processing.value = true;
    try {
        syncHeroLegacyFields();
        const cleanedConfig = JSON.parse(JSON.stringify(configForm.member_area_config));
        if (cleanedConfig.hero?.slides) {
            cleanedConfig.hero.slides = cleanedConfig.hero.slides.map(({ _index, ...slide }) => slide);
        }
        if (cleanedConfig.gamification && Array.isArray(cleanedConfig.gamification.achievements)) {
            cleanedConfig.gamification.achievements.forEach((a) => { delete a._editing; });
        }
        const payload = {
            member_area_config: cleanedConfig,
        };
        // Evita bloquear salvamento de outras abas por validação de domínio.
        if (activeTab.value === 'pwa') {
            payload.domain_type = configForm.domain_type ?? 'path';
            payload.domain_value = configForm.domain_value ?? '';
        }
        // Rota POST explícita: em muitos ambientes _method em body JSON não é aplicado pelo Laravel.
        const putRes = await axios.post(
            `${base.value}/config`,
            payload,
            { headers: headers(), withCredentials: true }
        );
        const contentType = putRes?.headers?.['content-type'] ?? '';
        if (contentType.includes('text/html')) {
            alert('A resposta não foi JSON (possível redirecionamento). Verifique se está logado e tente novamente.');
            return;
        }
        if (putRes?.data?.warning) {
            alert(putRes.data.warning);
        }
        // Recarrega com cache-bust para forçar HTML novo (evita ver dados antigos por cache do navegador)
        const url = new URL(window.location.href);
        url.searchParams.set('tab', activeTab.value);
        url.searchParams.set('_', String(Date.now()));
        window.location.href = url.toString();
    } catch (err) {
        const msg = err?.response?.data?.message ?? err?.response?.data?.errors ?? err?.message ?? 'Erro ao salvar.';
        alert(Array.isArray(msg) ? Object.values(msg).flat().join('\n') : (typeof msg === 'object' ? JSON.stringify(msg) : msg));
    } finally {
        processing.value = false;
    }
}

const uploadHeaders = () => ({
    'X-CSRF-TOKEN': csrfToken(),
    'Accept': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
});

async function doUpload(file, setUrl) {
    const formData = new FormData();
    formData.append('file', file);
    const { data } = await axios.post(uploadUrl.value, formData, { headers: uploadHeaders() });
    if (data?.url) {
        setUrl(data.url);
        await saveConfig();
    }
}

function ensureHeroSlidesArray() {
    if (!configForm.member_area_config.hero) {
        configForm.member_area_config.hero = normalizeHeroConfig();
    }
    if (!Array.isArray(configForm.member_area_config.hero.slides)) {
        configForm.member_area_config.hero.slides = [];
    }
    return configForm.member_area_config.hero.slides;
}

function addHeroSlide() {
    ensureHeroSlidesArray().push({
        id: newHeroSlideId(),
        image_url_desktop: '',
        image_url_mobile: '',
        title: '',
        subtitle: '',
    });
}

function removeHeroSlide(index) {
    const slides = ensureHeroSlidesArray();
    if (index < 0 || index >= slides.length) return;
    slides.splice(index, 1);
    syncHeroLegacyFields();
    saveConfig();
}

function moveHeroSlide(index, direction) {
    const slides = ensureHeroSlidesArray();
    const target = index + direction;
    if (target < 0 || target >= slides.length) return;
    const [item] = slides.splice(index, 1);
    slides.splice(target, 0, item);
}

function setHeroSlideFileInput(slideId, el) {
    if (el) heroSlideFileInputs.value[slideId] = el;
    else delete heroSlideFileInputs.value[slideId];
}

async function onHeroSlideImageChange(event, index, field) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    const slides = ensureHeroSlidesArray();
    const slide = slides[index];
    if (!slide) return;
    const key = `${slide.id}:${field}`;
    heroSlideUploading.value = { ...heroSlideUploading.value, [key]: true };
    try {
        await doUpload(file, (url) => {
            slide[field] = url;
            syncHeroLegacyFields();
        });
    } catch (e) {
        alert(memberBuilderImageUploadError(e, 'imagem'));
    } finally {
        heroSlideUploading.value = { ...heroSlideUploading.value, [key]: false };
        if (event.target) event.target.value = '';
    }
}

function removeHeroSlideImage(index, field) {
    const slides = ensureHeroSlidesArray();
    const slide = slides[index];
    if (!slide) return;
    slide[field] = '';
    syncHeroLegacyFields();
    saveConfig();
}

async function onCertBgChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    try {
        await doUpload(file, (url) => { configForm.member_area_config.certificate.background_image_url = url; });
    } catch (e) {
        alert(memberBuilderImageUploadError(e, 'imagem'));
    }
    if (certBgFileInput.value) certBgFileInput.value.value = '';
}

function removeCertBg() {
    configForm.member_area_config.certificate.background_image_url = '';
    saveConfig();
}

async function onHeaderLogoChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    headerLogoUploading.value = true;
    try {
        await doUpload(file, (url) => {
            if (!configForm.member_area_config.header) configForm.member_area_config.header = { logo_url: '' };
            configForm.member_area_config.header.logo_url = url;
        });
    } catch (e) {
        alert(memberBuilderImageUploadError(e, 'logo'));
    } finally {
        headerLogoUploading.value = false;
        if (headerLogoFileInput.value) headerLogoFileInput.value.value = '';
    }
}

function removeHeaderLogo() {
    if (configForm.member_area_config.header) configForm.member_area_config.header.logo_url = '';
    saveConfig();
}

async function onLoginLogoChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    loginLogoUploading.value = true;
    try {
        await doUpload(file, (url) => { configForm.member_area_config.login.logo = url; });
    } catch (e) {
        alert(memberBuilderImageUploadError(e, 'logo'));
    } finally {
        loginLogoUploading.value = false;
        if (loginLogoFileInput.value) loginLogoFileInput.value.value = '';
    }
}

function removeLoginLogo() {
    configForm.member_area_config.login.logo = '';
    saveConfig();
}

async function onFaviconChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    faviconUploading.value = true;
    try {
        await doUpload(file, (url) => {
            if (!configForm.member_area_config.logos) configForm.member_area_config.logos = {};
            configForm.member_area_config.logos.favicon = url;
        });
    } catch (e) {
        alert(memberBuilderImageUploadError(e, 'ícone'));
    } finally {
        faviconUploading.value = false;
        if (faviconFileInput.value) faviconFileInput.value.value = '';
    }
}

function removeFavicon() {
    if (configForm.member_area_config.logos) configForm.member_area_config.logos.favicon = '';
    saveConfig();
}

async function onLoginBackgroundChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    loginBackgroundUploading.value = true;
    try {
        await doUpload(file, (url) => { configForm.member_area_config.login.background_image = url; });
    } catch (e) {
        alert(memberBuilderImageUploadError(e, 'imagem'));
    } finally {
        loginBackgroundUploading.value = false;
        if (loginBackgroundFileInput.value) loginBackgroundFileInput.value.value = '';
    }
}

function removeLoginBackground() {
    configForm.member_area_config.login.background_image = '';
    saveConfig();
}

function reload() {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', activeTab.value);
    if (activeTab.value === 'modulos') {
        if (modulosSelectedSectionId.value) url.searchParams.set('member_section', modulosSelectedSectionId.value);
        else url.searchParams.delete('member_section');
        if (modulosSelectedModuleId.value) url.searchParams.set('member_module', modulosSelectedModuleId.value);
        else url.searchParams.delete('member_module');
    }
    window.location.href = url.toString();
}


// Modal nativo para pedir um texto (substitui prompt())
const promptModal = ref({ show: false, title: '', placeholder: '', value: '', callback: null });
const promptModalInputRef = ref(null);

// Modal nativo de confirmação (substitui confirm() do navegador)
const confirmModal = ref({
    show: false,
    title: '',
    message: '',
    confirmLabel: 'Remover',
    danger: true,
    loading: false,
    onConfirm: null,
});
function openConfirmModal({ title, message, confirmLabel = 'Remover', danger = true, onConfirm }) {
    confirmModal.value = {
        show: true,
        title: title ?? 'Confirmar',
        message: message ?? 'Tem certeza?',
        confirmLabel: confirmLabel ?? 'Remover',
        danger: danger !== false,
        loading: false,
        onConfirm: typeof onConfirm === 'function' ? onConfirm : null,
    };
}
function closeConfirmModal() {
    confirmModal.value.show = false;
    confirmModal.value.onConfirm = null;
}
async function runConfirmModal() {
    const fn = confirmModal.value.onConfirm;
    if (!fn) {
        closeConfirmModal();
        return;
    }
    confirmModal.value.loading = true;
    try {
        await fn();
        closeConfirmModal();
    } catch (e) {
        const msg = e.response?.data?.message ?? e.message ?? 'Erro ao executar.';
        alert(msg);
    } finally {
        confirmModal.value.loading = false;
    }
}

function openPrompt(options, callback) {
    promptModal.value = {
        show: true,
        title: options.title ?? '',
        placeholder: options.placeholder ?? '',
        value: '',
        callback,
    };
    nextTick(() => promptModalInputRef.value?.focus());
}

async function confirmPrompt() {
    const val = promptModal.value.value?.trim() ?? '';
    const cb = promptModal.value.callback;
    promptModal.value = { show: false, title: '', placeholder: '', value: '', callback: null };
    if (cb) await cb(val || null);
}

function cancelPrompt() {
    if (promptModal.value.callback) promptModal.value.callback(null);
    promptModal.value = { show: false, title: '', placeholder: '', value: '', callback: null };
}

function sectionTypeLabel(sectionType) {
    const map = { courses: 'Cursos/Aulas', products: 'Outros produtos', external_links: 'Links externos' };
    return map[sectionType] ?? sectionType;
}

function openSectionModal() {
    sectionModalTitle.value = '';
    sectionModalCoverMode.value = 'vertical';
    sectionModalSectionType.value = 'courses';
    sectionModalOpen.value = true;
}

function closeSectionModal() {
    sectionModalOpen.value = false;
}

async function confirmNewSection() {
    const title = sectionModalTitle.value?.trim();
    if (!title) return;
    sectionModalSaving.value = true;
    try {
        await axios.post(`${base.value}/sections`, {
            title,
            cover_mode: sectionModalCoverMode.value,
            section_type: sectionModalSectionType.value,
        }, { headers: headers() });
        closeSectionModal();
        reload();
    } catch (_) {}
    finally {
        sectionModalSaving.value = false;
    }
}
async function deleteSection(sectionId) {
    openConfirmModal({
        title: 'Remover seção',
        message: 'Remover esta seção e todo o conteúdo?',
        confirmLabel: 'Remover',
        onConfirm: async () => {
            await axios.delete(`${base.value}/sections/${sectionId}`, { headers: headers() });
            reload();
        },
    });
}

function findSectionByModuleId(moduleId) {
    if (!moduleId) return null;
    return courseStructureSections.value?.find((s) => s.modules?.some((m) => m.id === moduleId)) ?? null;
}

function findModuleByLessonId(lessonId) {
    if (!lessonId) return null;
    for (const section of courseStructureSections.value ?? []) {
        const module = section.modules?.find((m) => m.lessons?.some((l) => l.id === lessonId));
        if (module) return { section, module };
    }
    return null;
}

async function duplicateSection(sectionId) {
    openConfirmModal({
        title: 'Duplicar seção',
        message: 'Criar uma cópia desta seção com módulos e aulas?',
        confirmLabel: 'Duplicar',
        danger: false,
        onConfirm: async () => {
            const { data } = await axios.post(
                `${base.value}/sections/${sectionId}/duplicate`,
                {},
                { headers: headers() },
            );
            const newSection = data?.section;
            if (!newSection) {
                reload();
                return;
            }
            const idx = courseStructureSections.value.findIndex((s) => s.id === sectionId);
            if (idx >= 0) {
                courseStructureSections.value.splice(idx + 1, 0, newSection);
            } else {
                courseStructureSections.value.push(newSection);
            }
            modulosSelectedSectionId.value = newSection.id;
            modulosSelectedModuleId.value = null;
            previewKey.value++;
        },
    });
}
function openModuleModal(sectionId) {
    const section = courseStructureSections.value?.find((s) => s.id === sectionId)
        ?? props.produto.sections?.find((s) => s.id === sectionId);
    moduleModalSectionId.value = sectionId;
    moduleModalSectionType.value = section?.section_type ?? 'courses';
    moduleModalCoverMode.value = section?.cover_mode ?? 'vertical';
    moduleModalTitle.value = '';
    moduleModalShowTitleOnCover.value = true;
    moduleModalRelatedProductId.value = null;
    moduleModalAccessType.value = 'paid';
    moduleModalExternalUrl.value = '';
    moduleModalReleaseMode.value = 'none';
    moduleModalReleaseAfterDays.value = '';
    moduleModalReleaseAtDate.value = '';
    moduleModalReleaseProgressPercent.value = '';
    moduleModalReleaseRequiredModuleIds.value = [];
    moduleModalAccessDurationDays.value = '';
    clearModuleModalFile();
    moduleModalOpen.value = true;
}

function clearModuleModalFile() {
    if (moduleModalFilePreviewUrl.value) {
        URL.revokeObjectURL(moduleModalFilePreviewUrl.value);
    }
    moduleModalFile.value = null;
    moduleModalFilePreviewUrl.value = '';
    if (moduleModalFileInputRef.value) moduleModalFileInputRef.value.value = '';
}

function onModuleModalFileChange(event) {
    const file = event.target?.files?.[0];
    if (moduleModalFilePreviewUrl.value) URL.revokeObjectURL(moduleModalFilePreviewUrl.value);
    moduleModalFile.value = file || null;
    moduleModalFilePreviewUrl.value = file && file.type.startsWith('image/') ? URL.createObjectURL(file) : '';
}

function closeModuleModal() {
    moduleModalOpen.value = false;
    moduleModalSectionId.value = null;
    clearModuleModalFile();
}

async function confirmNewModule() {
    const sectionId = moduleModalSectionId.value;
    const title = moduleModalTitle.value?.trim();
    if (!title || !sectionId) return;
    const sectionType = moduleModalSectionType.value;
    if (sectionType === 'products' && !moduleModalRelatedProductId.value) return;
    if (sectionType === 'external_links' && !moduleModalExternalUrl.value?.trim()) return;
    moduleModalSaving.value = true;
    try {
        let payload = { title };
        if (sectionType === 'courses') {
            payload.show_title_on_cover = moduleModalShowTitleOnCover.value;
            const accessDurationDays = parseInt(moduleModalAccessDurationDays.value, 10);
            payload.access_duration_days = Number.isFinite(accessDurationDays) && accessDurationDays > 0 ? accessDurationDays : null;
            if (moduleModalReleaseMode.value === 'days') {
                const days = parseInt(moduleModalReleaseAfterDays.value, 10);
                payload.release_after_days = Number.isFinite(days) && days > 0 ? days : null;
                payload.release_at_date = null;
                payload.release_progress_percent = null;
                payload.release_required_module_ids = [];
            } else if (moduleModalReleaseMode.value === 'date') {
                payload.release_at_date = moduleModalReleaseAtDate.value?.trim() || null;
                payload.release_after_days = null;
                payload.release_progress_percent = null;
                payload.release_required_module_ids = [];
            } else if (moduleModalReleaseMode.value === 'progress') {
                const pct = parseInt(moduleModalReleaseProgressPercent.value, 10);
                payload.release_progress_percent = Number.isFinite(pct) && pct >= 1 && pct <= 100 ? pct : null;
                payload.release_after_days = null;
                payload.release_at_date = null;
                payload.release_required_module_ids = [];
            } else if (moduleModalReleaseMode.value === 'modules') {
                payload.release_required_module_ids = (moduleModalReleaseRequiredModuleIds.value || []).map(Number);
                payload.release_after_days = null;
                payload.release_at_date = null;
                payload.release_progress_percent = null;
            } else {
                payload.release_after_days = null;
                payload.release_at_date = null;
                payload.release_progress_percent = null;
                payload.release_required_module_ids = [];
            }
        } else if (sectionType === 'products') {
            payload.related_product_id = moduleModalRelatedProductId.value;
            payload.access_type = moduleModalAccessType.value;
            payload.show_title_on_cover = moduleModalShowTitleOnCover.value;
        } else {
            payload.external_url = moduleModalExternalUrl.value?.trim() ?? '';
            payload.show_title_on_cover = moduleModalShowTitleOnCover.value;
        }
        const { data } = await axios.post(`${base.value}/sections/${sectionId}/modules`, payload, { headers: headers() });
        const imported = Array.isArray(data?.modules) && data.modules.length ? data.modules : data?.module ? [data.module] : [];
        if (!imported.length) {
            reload();
            return;
        }
        const hasCoverFile = moduleModalFile.value && moduleModalFile.value.type.startsWith('image/');
        if (hasCoverFile && imported.length === 1) {
            let newModule = imported[0];
            const formData = new FormData();
            formData.append('file', moduleModalFile.value);
            const up = await axios.post(uploadUrl.value, formData, { headers: uploadHeaders() });
            if (up.data?.url) {
                await axios.put(`${base.value}/modules/${newModule.id}`, { thumbnail: up.data.url }, { headers: headers() });
                newModule = { ...newModule, thumbnail: up.data.url };
                imported[0] = newModule;
            }
        }
        const section = props.produto.sections?.find((s) => s.id === sectionId);
        if (section) {
            if (!section.modules) section.modules = [];
            for (const newModule of imported) {
                section.modules.push(newModule);
            }
            expandedSections.value = new Set([...expandedSections.value, sectionId]);
            for (const newModule of imported) {
                expandedModules.value = new Set([...expandedModules.value, newModule.id]);
            }
        }
        previewKey.value++;
        closeModuleModal();
    } catch (_) {
        reload();
    } finally {
        moduleModalSaving.value = false;
    }
}
async function deleteModule(moduleId) {
    openConfirmModal({
        title: 'Remover módulo',
        message: 'Remover este módulo e todas as aulas?',
        confirmLabel: 'Remover',
        onConfirm: async () => {
            await axios.delete(`${base.value}/modules/${moduleId}`, { headers: headers() });
            reload();
        },
    });
}

async function duplicateModule(moduleId) {
    openConfirmModal({
        title: 'Duplicar módulo',
        message: 'Criar uma cópia deste módulo e das aulas?',
        confirmLabel: 'Duplicar',
        danger: false,
        onConfirm: async () => {
            const { data } = await axios.post(
                `${base.value}/modules/${moduleId}/duplicate`,
                {},
                { headers: headers() },
            );
            const newModule = data?.module;
            if (!newModule) {
                reload();
                return;
            }
            const section = findSectionByModuleId(moduleId);
            if (!section) {
                reload();
                return;
            }
            if (!section.modules) section.modules = [];
            const idx = section.modules.findIndex((m) => m.id === moduleId);
            if (idx >= 0) {
                section.modules.splice(idx + 1, 0, newModule);
            } else {
                section.modules.push(newModule);
            }
            modulosSelectedSectionId.value = section.id;
            modulosSelectedModuleId.value = newModule.id;
            previewKey.value++;
        },
    });
}
async function deleteLesson(lessonId) {
    openConfirmModal({
        title: 'Remover aula',
        message: 'Remover esta aula?',
        confirmLabel: 'Remover',
        onConfirm: async () => {
            await axios.delete(`${base.value}/lessons/${lessonId}`, { headers: headers() });
            reload();
        },
    });
}

async function duplicateLesson(lessonId) {
    openConfirmModal({
        title: 'Duplicar aula',
        message: 'Criar uma cópia desta aula no mesmo módulo?',
        confirmLabel: 'Duplicar',
        danger: false,
        onConfirm: async () => {
            const { data } = await axios.post(
                `${base.value}/lessons/${lessonId}/duplicate`,
                {},
                { headers: headers() },
            );
            const newLesson = data?.lesson;
            if (!newLesson) {
                reload();
                return;
            }
            const located = findModuleByLessonId(lessonId);
            if (!located) {
                reload();
                return;
            }
            const { section, module } = located;
            if (!module.lessons) module.lessons = [];
            const idx = module.lessons.findIndex((l) => l.id === lessonId);
            if (idx >= 0) {
                module.lessons.splice(idx + 1, 0, newLesson);
            } else {
                module.lessons.push(newLesson);
            }
            modulosSelectedSectionId.value = section.id;
            modulosSelectedModuleId.value = module.id;
            previewKey.value++;
        },
    });
}
async function addInternalProduct() {
    const id = prompt('ID do produto relacionado:');
    if (!id) return;
    const relatedId = parseInt(id, 10);
    if (!relatedId) return;
    try {
        await axios.post(`${base.value}/internal-products`, { related_product_id: relatedId }, { headers: headers() });
        reload();
    } catch (_) {}
}
async function removeInternalProduct(internalProductId) {
    try {
        await axios.delete(`${base.value}/internal-products/${internalProductId}`, { headers: headers() });
        reload();
    } catch (_) {}
}
// Modal Nova/Editar turma
const turmaModalOpen = ref(false);
const turmaModalName = ref('');
const turmaModalEditing = ref(null); // turma sendo editada ou null = nova
const turmaModalSaving = ref(false);
const turmaModalInputRef = ref(null);

function openTurmaModal(editTurma = null) {
    turmaModalEditing.value = editTurma ?? null;
    turmaModalName.value = editTurma ? editTurma.name : '';
    turmaModalOpen.value = true;
    nextTick(() => turmaModalInputRef.value?.focus());
}
function closeTurmaModal() {
    turmaModalOpen.value = false;
    turmaModalEditing.value = null;
    turmaModalName.value = '';
}
async function saveTurmaModal() {
    const name = turmaModalName.value?.trim();
    if (!name) return;
    const editing = turmaModalEditing.value;
    turmaModalSaving.value = true;
    try {
        if (editing) {
            await axios.put(`${base.value}/turmas/${editing.id}`, { name }, { headers: headers() });
        } else {
            await axios.post(`${base.value}/turmas`, { name }, { headers: headers() });
        }
        closeTurmaModal();
        reload();
    } catch (_) {}
    finally {
        turmaModalSaving.value = false;
    }
}

async function addTurma() {
    openTurmaModal();
}
async function deleteTurma(turmaId) {
    openConfirmModal({
        title: 'Remover turma',
        message: 'Remover esta turma?',
        confirmLabel: 'Remover',
        onConfirm: async () => {
            await axios.delete(`${base.value}/turmas/${turmaId}`, { headers: headers() });
            reload();
        },
    });
}
function alunosDisponiveisParaTurma(turma) {
    const productUsers = props.produto.product_users ?? [];
    const inTurma = (turma.users ?? []).map((u) => u.id);
    return productUsers.filter((a) => !inTurma.includes(a.id));
}
function openEditTurma(t) {
    openTurmaModal(t);
}
// Modal Adicionar aluno à turma
const addAlunoModalTurma = ref(null);
const addAlunoModalSaving = ref(false);
const addAlunoModalMode = ref('list'); // 'list' | 'new'
const newAlunoForm = reactive({ name: '', email: '', password: '' });
const newAlunoFormErrors = reactive({ name: '', email: '', password: '' });
const addAlunoModalCreateSaving = ref(false);
function openAddAlunoModal(turma) {
    addAlunoModalTurma.value = turma;
    addAlunoModalMode.value = 'list';
    newAlunoForm.name = '';
    newAlunoForm.email = '';
    newAlunoForm.password = '';
    newAlunoFormErrors.name = '';
    newAlunoFormErrors.email = '';
    newAlunoFormErrors.password = '';
}
function closeAddAlunoModal() {
    addAlunoModalTurma.value = null;
    addAlunoModalMode.value = 'list';
}
function setAddAlunoModalMode(mode) {
    addAlunoModalMode.value = mode;
    if (mode === 'new') {
        newAlunoForm.name = '';
        newAlunoForm.email = '';
        newAlunoForm.password = '';
        newAlunoFormErrors.name = '';
        newAlunoFormErrors.email = '';
        newAlunoFormErrors.password = '';
    }
}
async function createNewAluno() {
    const name = newAlunoForm.name?.trim();
    const email = newAlunoForm.email?.trim();
    const password = newAlunoForm.password;
    newAlunoFormErrors.name = '';
    newAlunoFormErrors.email = '';
    newAlunoFormErrors.password = '';
    if (!name) {
        newAlunoFormErrors.name = 'Nome é obrigatório.';
        return;
    }
    if (!email) {
        newAlunoFormErrors.email = 'E-mail é obrigatório.';
        return;
    }
    if (password && password.length < 6) {
        newAlunoFormErrors.password = 'Senha deve ter no mínimo 6 caracteres.';
        return;
    }
    addAlunoModalCreateSaving.value = true;
    try {
        const payload = { name, email };
        if (password) payload.password = password;
        if (addAlunoModalTurma?.id) payload.turma_id = addAlunoModalTurma.id;
        const res = await axios.post(`${base.value}/alunos`, payload, { headers: headers() });
        if (res.data?.errors) {
            Object.assign(newAlunoFormErrors, res.data.errors);
            return;
        }
        closeAddAlunoModal();
        reload();
    } catch (err) {
        const data = err.response?.data;
        if (data?.errors && typeof data.errors === 'object') {
            const e = data.errors;
            newAlunoFormErrors.name = Array.isArray(e.name) ? e.name[0] : e.name || '';
            newAlunoFormErrors.email = Array.isArray(e.email) ? e.email[0] : e.email || '';
            newAlunoFormErrors.password = Array.isArray(e.password) ? e.password[0] : e.password || '';
        } else {
            newAlunoFormErrors.email = data?.message || 'Erro ao criar aluno. Tente outro e-mail.';
        }
    } finally {
        addAlunoModalCreateSaving.value = false;
    }
}
async function attachTurmaUser(turmaId, userId) {
    if (!userId) return;
    addAlunoModalSaving.value = true;
    try {
        await axios.post(`${base.value}/turmas/${turmaId}/users`, { user_id: userId }, { headers: headers() });
        reload();
        closeAddAlunoModal();
    } catch (_) {}
    finally {
        addAlunoModalSaving.value = false;
    }
}
async function detachTurmaUser(turmaId, userId) {
    try {
        await axios.delete(`${base.value}/turmas/${turmaId}/users/${userId}`, { headers: headers() });
        reload();
    } catch (_) {}
}
// Lista reativa de páginas da comunidade (sidebar + preview usam esta; atualizada ao criar/editar/remover)
const communityPagesList = ref([...(props.produto.community_pages ?? [])]);
watch(() => props.produto.community_pages, (pages) => {
    communityPagesList.value = Array.isArray(pages) ? [...pages] : [];
}, { immediate: true });

// Modal Nova/Editar página da comunidade
const communityPageModalOpen = ref(false);
const communityPageModalEditing = ref(null);
const communityPageModalTitle = ref('');
const communityPageModalIcon = ref('');
const communityPageModalPublic = ref(true);
const communityPageModalDefault = ref(false);
const communityPageModalFeatured = ref(false);
const communityPageModalSaving = ref(false);
const communityPageModalBannerPath = ref('');
const communityPageModalBannerPreviewUrl = ref('');
const communityPageModalBannerFile = ref(null);
const communityPageModalBannerUploading = ref(false);
const communityPageModalBannerInputRef = ref(null);
/** Qual seletor está aberto: 'emoji' | 'icon' | null */
const communityPageIconPickerOpen = ref(null);

function openCommunityPageModal(page = null) {
    const editing = page && page.id != null ? page : null;
    communityPageModalEditing.value = editing;
    if (editing) {
        communityPageModalTitle.value = editing.title ?? '';
        communityPageModalIcon.value = editing.icon ?? '';
        communityPageModalPublic.value = editing.is_public_posting !== false;
        communityPageModalDefault.value = editing.is_default === true;
        communityPageModalFeatured.value = editing.is_featured === true;
        communityPageModalBannerPath.value = editing.banner ?? '';
        communityPageModalBannerPreviewUrl.value = editing.banner_url ?? '';
        communityPageModalBannerFile.value = null;
    } else {
        communityPageModalTitle.value = '';
        communityPageModalIcon.value = '';
        communityPageModalPublic.value = true;
        communityPageModalDefault.value = false;
        communityPageModalFeatured.value = false;
        communityPageModalBannerPath.value = '';
        communityPageModalBannerPreviewUrl.value = '';
        communityPageModalBannerFile.value = null;
    }
    communityPageModalOpen.value = true;
}
function closeCommunityPageModal() {
    communityPageModalOpen.value = false;
    communityPageModalEditing.value = null;
    communityPageModalTitle.value = '';
    communityPageModalIcon.value = '';
    communityPageModalDefault.value = false;
    communityPageModalFeatured.value = false;
    communityPageIconPickerOpen.value = null;
    communityPageModalBannerPath.value = '';
    communityPageModalBannerPreviewUrl.value = '';
    communityPageModalBannerFile.value = null;
}
function setCommunityPageModalEmoji(emoji) {
    communityPageModalIcon.value = emoji;
    communityPageIconPickerOpen.value = null;
}
function setCommunityPageModalIcon(name) {
    communityPageModalIcon.value = 'icon:' + name;
    communityPageIconPickerOpen.value = null;
}
function openIconPicker(type) {
    communityPageIconPickerOpen.value = communityPageIconPickerOpen.value === type ? null : type;
}
const communityPageModalIconComponent = computed(() => getCommunityPageIconComponent(communityPageModalIcon.value));
async function onCommunityPageBannerChange(event) {
    const file = event.target?.files?.[0];
    if (!file) return;
    communityPageModalBannerFile.value = file;
    communityPageModalBannerPreviewUrl.value = URL.createObjectURL(file);
    communityPageModalBannerUploading.value = true;
    try {
        const formData = new FormData();
        formData.append('file', file);
        const { data } = await axios.post(uploadUrl.value, formData, { headers: uploadHeaders() });
        communityPageModalBannerPath.value = data.path ?? '';
    } catch (_) {
        communityPageModalBannerPath.value = '';
        communityPageModalBannerPreviewUrl.value = '';
    } finally {
        communityPageModalBannerUploading.value = false;
    }
}
function clearCommunityPageBanner() {
    communityPageModalBannerPath.value = '';
    communityPageModalBannerPreviewUrl.value = '';
    communityPageModalBannerFile.value = null;
}
async function saveCommunityPageModal() {
    const title = communityPageModalTitle.value?.trim();
    if (!title) return;
    const editing = communityPageModalEditing.value;
    communityPageModalSaving.value = true;
    try {
        let banner = communityPageModalBannerPath.value || null;
        if (communityPageModalBannerFile.value && !banner) {
            const formData = new FormData();
            formData.append('file', communityPageModalBannerFile.value);
            const { data } = await axios.post(uploadUrl.value, formData, { headers: uploadHeaders() });
            banner = data.path ?? data.url ?? null;
        }
        const payload = {
            title,
            icon: communityPageModalIcon.value || null,
            banner: banner || null,
            is_public_posting: communityPageModalPublic.value,
            is_default: communityPageModalDefault.value,
            is_featured: communityPageModalFeatured.value,
        };
        let res;
        if (editing?.id != null) {
            res = await axios.put(`${base.value}/community-pages/${editing.id}`, payload, { headers: headers() });
        } else {
            res = await axios.post(`${base.value}/community-pages`, payload, { headers: headers() });
        }
        if (Array.isArray(res?.data?.community_pages)) {
            communityPagesList.value = res.data.community_pages;
        }
        closeCommunityPageModal();
    } catch (err) {
        const msg = err?.response?.data?.message ?? err?.response?.data?.errors ?? err?.message ?? 'Erro ao salvar.';
        alert(Array.isArray(msg) ? Object.values(msg).flat().join('\n') : msg);
    } finally {
        communityPageModalSaving.value = false;
    }
}

async function deleteCommunityPage(pageId) {
    openConfirmModal({
        title: 'Remover página',
        message: 'Remover esta página e todos os posts?',
        confirmLabel: 'Remover',
        onConfirm: async () => {
            const res = await axios.delete(`${base.value}/community-pages/${pageId}`, {
                headers: { ...headers(), Accept: 'application/json' },
            });
            if (Array.isArray(res?.data?.community_pages)) {
                communityPagesList.value = res.data.community_pages;
            } else {
                communityPagesList.value = communityPagesList.value.filter((p) => p.id !== pageId);
            }
        },
    });
}

const inputClass = 'block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200';
</script>

<template>
    <div class="flex h-screen flex-col bg-zinc-100 dark:bg-zinc-950">
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
                    v-if="produto.member_area_url"
                    :href="produto.member_area_url"
                    target="_blank"
                    rel="noopener"
                    class="hidden items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-sm text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800 sm:flex"
                >
                    <ExternalLink class="h-4 w-4" />
                    Ver área
                </a>
                <a
                    :href="`/produtos/${produto.id}/edit?tab=geral`"
                    class="flex h-9 w-9 items-center justify-center rounded-lg text-zinc-500 transition hover:bg-zinc-200 hover:text-zinc-900 dark:hover:bg-zinc-700 dark:hover:text-zinc-100"
                    title="Fechar Member Builder"
                >
                    <X class="h-5 w-5" />
                </a>
            </div>
        </header>

        <!-- Conteúdo: sidebar config + preview (preview só em lg+) -->
        <div :class="['flex min-h-0 flex-1 flex-col overflow-hidden', effectiveShowPreview ? 'lg:flex-row' : '']">
            <aside
                :class="[
                    'flex min-h-0 min-w-0 flex-col border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900',
                    'lg:shrink-0',
                    effectiveShowPreview && activeTab !== 'modulos' ? 'lg:w-80 lg:border-b-0 lg:border-r lg:overflow-y-auto' : 'flex-1 w-full min-w-0 overflow-hidden',
                    activeTab === 'modulos' ? 'flex-1' : '',
                ]"
            >
                <!-- Container rolável: garante scroll no mobile (altura limitada) -->
                <div
                    class="min-h-0 min-w-0 flex-1 overflow-y-auto overflow-x-hidden"
                    style="-webkit-overflow-scrolling: touch"
                >
                <div class="min-w-0 p-4">
                    <template v-if="activeTab === 'aparencia'">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tema e hero</h2>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Logo do header</label>
                                <input
                                    ref="headerLogoFileInput"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onHeaderLogoChange"
                                />
                                <div class="flex flex-col gap-2">
                                    <div v-if="configForm.member_area_config.header?.logo_url" class="relative">
                                        <img :src="configForm.member_area_config.header.logo_url" alt="Logo" class="h-14 w-auto max-w-[140px] rounded object-contain" />
                                        <div class="mt-1 flex gap-2">
                                            <Button type="button" size="sm" variant="outline" :disabled="headerLogoUploading" @click="headerLogoFileInput?.click()">
                                                Trocar
                                            </Button>
                                            <Button type="button" size="sm" variant="ghost" class="text-red-600" :disabled="headerLogoUploading" @click="removeHeaderLogo">
                                                Remover
                                            </Button>
                                        </div>
                                    </div>
                                    <template v-else>
                                        <Button type="button" variant="outline" size="sm" :disabled="headerLogoUploading" @click="headerLogoFileInput?.click()">
                                            {{ headerLogoUploading ? 'Enviando…' : 'Enviar logo do header' }}
                                        </Button>
                                    </template>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Tamanho ideal: 180×40 px (ou proporção similar). PNG ou SVG com fundo transparente. Máx. {{ uploadLimits.image_max_mb }} MB.</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Favicon (ícone da aba do navegador)</label>
                                <input ref="faviconFileInput" type="file" accept="image/*" class="hidden" @change="onFaviconChange" />
                                <div class="flex flex-col gap-2">
                                    <div v-if="configForm.member_area_config.logos?.favicon" class="flex items-center gap-3">
                                        <img :src="configForm.member_area_config.logos.favicon" alt="Favicon" class="h-14 w-14 rounded-xl border border-zinc-200 object-cover dark:border-zinc-600" />
                                        <div class="flex gap-2">
                                            <Button type="button" size="sm" variant="outline" :disabled="faviconUploading" @click="faviconFileInput?.click()">Trocar</Button>
                                            <Button type="button" size="sm" variant="ghost" class="text-red-600" :disabled="faviconUploading" @click="removeFavicon">Remover</Button>
                                        </div>
                                    </div>
                                    <Button v-else type="button" variant="outline" size="sm" :disabled="faviconUploading" @click="faviconFileInput?.click()">
                                        {{ faviconUploading ? 'Enviando…' : 'Enviar favicon (192×192 ou 512×512)' }}
                                    </Button>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Usado na aba do navegador e no PWA. Tamanho ideal: 192×192 ou 512×512 px. Máx. {{ uploadLimits.image_max_mb }} MB.</p>
                            </div>
                            <div>
                                <input v-model="configForm.member_area_config.theme.primary" type="color" class="h-9 w-full cursor-pointer rounded-lg border dark:border-zinc-600" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Fundo</label>
                                <input v-model="configForm.member_area_config.theme.background" type="color" class="h-9 w-full cursor-pointer rounded-lg border dark:border-zinc-600" />
                            </div>
                            <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <div class="flex items-center justify-between gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Banner do hero (carrossel)</label>
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Adicione uma ou mais imagens. Com 2+, vira carrossel com troca automática.</p>
                                    </div>
                                    <Button type="button" size="sm" variant="outline" @click="addHeroSlide">
                                        <Plus class="mr-1 h-3.5 w-3.5" /> Slide
                                    </Button>
                                </div>

                                <div
                                    v-if="!(configForm.member_area_config.hero.slides?.length)"
                                    class="rounded-lg border border-dashed border-zinc-300 px-3 py-6 text-center text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400"
                                >
                                    Nenhum slide ainda. Clique em “Slide” para enviar a primeira imagem.
                                </div>

                                <div
                                    v-for="(slide, index) in configForm.member_area_config.hero.slides"
                                    :key="slide.id || index"
                                    class="space-y-3 rounded-lg border border-zinc-200 bg-zinc-50/70 p-3 dark:border-zinc-700 dark:bg-zinc-900/40"
                                >
                                    <div class="flex items-center justify-between gap-2">
                                        <span class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Slide {{ index + 1 }}</span>
                                        <div class="flex items-center gap-1">
                                            <button
                                                type="button"
                                                class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-200 disabled:opacity-40 dark:hover:bg-zinc-800"
                                                title="Mover para cima"
                                                :disabled="index === 0"
                                                @click="moveHeroSlide(index, -1)"
                                            >
                                                <ChevronUp class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-200 disabled:opacity-40 dark:hover:bg-zinc-800"
                                                title="Mover para baixo"
                                                :disabled="index === configForm.member_area_config.hero.slides.length - 1"
                                                @click="moveHeroSlide(index, 1)"
                                            >
                                                <ChevronDown class="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                class="rounded-lg p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30"
                                                title="Remover slide"
                                                @click="removeHeroSlide(index)"
                                            >
                                                <Trash2 class="h-4 w-4" />
                                            </button>
                                        </div>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Imagem desktop</label>
                                        <input
                                            :ref="(el) => setHeroSlideFileInput(`${slide.id}:image_url_desktop`, el)"
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            @change="onHeroSlideImageChange($event, index, 'image_url_desktop')"
                                        />
                                        <div v-if="slide.image_url_desktop" class="space-y-1">
                                            <img :src="slide.image_url_desktop" alt="" class="h-24 w-full rounded-lg object-cover" />
                                            <div class="flex gap-2">
                                                <Button type="button" size="sm" variant="outline" :disabled="!!heroSlideUploading[`${slide.id}:image_url_desktop`]" @click="heroSlideFileInputs[`${slide.id}:image_url_desktop`]?.click()">
                                                    Trocar
                                                </Button>
                                                <Button type="button" size="sm" variant="ghost" class="text-red-600" @click="removeHeroSlideImage(index, 'image_url_desktop')">
                                                    Remover
                                                </Button>
                                            </div>
                                        </div>
                                        <Button
                                            v-else
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            :disabled="!!heroSlideUploading[`${slide.id}:image_url_desktop`]"
                                            @click="heroSlideFileInputs[`${slide.id}:image_url_desktop`]?.click()"
                                        >
                                            {{ heroSlideUploading[`${slide.id}:image_url_desktop`] ? 'Enviando…' : 'Enviar desktop (1920×600)' }}
                                        </Button>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Imagem mobile (opcional)</label>
                                        <input
                                            :ref="(el) => setHeroSlideFileInput(`${slide.id}:image_url_mobile`, el)"
                                            type="file"
                                            accept="image/*"
                                            class="hidden"
                                            @change="onHeroSlideImageChange($event, index, 'image_url_mobile')"
                                        />
                                        <div v-if="slide.image_url_mobile" class="space-y-1">
                                            <img :src="slide.image_url_mobile" alt="" class="h-24 w-full rounded-lg object-cover" />
                                            <div class="flex gap-2">
                                                <Button type="button" size="sm" variant="outline" :disabled="!!heroSlideUploading[`${slide.id}:image_url_mobile`]" @click="heroSlideFileInputs[`${slide.id}:image_url_mobile`]?.click()">
                                                    Trocar
                                                </Button>
                                                <Button type="button" size="sm" variant="ghost" class="text-red-600" @click="removeHeroSlideImage(index, 'image_url_mobile')">
                                                    Remover
                                                </Button>
                                            </div>
                                        </div>
                                        <Button
                                            v-else
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            :disabled="!!heroSlideUploading[`${slide.id}:image_url_mobile`]"
                                            @click="heroSlideFileInputs[`${slide.id}:image_url_mobile`]?.click()"
                                        >
                                            {{ heroSlideUploading[`${slide.id}:image_url_mobile`] ? 'Enviando…' : 'Enviar mobile (800×900)' }}
                                        </Button>
                                        <p class="mt-1 text-xs text-zinc-500">Se vazio, usa a imagem desktop neste slide.</p>
                                    </div>

                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título do slide</label>
                                        <input v-model="slide.title" type="text" :class="inputClass" placeholder="Opcional (usa o título geral se vazio)" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Subtítulo do slide</label>
                                        <input v-model="slide.subtitle" type="text" :class="inputClass" placeholder="Opcional" />
                                    </div>
                                </div>

                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Máx. {{ uploadLimits.image_max_mb }} MB por imagem.</p>
                            </div>

                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título geral do hero</label>
                                <input v-model="configForm.member_area_config.hero.title" type="text" :class="inputClass" placeholder="Usado quando o slide não tem título" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Subtítulo geral</label>
                                <input v-model="configForm.member_area_config.hero.subtitle" type="text" :class="inputClass" placeholder="Subtítulo" />
                            </div>
                            <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Intensidade do overlay (0-100%)</label>
                                <input v-model.number="configForm.member_area_config.hero.overlay_opacity" type="range" min="0" max="100" class="w-full" />
                                <span class="text-xs text-zinc-500">{{ Math.round((configForm.member_area_config.hero.overlay_opacity ?? 50)) }}%</span>
                            </div>
                            <div class="space-y-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <Toggle
                                    :model-value="configForm.member_area_config.hero.autoplay !== false"
                                    label="Play automático do carrossel"
                                    @update:model-value="(v) => { configForm.member_area_config.hero.autoplay = v; }"
                                />
                                <div v-if="configForm.member_area_config.hero.autoplay !== false">
                                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Intervalo (segundos)</label>
                                    <input
                                        v-model.number="configForm.member_area_config.hero.autoplay_interval"
                                        type="number"
                                        min="2"
                                        max="30"
                                        :class="inputClass"
                                    />
                                    <p class="mt-1 text-xs text-zinc-500">Entre 2 e 30 segundos. Pausa ao passar o mouse.</p>
                                </div>
                            </div>
                        </div>
                        <Button type="button" class="mt-4" @click="saveConfig" :disabled="processing">Salvar</Button>
                    </template>

                    <template v-else-if="activeTab === 'header'">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Menus do header</h2>
                        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">Itens exibidos no header da área de membros, ao lado da logo. O link pode ser interno (ex: /modulos) ou externo (marque "Abrir em nova aba").</p>
                        <div class="space-y-4">
                            <div
                                v-for="(item, index) in headerItems"
                                :key="index"
                                class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700"
                            >
                                <div class="mb-2 flex items-center justify-between">
                                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Item {{ index + 1 }}</span>
                                    <Button type="button" size="sm" variant="ghost" class="text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" @click="removeHeaderItem(index)">
                                        <Trash2 class="h-4 w-4" />
                                    </Button>
                                </div>
                                <div class="space-y-3">
                                    <div>
                                        <label class="mb-0.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título</label>
                                        <input v-model="item.title" type="text" :class="inputClass" placeholder="Ex: Início, Módulos" />
                                    </div>
                                    <div>
                                        <label class="mb-0.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Link</label>
                                        <input v-model="item.link" type="text" :class="inputClass" placeholder="Ex: / ou /modulos ou https://..." />
                                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Interno: use / ou /modulos. Externo: URL completa.</p>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input
                                            v-model="item.open_external"
                                            type="checkbox"
                                            :id="'header-external-' + index"
                                            class="h-4 w-4 rounded border-zinc-300 dark:border-zinc-600"
                                        />
                                        <label :for="'header-external-' + index" class="text-sm text-zinc-600 dark:text-zinc-400">Abrir em nova aba (link externo)</label>
                                    </div>
                                </div>
                            </div>
                            <Button type="button" variant="outline" size="sm" class="w-full" @click="addHeaderItem">
                                <Plus class="mr-2 h-4 w-4" /> Adicionar menu
                            </Button>
                        </div>
                        <Button type="button" class="mt-4" @click="saveConfig" :disabled="processing">Salvar</Button>
                    </template>

                    <template v-else-if="activeTab === 'login'">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Tela de login</h2>
                        <div class="mb-5">
                            <p class="mb-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">Template</p>
                            <div class="grid gap-3 sm:grid-cols-2">
                                <button
                                    type="button"
                                    :class="[
                                        'rounded-xl border p-4 text-left transition',
                                        (configForm.member_area_config.login.template || 'v1') === 'v1'
                                            ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 ring-1 ring-[var(--color-primary)]'
                                            : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600',
                                    ]"
                                    @click="configForm.member_area_config.login.template = 'v1'"
                                >
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Template v1</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Card centralizado (atual)</p>
                                </button>
                                <button
                                    type="button"
                                    :class="[
                                        'rounded-xl border p-4 text-left transition',
                                        configForm.member_area_config.login.template === 'v2'
                                            ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 ring-1 ring-[var(--color-primary)]'
                                            : 'border-zinc-200 hover:border-zinc-300 dark:border-zinc-700 dark:hover:border-zinc-600',
                                    ]"
                                    @click="configForm.member_area_config.login.template = 'v2'"
                                >
                                    <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">Template v2</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Split estilo plataforma — login à direita</p>
                                </button>
                            </div>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Logo</label>
                                <input ref="loginLogoFileInput" type="file" accept="image/*" class="hidden" @change="onLoginLogoChange" />
                                <div v-if="configForm.member_area_config.login.logo" class="flex items-center gap-2">
                                    <img :src="configForm.member_area_config.login.logo" alt="Logo login" class="h-14 w-auto max-w-[140px] rounded-lg object-contain bg-zinc-100 dark:bg-zinc-800" />
                                    <div class="flex gap-2">
                                        <Button type="button" size="sm" variant="outline" :disabled="loginLogoUploading" @click="loginLogoFileInput?.click()">Trocar</Button>
                                        <Button type="button" size="sm" variant="outline" @click="removeLoginLogo">Remover</Button>
                                    </div>
                                </div>
                                <Button v-else type="button" size="sm" variant="outline" :disabled="loginLogoUploading" @click="loginLogoFileInput?.click()">
                                    Enviar logo
                                </Button>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                    {{ configForm.member_area_config.login.template === 'v2' ? 'Imagem do hero (lado esquerdo)' : 'Imagem de fundo' }}
                                </label>
                                <input ref="loginBackgroundFileInput" type="file" accept="image/*" class="hidden" @change="onLoginBackgroundChange" />
                                <div v-if="configForm.member_area_config.login.background_image" class="space-y-2">
                                    <img :src="configForm.member_area_config.login.background_image" alt="Fundo login" class="h-24 w-full rounded-lg object-cover bg-zinc-100 dark:bg-zinc-800" />
                                    <div class="flex gap-2">
                                        <Button type="button" size="sm" variant="outline" :disabled="loginBackgroundUploading" @click="loginBackgroundFileInput?.click()">Trocar</Button>
                                        <Button type="button" size="sm" variant="outline" @click="removeLoginBackground">Remover</Button>
                                    </div>
                                </div>
                                <Button v-else type="button" size="sm" variant="outline" :disabled="loginBackgroundUploading" @click="loginBackgroundFileInput?.click()">
                                    Enviar imagem de fundo
                                </Button>
                            </div>
                            <div v-if="configForm.member_area_config.login.background_image" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Intensidade do overlay (0-100%)</label>
                                <input v-model.number="configForm.member_area_config.login.background_overlay_opacity" type="range" min="0" max="100" class="w-full" />
                                <span class="text-xs text-zinc-500">{{ Math.round((configForm.member_area_config.login.background_overlay_opacity ?? 50)) }}%</span>
                            </div>
                            <div v-if="configForm.member_area_config.login.template !== 'v2'">
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor de fundo (sem imagem)</label>
                                <div class="flex items-center gap-2">
                                    <input v-model="configForm.member_area_config.login.background_color" type="color" class="h-9 w-20 cursor-pointer rounded-lg border dark:border-zinc-600" />
                                    <input v-model="configForm.member_area_config.login.background_color" type="text" :class="inputClass" class="flex-1 font-mono text-sm" placeholder="#18181b" />
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
                        <Button type="button" class="mt-4" @click="saveConfig" :disabled="processing">Salvar</Button>
                    </template>

                    <template v-else-if="activeTab === 'modulos'">
                        <input ref="moduleThumbnailFileInput" type="file" accept="image/*" class="hidden" @change="onModuleThumbnailChange" />
                        <input ref="lessonPdfFileInput" type="file" accept=".pdf,application/pdf" multiple class="hidden" @change="onLessonPdfChange" />
                        <input ref="lessonSupportFileInput" type="file" accept=".pdf,application/pdf" multiple class="hidden" @change="onSupportPdfChange" />
                        <MemberBuilderModulesTab
                            v-model:sections="courseStructureSections"
                            v-model:selected-section-id="modulosSelectedSectionId"
                            v-model:selected-module-id="modulosSelectedModuleId"
                            v-model:preview-open="modulosPreviewOpen"
                            :tenant-products="tenant_products"
                            :upload-limits="uploadLimits"
                            :input-class="inputClass"
                            :member-reorder-saving="memberReorderSaving"
                            :section-type-label="sectionTypeLabel"
                            :lesson-form="modulosLessonForm"
                            :lesson-form-saving="modulosLessonFormSaving"
                            :module-form-saving="modulosModuleFormSaving"
                            :lesson-pdf-uploading="lessonPdfUploading"
                            :lesson-support-uploading="lessonSupportUploading"
                            :is-lesson-pdf-content-type="isLessonPdfContentType"
                            :pdf-lesson-file-label="pdfLessonFileLabel"
                            :editing-section-id="editingSectionId"
                            :editing-section-title="editingSectionTitle"
                            :editing-section-cover-mode="editingSectionCoverMode"
                            :editing-module-id="editingModuleId"
                            :editing-module-title="editingModuleTitle"
                            :editing-module-show-title-on-cover="editingModuleShowTitleOnCover"
                            :editing-module-related-product-id="editingModuleRelatedProductId"
                            :editing-module-access-type="editingModuleAccessType"
                            :editing-module-external-url="editingModuleExternalUrl"
                            :editing-module-release-mode="editingModuleReleaseMode"
                            :editing-module-release-after-days="editingModuleReleaseAfterDays"
                            :editing-module-release-at-date="editingModuleReleaseAtDate"
                            :editing-module-release-progress-percent="editingModuleReleaseProgressPercent"
                            :editing-module-release-required-module-ids="editingModuleReleaseRequiredModuleIds"
                            :editing-module-access-duration-days="editingModuleAccessDurationDays"
                            :editing-module-thumbnail="editingModule?.thumbnail"
                            :module-thumbnail-uploading="moduleThumbnailUploading"
                            @open-section-modal="openSectionModal"
                            @open-module-modal="openModuleModal"
                            @delete-section="deleteSection"
                            @duplicate-section="duplicateSection"
                            @delete-module="deleteModule"
                            @duplicate-module="duplicateModule"
                            @sections-reorder-end="onMemberSectionsReorderEnd"
                            @modules-reorder-end="onMemberModulesReorderEnd"
                            @lessons-reorder-end="onMemberLessonsReorderEnd"
                            @edit-section="handleModulesTabEditSection"
                            @save-section="saveSectionTitle"
                            @cancel-section-edit="cancelEdit"
                            @save-module="saveModuleTitle"
                            @open-module-edit="handleModulesTabOpenModuleEdit"
                            @open-lesson-form="openModulosLessonForm"
                            @close-lesson-form="closeModulosLessonForm"
                            @save-lesson="saveLessonFromSidebar"
                            @delete-lesson="deleteLesson"
                            @duplicate-lesson="duplicateLesson"
                            @pick-lesson-pdf="lessonPdfFileInput?.click()"
                            @pick-support-pdf="lessonSupportFileInput?.click()"
                            @pick-module-thumbnail="moduleThumbnailFileInput?.click()"
                            @remove-module-thumbnail="removeModuleThumbnail"
                            @set-module-show-title-on-cover="setModuleShowTitleOnCover"
                            @clear-lesson-pdf="clearLessonPdf"
                            @remove-lesson-pdf-at="removeLessonPdfAt"
                            @clear-support-files="clearSupportFiles"
                            @remove-support-file-at="removeSupportFileAt"
                            @add-useful-link="addUsefulLink"
                            @remove-useful-link-at="removeUsefulLinkAt"
                            @update:editing-section-title="editingSectionTitle = $event"
                            @update:editing-section-cover-mode="editingSectionCoverMode = $event"
                            @update:editing-module-title="editingModuleTitle = $event"
                            @update:editing-module-show-title-on-cover="editingModuleShowTitleOnCover = $event"
                            @update:editing-module-related-product-id="editingModuleRelatedProductId = $event"
                            @update:editing-module-access-type="editingModuleAccessType = $event"
                            @update:editing-module-external-url="editingModuleExternalUrl = $event"
                            @update:editing-module-release-mode="editingModuleReleaseMode = $event"
                            @update:editing-module-release-after-days="editingModuleReleaseAfterDays = $event"
                            @update:editing-module-release-at-date="editingModuleReleaseAtDate = $event"
                            @update:editing-module-release-progress-percent="editingModuleReleaseProgressPercent = $event"
                            @update:editing-module-release-required-module-ids="editingModuleReleaseRequiredModuleIds = $event"
                            @update:editing-module-access-duration-days="editingModuleAccessDurationDays = $event"
                        />
                    </template>

                    <template v-else-if="activeTab === 'turmas'">
                        <div class="flex flex-col gap-6 lg:flex-row lg:gap-8">
                            <!-- Coluna esquerda: ações e referência -->
                            <div class="shrink-0 space-y-6 lg:w-72">
                                <div>
                                    <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Turmas e alunos</h2>
                                    <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">Organize os alunos em turmas. Alunos são quem têm acesso ao produto (compradores ou adicionados manualmente).</p>
                                    <Button class="w-full" size="sm" @click="addTurma">
                                        <Plus class="mr-2 h-4 w-4" />
                                        Nova turma
                                    </Button>
                                </div>
                                <div v-if="produto.product_users?.length" class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/30">
                                    <h3 class="mb-1 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Alunos com acesso</h3>
                                    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Usuários com acesso a esta área (Produtos → Alunos).</p>
                                    <ul class="max-h-44 space-y-1.5 overflow-y-auto text-xs text-zinc-600 dark:text-zinc-400">
                                        <li v-for="a in produto.product_users" :key="a.id" class="truncate rounded-md py-1 px-2 hover:bg-zinc-100 dark:hover:bg-zinc-700/50">{{ a.name || a.email }} · {{ a.email }}</li>
                                    </ul>
                                </div>
                            </div>
                            <!-- Coluna direita: lista de turmas -->
                            <div class="min-w-0 flex-1">
                                <div class="space-y-4">
                                    <div
                                        v-for="t in produto.turmas"
                                        :key="t.id"
                                        class="rounded-xl border border-zinc-200 bg-white shadow-sm transition dark:border-zinc-600 dark:bg-zinc-800/50 dark:shadow-none"
                                    >
                                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-4 py-3 dark:border-zinc-600/80">
                                            <div class="flex min-w-0 items-center gap-2">
                                                <span class="min-w-0 truncate font-semibold text-zinc-900 dark:text-zinc-100">{{ t.name }}</span>
                                                <span class="shrink-0 rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-300">
                                                    {{ (t.users ?? []).length }} {{ (t.users ?? []).length === 1 ? 'aluno' : 'alunos' }}
                                                </span>
                                            </div>
                                            <div class="flex shrink-0 items-center gap-1">
                                                <button type="button" class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" title="Editar turma" @click="openEditTurma(t)"><Pencil class="h-4 w-4" /></button>
                                                <button type="button" class="rounded-lg p-2 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover turma" @click="deleteTurma(t.id)"><Trash2 class="h-4 w-4" /></button>
                                            </div>
                                        </div>
                                        <div class="p-4">
                                            <ul class="space-y-2">
                                                <li
                                                    v-for="u in (t.users ?? [])"
                                                    :key="u.id"
                                                    class="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 bg-zinc-50/80 py-2.5 px-3 text-sm dark:border-zinc-700/50 dark:bg-zinc-800/30"
                                                >
                                                    <span class="min-w-0 truncate font-medium text-zinc-800 dark:text-zinc-200">{{ u.name || u.email }}</span>
                                                    <button type="button" class="shrink-0 rounded-md px-2 py-1 text-xs font-medium text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" @click="detachTurmaUser(t.id, u.id)">Remover</button>
                                                </li>
                                            </ul>
                                            <div v-if="!t.users?.length" class="rounded-lg border border-dashed border-zinc-200 py-6 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Nenhum aluno nesta turma.</div>
                                            <div class="mt-3">
                                                <Button size="sm" variant="outline" class="w-full sm:w-auto" @click.stop="openAddAlunoModal(t)">
                                                    <Plus class="mr-1.5 h-3.5 w-3.5" />
                                                    Adicionar aluno
                                                </Button>
                                                <p v-if="!alunosDisponiveisParaTurma(t).length && produto.product_users?.length" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Todos os alunos já estão nesta turma.</p>
                                                <p v-if="!produto.product_users?.length" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Nenhum aluno com acesso. Adicione em Produtos → Alunos.</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div v-if="!produto.turmas?.length" class="rounded-xl border border-dashed border-zinc-200 py-12 text-center dark:border-zinc-600">
                                    <Users class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                                    <p class="mt-3 text-sm font-medium text-zinc-600 dark:text-zinc-400">Nenhuma turma ainda</p>
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Crie uma turma para organizar os alunos.</p>
                                    <Button size="sm" class="mt-4" @click="addTurma">
                                        <Plus class="mr-2 h-4 w-4" />
                                        Nova turma
                                    </Button>
                                </div>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="activeTab === 'progresso'">
                        <div class="space-y-6">
                            <div>
                                <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Progresso dos alunos</h2>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Aulas concluídas na área do membro (marcadas como concluídas pelo aluno). O total de aulas inclui todos os módulos do produto.
                                </p>
                            </div>
                            <div class="flex flex-wrap gap-4 rounded-xl border border-zinc-200 bg-zinc-50/50 px-4 py-3 text-sm dark:border-zinc-600 dark:bg-zinc-800/30">
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">Alunos com acesso</span>
                                    <p class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ studentProgressRows.length }}</p>
                                </div>
                                <div class="hidden h-10 w-px bg-zinc-200 dark:bg-zinc-600 sm:block" aria-hidden="true" />
                                <div>
                                    <span class="text-zinc-500 dark:text-zinc-400">Aulas no curso</span>
                                    <p class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ totalLessonsProgress }}</p>
                                </div>
                            </div>
                            <div
                                v-if="totalLessonsProgress === 0"
                                class="rounded-xl border border-dashed border-amber-200 bg-amber-50/50 px-4 py-6 text-center text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/20 dark:text-amber-200"
                            >
                                Nenhuma aula cadastrada nos módulos. Adicione aulas na aba Módulos para acompanhar o progresso.
                            </div>
                            <div
                                v-else-if="!studentProgressRows.length"
                                class="rounded-xl border border-dashed border-zinc-200 py-12 text-center dark:border-zinc-600"
                            >
                                <BarChart3 class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                                <p class="mt-3 text-sm font-medium text-zinc-600 dark:text-zinc-400">Nenhum aluno com acesso</p>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Quem compra ou é adicionado em Produtos → Alunos aparece aqui.</p>
                            </div>
                            <div v-else class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-600">
                                <table class="w-full min-w-[640px] text-left text-sm">
                                    <thead>
                                        <tr class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800/50">
                                            <th class="px-4 py-3 font-semibold text-zinc-700 dark:text-zinc-200">Aluno</th>
                                            <th class="px-4 py-3 font-semibold text-zinc-700 dark:text-zinc-200">E-mail</th>
                                            <th class="px-4 py-3 font-semibold text-zinc-700 dark:text-zinc-200">Aulas</th>
                                            <th class="min-w-[180px] px-4 py-3 font-semibold text-zinc-700 dark:text-zinc-200">Progresso</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700/80">
                                        <tr
                                            v-for="row in studentProgressRows"
                                            :key="row.id"
                                            class="bg-white dark:bg-zinc-900/40"
                                        >
                                            <td class="px-4 py-3 font-medium text-zinc-900 dark:text-zinc-100">{{ row.name || '—' }}</td>
                                            <td class="max-w-[220px] truncate px-4 py-3 text-zinc-600 dark:text-zinc-400" :title="row.email">{{ row.email }}</td>
                                            <td class="whitespace-nowrap px-4 py-3 text-zinc-700 dark:text-zinc-300">
                                                {{ row.completed_count }} / {{ row.total_lessons }}
                                            </td>
                                            <td class="px-4 py-3">
                                                <div class="flex items-center gap-3">
                                                    <div class="h-2 min-w-0 flex-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
                                                        <div
                                                            class="h-full rounded-full bg-[var(--color-primary)] transition-[width] duration-300"
                                                            :style="{ width: `${row.percent}%` }"
                                                        />
                                                    </div>
                                                    <span class="w-10 shrink-0 text-right text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ row.percent }}%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="activeTab === 'comentarios'">
                        <div class="flex flex-col gap-6 lg:flex-row lg:gap-8">
                            <!-- Coluna esquerda: configurações -->
                            <div class="shrink-0 space-y-6 lg:w-72">
                                <div>
                                    <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Configurações</h2>
                                    <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">Permita que os alunos comentem nas aulas e defina se a aprovação é obrigatória.</p>
                                    <div class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/30">
                                        <Toggle v-model="configForm.member_area_config.comments_enabled" label="Ativar comentários nas aulas" />
                                        <Toggle v-model="configForm.member_area_config.comments_require_approval" label="Comentários exigem aprovação" />
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Se ativo, os comentários só aparecem após você aprovar na lista ao lado.</p>
                                    </div>
                                    <Button type="button" class="mt-4 w-full" @click="saveConfig" :disabled="processing">Salvar alterações</Button>
                                </div>
                            </div>
                            <!-- Coluna direita: ver e aprovar comentários -->
                            <div class="min-w-0 flex-1">
                                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Ver e aprovar comentários</h2>
                                <div class="mb-4 flex flex-wrap gap-2">
                                    <button
                                        v-for="opt in [{ value: 'all', label: 'Todos' }, { value: 'pending', label: 'Pendentes' }, { value: 'approved', label: 'Aprovados' }, { value: 'rejected', label: 'Rejeitados' }]"
                                        :key="opt.value"
                                        type="button"
                                        :class="[
                                            'rounded-full px-4 py-2 text-sm font-medium transition-all',
                                            commentStatusFilter === opt.value
                                                ? 'bg-[var(--color-primary)] text-white shadow-sm'
                                                : 'bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-600',
                                        ]"
                                        @click="setCommentStatus(opt.value)"
                                    >
                                        {{ opt.label }}
                                    </button>
                                </div>
                                <div class="space-y-3">
                                    <div
                                        v-for="c in commentsFiltered"
                                        :key="c.id"
                                        class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition dark:border-zinc-600 dark:bg-zinc-800/50 dark:shadow-none"
                                    >
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div class="min-w-0 flex-1 space-y-2">
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <span class="font-medium text-zinc-900 dark:text-white">{{ c.user?.name ?? c.user?.email ?? '—' }}</span>
                                                    <span
                                                        :class="[
                                                            'inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium capitalize',
                                                            c.status === 'pending' && 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300',
                                                            c.status === 'approved' && 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300',
                                                            c.status === 'rejected' && 'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300',
                                                        ]"
                                                    >
                                                        {{ c.status === 'pending' ? 'Pendente' : c.status === 'approved' ? 'Aprovado' : 'Rejeitado' }}
                                                    </span>
                                                </div>
                                                <p v-if="c.lesson" class="text-sm text-zinc-500 dark:text-zinc-400">Aula: {{ c.lesson.title }}</p>
                                                <p class="text-zinc-700 dark:text-zinc-300">{{ c.content }}</p>
                                                <p class="text-xs text-zinc-400 dark:text-zinc-500">{{ c.created_at }}</p>
                                            </div>
                                            <div v-if="c.status === 'pending'" class="flex shrink-0 gap-2">
                                                <Button size="sm" :disabled="commentActionId === c.id" @click="approveComment(c.id)">Aprovar</Button>
                                                <Button size="sm" variant="outline" :disabled="commentActionId === c.id" @click="rejectComment(c.id)">Rejeitar</Button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <p v-if="!commentsFiltered.length" class="rounded-xl border border-dashed border-zinc-200 py-12 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Nenhum comentário encontrado.</p>
                            </div>
                        </div>
                    </template>

                    <template v-else-if="activeTab === 'comunidade'">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Configuração da comunidade</h2>
                        <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">Ative a comunidade e gerencie as páginas. O preview à direita mostra como os alunos verão a tela.</p>
                        <div class="space-y-4">
                            <Toggle v-model="configForm.member_area_config.community_enabled" label="Habilitar comunidade" />
                            <Toggle v-model="configForm.member_area_config.community_users_can_delete_own_posts" label="Alunos podem excluir suas próprias postagens" />
                        </div>
                        <div class="mt-6 flex items-center justify-between">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Páginas da comunidade</h3>
                            <Button size="sm" @click="openCommunityPageModal()">Nova página</Button>
                        </div>
                        <ul class="mt-2 space-y-2">
                            <li v-for="p in communityPagesList" :key="p.id" class="flex items-center justify-between gap-3 rounded-lg bg-zinc-50 py-2 px-3 text-sm dark:bg-zinc-800/50">
                                <span class="flex min-w-0 items-center gap-2">
                                    <template v-if="p.icon">
                                        <component v-if="getCommunityPageIconComponent(p.icon)" :is="getCommunityPageIconComponent(p.icon)" class="h-5 w-5 shrink-0 text-zinc-600 dark:text-zinc-400" />
                                        <span v-else class="text-lg leading-none">{{ p.icon }}</span>
                                    </template>
                                    <img v-else-if="p.banner_url" :src="p.banner_url" :alt="p.title" class="h-8 w-12 shrink-0 rounded object-cover" />
                                    <span class="truncate">{{ p.title }}</span>
                                    <span v-if="p.is_default" class="shrink-0 rounded bg-[var(--color-primary)]/20 px-1.5 py-0.5 text-xs font-medium text-[var(--color-primary)]">padrão</span>
                                    <span v-if="p.is_featured" class="shrink-0 rounded bg-amber-500/15 px-1.5 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400">destaque</span>
                                    <span class="shrink-0 text-zinc-500">({{ p.is_public_posting ? 'público' : 'privado' }})</span>
                                </span>
                                <span class="flex shrink-0 gap-1">
                                    <button type="button" class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100" title="Editar" @click="openCommunityPageModal(p)">Editar</button>
                                    <span class="text-zinc-300 dark:text-zinc-600">|</span>
                                    <button type="button" class="text-red-600 hover:underline" @click="deleteCommunityPage(p.id)">Remover</button>
                                </span>
                            </li>
                        </ul>
                        <p v-if="!communityPagesList.length" class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Nenhuma página ainda. Clique em "Nova página" para criar.</p>
                        <Button type="button" class="mt-4" @click="saveConfig" :disabled="processing">Salvar</Button>
                    </template>

                    <template v-else-if="activeTab === 'certificado'">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Certificado</h2>
                        <div class="space-y-4">
                            <Toggle v-model="configForm.member_area_config.certificate.enabled" label="Habilitar certificado" />
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome do certificado</label>
                                <input v-model="configForm.member_area_config.certificate.title" type="text" :class="inputClass" placeholder="Deixe vazio para usar o nome do produto" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">% conclusão mínima</label>
                                <input v-model.number="configForm.member_area_config.certificate.completion_percent" type="number" min="0" max="100" :class="inputClass" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Duração do curso</label>
                                <input v-model="configForm.member_area_config.certificate.duration_text" type="text" :class="inputClass" placeholder="Ex: 40 horas" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Fonte</label>
                                <select v-model="configForm.member_area_config.certificate.font_family" :class="inputClass">
                                    <option value="sans-serif">Sans-serif</option>
                                    <option value="serif">Serif</option>
                                    <option value="monospace">Monospace</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Texto da assinatura</label>
                                <input v-model="configForm.member_area_config.certificate.signature_text" type="text" :class="inputClass" placeholder="Ex: Diretor, Escola XYZ" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Nome da plataforma</label>
                                <input v-model="configForm.member_area_config.certificate.platform_name" type="text" :class="inputClass" placeholder="Deixe vazio para usar o nome do sistema" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor primária</label>
                                <div class="flex items-center gap-2">
                                    <input v-model="configForm.member_area_config.certificate.primary_color" type="color" class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 bg-white p-0 dark:bg-zinc-800" />
                                    <input v-model="configForm.member_area_config.certificate.primary_color" type="text" :class="inputClass" placeholder="Ex: #22c55e" class="flex-1 font-mono text-sm" />
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Imagem de fundo</label>
                                <div class="flex items-center gap-2">
                                    <input ref="certBgFileInput" type="file" accept="image/*" class="hidden" @change="onCertBgChange" />
                                    <Button type="button" variant="secondary" size="sm" @click="certBgFileInput?.click()">{{ configForm.member_area_config.certificate.background_image_url ? 'Trocar imagem' : 'Enviar imagem' }}</Button>
                                    <Button v-if="configForm.member_area_config.certificate.background_image_url" type="button" variant="ghost" size="sm" @click="removeCertBg">Remover</Button>
                                </div>
                                <img v-if="configForm.member_area_config.certificate.background_image_url" :src="configForm.member_area_config.certificate.background_image_url" alt="Fundo" class="mt-2 h-20 w-full rounded-lg object-cover" />
                            </div>
                            <template v-if="configForm.member_area_config.certificate.background_image_url">
                                <div class="rounded-lg border border-zinc-200 bg-zinc-50/50 p-3 dark:border-zinc-600 dark:bg-zinc-800/30">
                                    <p class="mb-2 text-xs font-medium text-zinc-600 dark:text-zinc-400">Overlay na imagem de fundo</p>
                                    <Toggle v-model="configForm.member_area_config.certificate.background_overlay_enabled" label="Ativar overlay" />
                                    <template v-if="configForm.member_area_config.certificate.background_overlay_enabled">
                                        <div class="mt-3">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor do overlay</label>
                                            <div class="flex items-center gap-2">
                                                <input v-model="configForm.member_area_config.certificate.background_overlay_color" type="color" class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 bg-white p-0 dark:bg-zinc-800" />
                                                <input v-model="configForm.member_area_config.certificate.background_overlay_color" type="text" :class="inputClass" class="flex-1 font-mono text-sm" />
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Opacidade do overlay (0–100%)</label>
                                            <input v-model.number="configForm.member_area_config.certificate.background_overlay_opacity" type="range" min="0" max="100" class="w-full" />
                                            <span class="text-xs text-zinc-500">{{ Math.round((configForm.member_area_config.certificate.background_overlay_opacity ?? 50)) }}%</span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template v-if="configForm.member_area_config.certificate.background_image_url">
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor do texto (com imagem de fundo)</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="configForm.member_area_config.certificate.text_color" type="color" class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 bg-white p-0 dark:bg-zinc-800" />
                                        <input v-model="configForm.member_area_config.certificate.text_color" type="text" :class="inputClass" placeholder="Ex: #171717" class="flex-1 font-mono text-sm" />
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor do título (com imagem de fundo)</label>
                                    <div class="flex items-center gap-2">
                                        <input v-model="configForm.member_area_config.certificate.title_color" type="color" class="h-9 w-14 cursor-pointer rounded border border-zinc-300 dark:border-zinc-600 bg-white p-0 dark:bg-zinc-800" />
                                        <input v-model="configForm.member_area_config.certificate.title_color" type="text" :class="inputClass" placeholder="Ex: #22c55e" class="flex-1 font-mono text-sm" />
                                    </div>
                                </div>
                            </template>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Fonte da assinatura</label>
                                <select v-model="configForm.member_area_config.certificate.signature_font_family" :class="inputClass">
                                    <option value="Dancing Script">Dancing Script</option>
                                    <option value="Great Vibes">Great Vibes</option>
                                    <option value="Pacifico">Pacifico</option>
                                    <option value="Caveat">Caveat</option>
                                    <option value="Satisfy">Satisfy</option>
                                </select>
                            </div>
                        </div>
                        <Button type="button" class="mt-4" @click="saveConfig" :disabled="processing">Salvar</Button>
                    </template>

                    <template v-else-if="activeTab === 'gamificacao'">
                        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Gamificação</h2>
                        <div class="space-y-6">
                            <Toggle v-model="configForm.member_area_config.gamification.enabled" label="Habilitar gamificação" />
                            <template v-if="configForm.member_area_config.gamification.enabled">
                                <div class="flex items-start justify-between">
                                    <div>
                                        <p class="text-sm text-zinc-600 dark:text-zinc-400">Configure conquistas (badges) que os alunos desbloqueiam ao atingir metas. Personalize título, descrição (exibida no modal de celebração) e imagem.</p>
                                    </div>
                                    <div>
                                        <Button type="button" variant="secondary" @click="openGamificationModal">
                                            <Plus class="mr-2 h-4 w-4" /> Adicionar conquista
                                        </Button>
                                    </div>
                                </div>
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 mt-2">
                                    <div
                                        v-for="(ach, idx) in (configForm.member_area_config.gamification.achievements || [])"
                                        :key="ach.id"
                                        class="rounded-lg border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800/50"
                                    >
                                        <!-- Compact view -->
                                        <div v-if="!ach._editing" class="flex items-center gap-3">
                                            <div class="h-14 w-14 rounded-md overflow-hidden flex-shrink-0">
                                                <img v-if="ach.image" :src="ach.image" alt="badge" class="h-full w-full object-cover" />
                                                <div v-else class="h-full w-full flex items-center justify-center bg-zinc-200 dark:bg-zinc-700">
                                                    <Trophy class="h-5 w-5 text-zinc-500" />
                                                </div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <p class="truncate font-medium text-zinc-900 dark:text-zinc-100">{{ ach.title || 'Sem título' }}</p>
                                                <p class="text-xs text-zinc-500">{{ (GAMIFICATION_TRIGGERS.find(t => t.value === ach.trigger) || { label: '' }).label }}</p>
                                            </div>
                                                <div class="flex items-center gap-2">
                                                <button type="button" class="text-sm text-sky-600" @click="openGamificationModalForEdit(ach, idx)">Editar</button>
                                                <button type="button" class="text-sm text-red-600" @click="removeGamificationAchievement(idx)">Remover</button>
                                            </div>
                                        </div>
                                        <!-- Edit mode -->
                                        <div v-else class="flex items-start gap-3">
                                            <div class="h-14 w-14 rounded-md overflow-hidden flex-shrink-0">
                                                <img v-if="ach.image" :src="ach.image" alt="badge" class="h-full w-full object-cover" />
                                                <div v-else class="h-full w-full flex items-center justify-center bg-zinc-200 dark:bg-zinc-700">
                                                    <Trophy class="h-5 w-5 text-zinc-500" />
                                                </div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <input v-model="ach.title" type="text" :class="inputClass" placeholder="Título" />
                                                <div class="mt-2 flex items-center gap-2">
                                                    <select v-model="ach.trigger" :class="inputClass" class="flex-1">
                                                        <option v-for="t in GAMIFICATION_TRIGGERS" :key="t.value" :value="t.value">{{ t.label }}</option>
                                                    </select>
                                                    <input v-if="ach.trigger === 'lessons_count'" v-model.number="ach.trigger_config.count" type="number" min="1" class="block rounded border px-2 py-1 text-sm w-20" placeholder="N" />
                                                    <input v-else-if="ach.trigger === 'completion_percent'" v-model.number="ach.trigger_config.percent" type="number" min="1" max="100" class="block rounded border px-2 py-1 text-sm w-20" placeholder="%" />
                                                </div>
                                                <textarea v-model="ach.description" rows="2" class="mt-2 block w-full rounded border px-2 py-1 text-sm" placeholder="Descrição (modal)"></textarea>
                                                <p class="mt-1 text-xs text-zinc-500">Imagem enviada: máx. {{ uploadLimits.badge_max_mb }} MB.</p>
                                                <div class="mt-2 flex items-center justify-between gap-2">
                                                    <div>
                                                        <input
                                                            ref="badgeFileInputRef"
                                                            :id="`badge-input-${ach.id}`"
                                                            type="file"
                                                            accept="image/*"
                                                            class="hidden"
                                                            @change="onBadgeUpload(ach, $event)"
                                                        />
                                                    </div>
                                                    <div class="flex flex-wrap items-center gap-3">
                                                    <button
                                                        v-for="src in BADGE_LIBRARY"
                                                        :key="src"
                                                        type="button"
                                                        class="h-14 w-14 flex-shrink-0 overflow-hidden rounded border transition"
                                                        :class="ach.image === src ? 'ring-2 ring-[var(--ma-primary)]' : 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-600'"
                                                        @click="selectBadge(ach, src)"
                                                    >
                                                        <img :src="src" class="h-full w-full object-cover" />
                                                    </button>
                                                </div>
                                                    <div class="flex items-center gap-2">
                                                        <Button type="button" variant="secondary" size="sm" :disabled="badgeUploadingRef === ach.id" @click="triggerBadgeUpload(ach)">{{ badgeUploadingRef === ach.id ? 'Enviando…' : 'Upload' }}</Button>
                                                        <button type="button" class="text-red-600 text-sm" @click="removeGamificationAchievement(idx)">Remover</button>
                                                        <button type="button" class="text-sm text-zinc-500" @click="ach._editing = false">Fechar</button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <!-- header moved to top -->
                            </template>
                        </div>
                        <Button type="button" class="mt-4" @click="saveConfig" :disabled="processing">Salvar</Button>
                    </template>

                    <template v-else-if="activeTab === 'pwa'">
                        <div class="mx-auto max-w-3xl space-y-6">
                            <!-- Formulário em cards -->
                            <div class="min-w-0 flex-1 space-y-6">
                                <!-- Card: URL da área -->
                                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                        <Globe class="h-4 w-4 text-sky-500" />
                                        URL da área
                                    </h3>
                                    <div class="space-y-4">
                                        <div v-if="configForm.domain_type === 'custom'">
                                            <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Domínio ou subdomínio</label>
                                            <input v-model="configForm.domain_value" type="text" :class="inputClass" class="w-full" placeholder="membros.empresa.com ou area.empresa.com.br" />
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Ex.: membros.seudominio.com ou area.seudominio.com.br</p>
                                            <!-- Instruções DNS: para onde apontar -->
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
                                        <div v-else>
                                            <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Segmento da URL</label>
                                            <input
                                                v-model="configForm.domain_value"
                                                type="text"
                                                :class="inputClass"
                                                class="w-full"
                                                placeholder="Ex.: meucurso (6–16 letras/números)"
                                                maxlength="16"
                                            />
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Vazio = {{ produto.checkout_slug }}</p>
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

                                <!-- Card: Aparência do app (PWA) -->
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
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Barra do app e tela inicial</p>
                                        </div>
                                        <div>
                                            <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Cor do tema</label>
                                            <div class="flex items-center gap-3">
                                                <input
                                                    v-model="configForm.member_area_config.pwa.theme_color"
                                                    type="color"
                                                    class="h-10 w-14 cursor-pointer rounded-lg border border-zinc-300 dark:border-zinc-600"
                                                />
                                                <input
                                                    v-model="configForm.member_area_config.pwa.theme_color"
                                                    type="text"
                                                    :class="inputClass"
                                                    class="flex-1 font-mono text-sm"
                                                    placeholder="#0ea5e9"
                                                    maxlength="20"
                                                />
                                            </div>
                                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Barra de status no PWA</p>
                                        </div>
                                    </div>
                                </div>

                                <!-- Card: Notificações push -->
                                <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
                                    <h3 class="mb-4 flex items-center gap-2 text-sm font-semibold text-zinc-800 dark:text-zinc-200">
                                        <Bell class="h-4 w-4 text-violet-500" />
                                        Notificações push
                                    </h3>
                                    <div class="space-y-4">
                                        <Toggle v-model="configForm.member_area_config.pwa.push_enabled" label="Habilitar notificações push para esta área" />
                                        <div v-if="configForm.member_area_config.pwa.push_enabled" class="rounded-lg bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                            <p class="font-medium text-zinc-700 dark:text-zinc-300">Chaves VAPID</p>
                                            <p class="mt-1">As chaves são geradas e armazenadas automaticamente para este produto. Ative as notificações e salve para gerar (ou use as já existentes).</p>
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
                                                <Button
                                                    type="button"
                                                    :disabled="pushSending || !pushForm.title.trim() || !pushForm.body.trim()"
                                                    @click="sendPushNotification"
                                                >
                                                    {{ pushSending ? 'Enviando…' : 'Enviar notificação' }}
                                                </Button>
                                            </div>
                                            <p v-if="pushSendResult" class="text-xs" :class="pushSendResult.success ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400'">
                                                {{ pushSendResult.message }}
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <Button type="button" class="w-full" @click="saveConfig" :disabled="processing">Salvar alterações</Button>
                            </div>
                        </div>
                    </template>
                </div>
                </div>
            </aside>

            <div
                v-if="effectiveShowPreview"
                class="hidden min-h-0 flex-1 flex-col overflow-hidden bg-zinc-200 p-4 dark:bg-zinc-900 lg:flex"
            >
                <p class="mb-2 shrink-0 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Preview</p>
                <div class="min-h-0 flex-1 overflow-auto">
                    <MemberBuilderPreview
                        :key="previewKey"
                        :mode="previewMode"
                        :config="configForm.member_area_config"
                        :product-name="produto.name"
                        :sections="previewSections"
                        :internal-products="produto.internal_products ?? []"
                        :progress-percent="0"
                        :continue-watching="null"
                        :community-enabled="configForm.member_area_config.community_enabled ?? false"
                        :community-pages="communityPagesList"
                        :certificate-enabled="configForm.member_area_config.certificate?.enabled ?? false"
                        :can-issue-certificate="(configForm.member_area_config.certificate?.enabled ?? false) ? true : false"
                    />
                </div>
            </div>
        </div>

        <!-- Modal nativo para Nova seção / Módulo / Aula (substitui prompt do navegador) -->
        <Teleport to="body">
            <div
                v-if="promptModal.show"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="cancelPrompt"
            >
                <div class="w-full max-w-xl overflow-hidden rounded-xl bg-white shadow-xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ promptModal.title }}</h3>
                    </div>
                    <div class="p-4">
                        <input
                            ref="promptModalInputRef"
                            v-model="promptModal.value"
                            type="text"
                            :placeholder="promptModal.placeholder"
                            :class="inputClass"
                            class="w-full"
                            @keydown.enter="confirmPrompt"
                            @keydown.escape="cancelPrompt"
                        />
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Button variant="outline" @click="cancelPrompt">Cancelar</Button>
                        <Button @click="confirmPrompt">Confirmar</Button>
                    </div>
                </div>
            </div>

            <!-- Modal de confirmação (substitui confirm() do navegador) -->
            <div
                v-if="confirmModal.show"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="closeConfirmModal"
            >
                <div class="w-full max-w-sm overflow-hidden rounded-xl bg-white shadow-xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ confirmModal.title }}</h3>
                    </div>
                    <div class="p-4">
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ confirmModal.message }}</p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Button variant="outline" :disabled="confirmModal.loading" @click="closeConfirmModal">Cancelar</Button>
                        <Button
                            :disabled="confirmModal.loading"
                            :class="confirmModal.danger ? 'bg-red-600 text-white hover:bg-red-700 dark:bg-red-600 dark:hover:bg-red-700' : ''"
                            @click="runConfirmModal"
                        >
                            {{ confirmModal.loading ? 'Aguarde…' : confirmModal.confirmLabel }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Modal Nova seção (tipo + título + modo de capa) -->
            <div
                v-if="sectionModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="closeSectionModal"
            >
                <div class="w-full max-w-sm overflow-hidden rounded-xl bg-white shadow-xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Nova seção</h3>
                    </div>
                    <div class="space-y-4 p-4">
                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tipo de seção</label>
                            <div class="grid grid-cols-1 gap-2">
                                <button
                                    type="button"
                                    :class="sectionModalSectionType === 'courses'
                                        ? 'border-sky-500 bg-sky-500/10 ring-1 ring-sky-500/30 dark:bg-sky-500/15'
                                        : 'border-zinc-200 dark:border-zinc-600 hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                                    class="rounded-lg border-2 px-3 py-2 text-left text-sm font-medium transition-all"
                                    @click="sectionModalSectionType = 'courses'"
                                >
                                    Cursos/Aulas — módulos com aulas
                                </button>
                                <button
                                    type="button"
                                    :class="sectionModalSectionType === 'products'
                                        ? 'border-sky-500 bg-sky-500/10 ring-1 ring-sky-500/30 dark:bg-sky-500/15'
                                        : 'border-zinc-200 dark:border-zinc-600 hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                                    class="rounded-lg border-2 px-3 py-2 text-left text-sm font-medium transition-all"
                                    @click="sectionModalSectionType = 'products'"
                                >
                                    Outros produtos — links para outros produtos (pago ou liberado)
                                </button>
                                <button
                                    type="button"
                                    :class="sectionModalSectionType === 'external_links'
                                        ? 'border-sky-500 bg-sky-500/10 ring-1 ring-sky-500/30 dark:bg-sky-500/15'
                                        : 'border-zinc-200 dark:border-zinc-600 hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                                    class="rounded-lg border-2 px-3 py-2 text-left text-sm font-medium transition-all"
                                    @click="sectionModalSectionType = 'external_links'"
                                >
                                    Links externos — título + URL
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Título</label>
                            <input v-model="sectionModalTitle" type="text" :class="inputClass" placeholder="Título da seção" class="w-full" />
                        </div>
                        <div>
                            <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Modo de capa dos módulos</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    @click="sectionModalCoverMode = 'vertical'"
                                    class="flex flex-col items-center gap-3 rounded-xl border-2 p-4 text-left transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-sky-500"
                                    :class="sectionModalCoverMode === 'vertical'
                                        ? 'border-sky-500 bg-sky-500/10 shadow-sm ring-2 ring-sky-500/20 dark:bg-sky-500/15 dark:ring-sky-400/30'
                                        : 'border-zinc-200 dark:border-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:hover:border-zinc-500 dark:hover:bg-zinc-800/60'"
                                >
                                    <div class="aspect-[2/3] w-16 rounded-lg bg-gradient-to-b from-zinc-400 to-zinc-600 shadow-inner dark:from-zinc-500 dark:to-zinc-700" aria-hidden="true" />
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Vertical</span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Capa em retrato</span>
                                </button>
                                <button
                                    type="button"
                                    @click="sectionModalCoverMode = 'horizontal'"
                                    class="flex flex-col items-center gap-3 rounded-xl border-2 p-4 text-left transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-sky-500"
                                    :class="sectionModalCoverMode === 'horizontal'
                                        ? 'border-sky-500 bg-sky-500/10 shadow-sm ring-2 ring-sky-500/20 dark:bg-sky-500/15 dark:ring-sky-400/30'
                                        : 'border-zinc-200 dark:border-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:hover:border-zinc-500 dark:hover:bg-zinc-800/60'"
                                >
                                    <div class="aspect-video w-full max-w-[120px] rounded-lg bg-gradient-to-r from-zinc-400 to-zinc-600 shadow-inner dark:from-zinc-500 dark:to-zinc-700" aria-hidden="true" />
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-200">Banner</span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">Capa horizontal</span>
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Button variant="outline" @click="closeSectionModal">Cancelar</Button>
                        <Button @click="confirmNewSection" :disabled="sectionModalSaving || !sectionModalTitle?.trim()">Criar</Button>
                    </div>
                </div>
            </div>

            <!-- Modal Novo módulo (conteúdo conforme tipo da seção) -->
            <div
                v-if="moduleModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="closeModuleModal"
            >
                <div class="w-full max-w-sm overflow-hidden rounded-xl bg-white shadow-xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Novo módulo</h3>
                    </div>
                    <div class="space-y-4 p-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Título</label>
                            <input v-model="moduleModalTitle" type="text" :class="inputClass" placeholder="Título do módulo" class="w-full" />
                        </div>
                        <!-- Cursos/Aulas: capa e mostrar título na capa -->
                        <template v-if="moduleModalSectionType === 'courses'">
                            <div>
                                <Toggle v-model="moduleModalShowTitleOnCover" label="Mostrar título na capa" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Liberação</label>
                                <div class="grid gap-2 sm:grid-cols-3">
                                    <select v-model="moduleModalReleaseMode" :class="inputClass" class="w-full sm:col-span-3">
                                        <option value="none">Imediata</option>
                                        <option value="days">Após X dias</option>
                                        <option value="date">Na data</option>
                                        <option value="progress">Ao atingir X% do curso</option>
                                        <option value="modules">Ao concluir módulos</option>
                                    </select>
                                    <input
                                        v-if="moduleModalReleaseMode === 'days'"
                                        v-model="moduleModalReleaseAfterDays"
                                        type="number"
                                        min="1"
                                        step="1"
                                        :class="inputClass"
                                        class="w-full sm:col-span-3"
                                        placeholder="Ex.: 7"
                                    />
                                    <input
                                        v-else-if="moduleModalReleaseMode === 'date'"
                                        v-model="moduleModalReleaseAtDate"
                                        type="date"
                                        :class="inputClass"
                                        class="w-full sm:col-span-3"
                                    />
                                    <input
                                        v-else-if="moduleModalReleaseMode === 'progress'"
                                        v-model="moduleModalReleaseProgressPercent"
                                        type="number"
                                        min="1"
                                        max="100"
                                        step="1"
                                        :class="inputClass"
                                        class="w-full sm:col-span-3"
                                        placeholder="Ex.: 40"
                                    />
                                    <div
                                        v-else-if="moduleModalReleaseMode === 'modules'"
                                        class="max-h-40 space-y-1 overflow-y-auto rounded-lg border border-zinc-200 p-2 sm:col-span-3 dark:border-zinc-600"
                                    >
                                        <label
                                            v-for="sec in courseStructureSections"
                                            :key="'prereq-sec-' + sec.id"
                                            class="block"
                                        >
                                            <template v-for="mod in (sec.modules || [])" :key="mod.id">
                                                <label class="flex cursor-pointer items-center gap-2 py-0.5 text-xs text-zinc-700 dark:text-zinc-300">
                                                    <input
                                                        type="checkbox"
                                                        :checked="moduleModalReleaseRequiredModuleIds.map(Number).includes(Number(mod.id))"
                                                        @change="(e) => {
                                                            const n = Number(mod.id);
                                                            const cur = moduleModalReleaseRequiredModuleIds.map(Number);
                                                            moduleModalReleaseRequiredModuleIds = e.target.checked
                                                                ? [...cur, n]
                                                                : cur.filter((x) => x !== n);
                                                        }"
                                                    />
                                                    <span>{{ mod.title }} <span class="text-zinc-400">({{ sec.title }})</span></span>
                                                </label>
                                            </template>
                                        </label>
                                    </div>
                                </div>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Duração do acesso</label>
                                <input
                                    v-model="moduleModalAccessDurationDays"
                                    type="number"
                                    min="1"
                                    max="3650"
                                    step="1"
                                    :class="inputClass"
                                    class="w-full"
                                    placeholder="Ilimitado"
                                />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Em dias após a compra. Deixe vazio para acesso ilimitado.
                                </p>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Capa — {{ moduleModalCoverMode === 'horizontal' ? 'banner' : 'vertical' }}</label>
                                <input
                                    ref="moduleModalFileInputRef"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onModuleModalFileChange"
                                />
                                <div v-if="moduleModalFilePreviewUrl" class="flex items-start gap-3">
                                    <div :class="moduleModalCoverMode === 'horizontal' ? 'aspect-video w-28 shrink-0' : 'aspect-[2/3] h-20 w-14 shrink-0'" class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-600">
                                        <img :src="moduleModalFilePreviewUrl" alt="Preview" class="h-full w-full object-cover" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="truncate text-xs text-zinc-600 dark:text-zinc-400">{{ moduleModalFile?.name }}</p>
                                        <Button type="button" size="sm" variant="ghost" class="mt-1 !py-0.5 !text-xs text-red-600" @click="clearModuleModalFile">Remover</Button>
                                    </div>
                                </div>
                                <Button v-else type="button" size="sm" variant="outline" class="w-full !py-2 !text-xs" @click="moduleModalFileInputRef?.click()">
                                    Escolher imagem
                                </Button>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ moduleModalCoverMode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. {{ uploadLimits.image_max_mb }} MB.</p>
                            </div>
                        </template>
                        <!-- Outros produtos: selecionar produto + acesso + capa -->
                        <template v-else-if="moduleModalSectionType === 'products'">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Produto</label>
                                <select v-model="moduleModalRelatedProductId" :class="inputClass" class="w-full">
                                    <option :value="null">Selecione o produto</option>
                                    <option v-for="p in tenant_products" :key="p.id" :value="p.id">{{ p.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Acesso</label>
                                <select v-model="moduleModalAccessType" :class="inputClass" class="w-full">
                                    <option value="paid">Pago — sem acesso leva ao checkout</option>
                                    <option value="free">Liberado — aluno acessa a área do produto</option>
                                </select>
                            </div>
                            <div>
                                <Toggle v-model="moduleModalShowTitleOnCover" label="Mostrar título na capa" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Capa — {{ moduleModalCoverMode === 'horizontal' ? 'banner' : 'vertical' }}</label>
                                <input
                                    ref="moduleModalFileInputRef"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onModuleModalFileChange"
                                />
                                <div v-if="moduleModalFilePreviewUrl" class="flex items-start gap-3">
                                    <div :class="moduleModalCoverMode === 'horizontal' ? 'aspect-video w-28 shrink-0' : 'aspect-[2/3] h-20 w-14 shrink-0'" class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-600">
                                        <img :src="moduleModalFilePreviewUrl" alt="Preview" class="h-full w-full object-cover" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="truncate text-xs text-zinc-600 dark:text-zinc-400">{{ moduleModalFile?.name }}</p>
                                        <Button type="button" size="sm" variant="ghost" class="mt-1 !py-0.5 !text-xs text-red-600" @click="clearModuleModalFile">Remover</Button>
                                    </div>
                                </div>
                                <Button v-else type="button" size="sm" variant="outline" class="w-full !py-2 !text-xs" @click="moduleModalFileInputRef?.click()">
                                    Escolher imagem
                                </Button>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ moduleModalCoverMode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. {{ uploadLimits.image_max_mb }} MB.</p>
                            </div>
                        </template>
                        <!-- Links externos: URL + capa -->
                        <template v-else>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL</label>
                                <input v-model="moduleModalExternalUrl" type="url" :class="inputClass" placeholder="https://..." class="w-full" />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">O link será aberto em nova aba.</p>
                            </div>
                            <div>
                                <Toggle v-model="moduleModalShowTitleOnCover" label="Mostrar título na capa" />
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Capa — {{ moduleModalCoverMode === 'horizontal' ? 'banner' : 'vertical' }}</label>
                                <input
                                    ref="moduleModalFileInputRef"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onModuleModalFileChange"
                                />
                                <div v-if="moduleModalFilePreviewUrl" class="flex items-start gap-3">
                                    <div :class="moduleModalCoverMode === 'horizontal' ? 'aspect-video w-28 shrink-0' : 'aspect-[2/3] h-20 w-14 shrink-0'" class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-600">
                                        <img :src="moduleModalFilePreviewUrl" alt="Preview" class="h-full w-full object-cover" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="truncate text-xs text-zinc-600 dark:text-zinc-400">{{ moduleModalFile?.name }}</p>
                                        <Button type="button" size="sm" variant="ghost" class="mt-1 !py-0.5 !text-xs text-red-600" @click="clearModuleModalFile">Remover</Button>
                                    </div>
                                </div>
                                <Button v-else type="button" size="sm" variant="outline" class="w-full !py-2 !text-xs" @click="moduleModalFileInputRef?.click()">
                                    Escolher imagem
                                </Button>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ moduleModalCoverMode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. {{ uploadLimits.image_max_mb }} MB.</p>
                            </div>
                        </template>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <Button variant="outline" @click="closeModuleModal">Cancelar</Button>
                        <Button
                            @click="confirmNewModule"
                            :disabled="moduleModalSaving || !moduleModalTitle?.trim() || (moduleModalSectionType === 'products' && !moduleModalRelatedProductId) || (moduleModalSectionType === 'external_links' && !moduleModalExternalUrl?.trim())"
                        >
                            Criar
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Modal Nova / Editar turma -->
            <div
                v-if="turmaModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="closeTurmaModal"
            >
                <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ turmaModalEditing ? 'Editar turma' : 'Nova turma' }}
                        </h3>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ turmaModalEditing ? 'Altere o nome da turma.' : 'Dê um nome para organizar os alunos.' }}
                        </p>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome da turma</label>
                            <input
                                ref="turmaModalInputRef"
                                v-model="turmaModalName"
                                type="text"
                                :class="inputClass"
                                class="w-full rounded-xl border-zinc-300 py-2.5 dark:border-zinc-600"
                                placeholder="Ex: Turma 2025, Grupo A..."
                                @keydown.enter="saveTurmaModal"
                                @keydown.escape="closeTurmaModal"
                            />
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <Button variant="outline" @click="closeTurmaModal">Cancelar</Button>
                        <Button :disabled="turmaModalSaving || !turmaModalName?.trim()" @click="saveTurmaModal">
                            {{ turmaModalSaving ? 'Salvando…' : (turmaModalEditing ? 'Salvar' : 'Criar turma') }}
                        </Button>
                    </div>
                </div>
            </div>

            <!-- Modal Adicionar aluno à turma -->
            <div
                v-if="addAlunoModalTurma"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="closeAddAlunoModal"
            >
                <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Adicionar aluno</h3>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                            Turma: <strong class="text-zinc-700 dark:text-zinc-200">{{ addAlunoModalTurma?.name }}</strong>
                        </p>
                        <div class="mt-3 flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                            <button
                                type="button"
                                :class="addAlunoModalMode === 'list' ? 'bg-white text-zinc-900 shadow dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-600 dark:text-zinc-400'"
                                class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition"
                                @click="setAddAlunoModalMode('list')"
                            >
                                Já cadastrados
                            </button>
                            <button
                                type="button"
                                :class="addAlunoModalMode === 'new' ? 'bg-white text-zinc-900 shadow dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-600 dark:text-zinc-400'"
                                class="flex-1 rounded-md px-3 py-1.5 text-sm font-medium transition"
                                @click="setAddAlunoModalMode('new')"
                            >
                                Novo aluno
                            </button>
                        </div>
                    </div>
                    <!-- Lista de alunos existentes -->
                    <div v-if="addAlunoModalMode === 'list'" class="max-h-80 overflow-y-auto p-2">
                        <div
                            v-for="a in alunosDisponiveisParaTurma(addAlunoModalTurma)"
                            :key="a.id"
                            :class="[
                                'flex cursor-pointer items-center justify-between gap-3 rounded-xl border py-3 px-4 transition',
                                addAlunoModalSaving
                                    ? 'cursor-wait border-zinc-200 opacity-60 dark:border-zinc-700'
                                    : 'border-zinc-200 hover:border-sky-300 hover:bg-sky-50 dark:border-zinc-700 dark:hover:border-sky-600 dark:hover:bg-sky-950/30',
                            ]"
                            @click="!addAlunoModalSaving && attachTurmaUser(addAlunoModalTurma.id, a.id)"
                        >
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ a.name || 'Sem nome' }}</p>
                                <p class="truncate text-sm text-zinc-500 dark:text-zinc-400">{{ a.email }}</p>
                            </div>
                            <div class="shrink-0 rounded-full bg-sky-100 p-2 dark:bg-sky-900/40">
                                <Plus class="h-4 w-4 text-sky-600 dark:text-sky-400" />
                            </div>
                        </div>
                        <p v-if="!alunosDisponiveisParaTurma(addAlunoModalTurma).length" class="py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                            Nenhum aluno deste produto fora da turma. Se o aluno já existir em outro produto seu, abra <strong class="text-zinc-700 dark:text-zinc-300">Novo aluno</strong>, use o mesmo e-mail e deixe a senha em branco.
                        </p>
                    </div>
                    <!-- Formulário criar novo aluno -->
                    <div v-else class="p-5 space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome</label>
                            <input
                                v-model="newAlunoForm.name"
                                type="text"
                                :class="inputClass"
                                class="rounded-xl"
                                placeholder="Nome completo do aluno"
                            />
                            <p v-if="newAlunoFormErrors.name" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ newAlunoFormErrors.name }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail</label>
                            <input
                                v-model="newAlunoForm.email"
                                type="email"
                                name="new_aluno_email"
                                autocomplete="off"
                                autocapitalize="off"
                                autocorrect="off"
                                spellcheck="false"
                                :class="inputClass"
                                class="rounded-xl"
                                placeholder="email@exemplo.com"
                            />
                            <p v-if="newAlunoFormErrors.email" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ newAlunoFormErrors.email }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Senha</label>
                            <input
                                v-model="newAlunoForm.password"
                                type="password"
                                :class="inputClass"
                                class="rounded-xl"
                                placeholder="Mínimo 6 caracteres"
                                autocomplete="new-password"
                            />
                            <p v-if="newAlunoFormErrors.password" class="mt-1 text-xs text-red-600 dark:text-red-400">{{ newAlunoFormErrors.password }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                Obrigatória só para e-mail novo. Se o aluno já existir em outro produto desta conta, use o mesmo e-mail e deixe a senha em branco — a senha atual continua valendo.
                            </p>
                        </div>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            O aluno será adicionado ao produto e à turma <strong>{{ addAlunoModalTurma?.name }}</strong>.
                        </p>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-200 px-5 py-3 dark:border-zinc-700">
                        <Button v-if="addAlunoModalMode === 'new'" variant="outline" @click="setAddAlunoModalMode('list')">Voltar</Button>
                        <Button v-if="addAlunoModalMode === 'new'" :disabled="addAlunoModalCreateSaving" @click="createNewAluno">
                            {{ addAlunoModalCreateSaving ? 'Criando…' : 'Criar e adicionar à turma' }}
                        </Button>
                        <Button variant="outline" @click="closeAddAlunoModal">Fechar</Button>
                    </div>
                </div>
            </div>

            <!-- Modal Nova página da comunidade -->
            <div
                v-if="communityPageModalOpen"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/50 p-4 sm:items-center sm:p-6"
                @click.self="closeCommunityPageModal"
            >
                <div
                    class="my-auto flex w-full max-w-3xl max-h-[min(calc(100dvh-2rem),880px)] flex-col overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-zinc-900"
                    @click.stop
                >
                    <div class="shrink-0 border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ communityPageModalEditing ? 'Editar página' : 'Nova página da comunidade' }}</h3>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Configure título, banner e permissões da página.</p>
                    </div>
                    <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain p-5">
                        <div class="grid gap-5 lg:grid-cols-2 lg:gap-6">
                            <div class="space-y-5">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Título da página</label>
                                    <input v-model="communityPageModalTitle" type="text" :class="inputClass" placeholder="Ex: Dúvidas, Anúncios..." class="w-full" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ícone ou emoji</label>
                                    <div class="mb-2 flex items-center gap-2">
                                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg border border-zinc-200 bg-zinc-50 text-xl dark:border-zinc-600 dark:bg-zinc-800">
                                            <component v-if="communityPageModalIconComponent" :is="communityPageModalIconComponent" class="h-5 w-5 text-zinc-600 dark:text-zinc-300" />
                                            <span v-else-if="communityPageModalIcon" class="leading-none">{{ communityPageModalIcon }}</span>
                                            <span v-else class="text-zinc-400">—</span>
                                        </div>
                                        <div class="flex flex-wrap gap-2">
                                            <button
                                                type="button"
                                                :class="[
                                                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition',
                                                    communityPageIconPickerOpen === 'emoji'
                                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                                                        : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700',
                                                ]"
                                                @click="openIconPicker('emoji')"
                                            >
                                                Emojis
                                            </button>
                                            <button
                                                type="button"
                                                :class="[
                                                    'inline-flex items-center gap-1.5 rounded-lg border px-3 py-1.5 text-sm font-medium transition',
                                                    communityPageIconPickerOpen === 'icon'
                                                        ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 text-[var(--color-primary)] dark:bg-[var(--color-primary)]/20'
                                                        : 'border-zinc-200 bg-zinc-50 text-zinc-700 hover:border-zinc-300 hover:bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700',
                                                ]"
                                                @click="openIconPicker('icon')"
                                            >
                                                Ícones
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="communityPageIconPickerOpen === 'emoji'" class="max-h-36 overflow-y-auto rounded-xl border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-600 dark:bg-zinc-800/80">
                                        <div class="grid grid-cols-8 gap-1 sm:grid-cols-10">
                                            <button
                                                v-for="emoji in communityPageEmojis"
                                                :key="emoji"
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg text-lg transition hover:bg-zinc-200 dark:hover:bg-zinc-700"
                                                :title="emoji"
                                                @click="setCommunityPageModalEmoji(emoji)"
                                            >
                                                {{ emoji }}
                                            </button>
                                        </div>
                                    </div>
                                    <div v-if="communityPageIconPickerOpen === 'icon'" class="max-h-36 overflow-y-auto rounded-xl border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-600 dark:bg-zinc-800/80">
                                        <div class="grid grid-cols-6 gap-1 sm:grid-cols-8">
                                            <button
                                                v-for="name in communityPageIconNames"
                                                :key="name"
                                                type="button"
                                                class="flex h-8 w-8 items-center justify-center rounded-lg transition hover:bg-zinc-200 dark:hover:bg-zinc-700"
                                                :title="name"
                                                @click="setCommunityPageModalIcon(name)"
                                            >
                                                <component :is="communityPageIconComponents[name]" class="h-4 w-4 text-zinc-600 dark:text-zinc-300" />
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Banner (opcional)</label>
                                    <input
                                        type="file"
                                        accept="image/*"
                                        class="hidden"
                                        ref="communityPageModalBannerInputRef"
                                        @change="onCommunityPageBannerChange"
                                    />
                                    <div v-if="communityPageModalBannerPreviewUrl" class="relative">
                                        <div :class="COMMUNITY_BANNER_CONTAINER_CLASS">
                                            <img :src="communityPageModalBannerPreviewUrl" alt="Preview" :class="COMMUNITY_BANNER_IMAGE_CLASS" />
                                        </div>
                                        <button type="button" class="absolute right-2 top-2 rounded-lg bg-black/60 px-2 py-1 text-xs text-white hover:bg-black/80" @click="clearCommunityPageBanner">Remover</button>
                                    </div>
                                    <Button v-else type="button" size="sm" variant="outline" class="w-full" :disabled="communityPageModalBannerUploading" @click="communityPageModalBannerInputRef?.click()">
                                        {{ communityPageModalBannerUploading ? 'Enviando…' : 'Escolher imagem' }}
                                    </Button>
                                    <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">Tamanho ideal: {{ COMMUNITY_BANNER_RECOMMENDED }}. Máx. {{ uploadLimits.image_max_mb }} MB.</p>
                                </div>
                            </div>

                            <div class="space-y-5">
                                <div>
                                    <p class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Quem pode publicar?</p>
                                    <div class="grid grid-cols-2 gap-3">
                                        <button
                                            type="button"
                                            :class="[
                                                'flex flex-col items-center gap-2 rounded-xl border-2 p-3 text-center transition sm:p-4',
                                                communityPageModalPublic
                                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 dark:bg-[var(--color-primary)]/20'
                                                    : 'border-zinc-200 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800/50 hover:border-zinc-300 dark:hover:border-zinc-500',
                                            ]"
                                            @click="communityPageModalPublic = true"
                                        >
                                            <span class="text-2xl" aria-hidden="true">🌐</span>
                                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Público</span>
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">Alunos podem postar</span>
                                        </button>
                                        <button
                                            type="button"
                                            :class="[
                                                'flex flex-col items-center gap-2 rounded-xl border-2 p-3 text-center transition sm:p-4',
                                                !communityPageModalPublic
                                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 dark:bg-[var(--color-primary)]/20'
                                                    : 'border-zinc-200 bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800/50 hover:border-zinc-300 dark:hover:border-zinc-500',
                                            ]"
                                            @click="communityPageModalPublic = false"
                                        >
                                            <span class="text-2xl" aria-hidden="true">🔒</span>
                                            <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">Privado</span>
                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">Só o instrutor</span>
                                        </button>
                                    </div>
                                </div>
                                <div class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/30">
                                    <div>
                                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Página padrão</p>
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Aberta ao entrar na comunidade. Apenas uma por produto.</p>
                                        <div class="mt-3">
                                            <Toggle v-model="communityPageModalDefault" label="Definir como página padrão" />
                                        </div>
                                    </div>
                                    <div class="border-t border-zinc-200 pt-4 dark:border-zinc-600">
                                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Destaque na comunidade</p>
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Card com banner na sidebar direita da comunidade.</p>
                                        <div class="mt-3">
                                            <Toggle v-model="communityPageModalFeatured" label="Destacar na sidebar da comunidade" />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 justify-end gap-2 border-t border-zinc-200 px-5 py-3 dark:border-zinc-700">
                        <Button variant="outline" @click="closeCommunityPageModal">Cancelar</Button>
                        <Button @click="saveCommunityPageModal" :disabled="communityPageModalSaving || !communityPageModalTitle?.trim()">{{ communityPageModalEditing ? 'Salvar' : 'Criar página' }}</Button>
                    </div>
                </div>
            </div>
        </Teleport>
        <!-- Modal Adicionar Conquista (Gamificação) -->
        <Teleport to="body">
            <div
                v-if="gamificationModalOpen"
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="gamificationModalOpen = false"
            >
                <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Nova conquista</h3>
                        <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Preencha os dados abaixo para criar uma nova conquista.</p>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Título</label>
                            <input v-model="gamificationModalForm.title" type="text" :class="inputClass" placeholder="Ex: Primeira aula" class="w-full" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Gatilho</label>
                            <select v-model="gamificationModalForm.trigger" :class="inputClass" class="w-full">
                                <option v-for="t in GAMIFICATION_TRIGGERS" :key="t.value" :value="t.value">{{ t.label }}</option>
                            </select>
                        </div>
                        <div v-if="gamificationModalForm.trigger === 'lessons_count'">
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Quantidade de aulas</label>
                            <input v-model.number="gamificationModalForm.trigger_config.count" type="number" min="1" class="block rounded border px-2 py-1 text-sm w-24" />
                        </div>
                        <div v-else-if="gamificationModalForm.trigger === 'completion_percent'">
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Percentual</label>
                            <input v-model.number="gamificationModalForm.trigger_config.percent" type="number" min="1" max="100" class="block rounded border px-2 py-1 text-sm w-24" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Descrição (opcional)</label>
                            <textarea v-model="gamificationModalForm.description" rows="3" :class="inputClass" class="w-full"></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Badge</label>
                            <div class="flex items-center gap-3">
                                <div class="h-14 w-14 rounded-md overflow-hidden bg-zinc-50 flex items-center justify-center dark:bg-zinc-800">
                                    <img v-if="gamificationModalForm.image" :src="gamificationModalForm.image" class="h-full w-full object-cover" />
                                    <div v-else class="flex items-center justify-center h-full w-full text-zinc-400"><Trophy class="h-5 w-5" /></div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input ref="gamificationModalFileRef" type="file" accept="image/*" class="hidden" @change="onGamificationModalFileChange" />
                                    <Button type="button" size="sm" variant="outline" @click="gamificationModalFileRef?.click()">Upload</Button>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Upload personalizado: máx. {{ uploadLimits.badge_max_mb }} MB.</p>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-2">
                                <button
                                    v-for="src in BADGE_LIBRARY"
                                    :key="src"
                                    type="button"
                                    class="h-10 w-10 overflow-hidden rounded border transition"
                                    :class="gamificationModalForm.image === src ? 'ring-2 ring-[var(--ma-primary)]' : 'border-zinc-200 hover:border-zinc-400 dark:border-zinc-600'"
                                    @click="gamificationModalForm.image = src"
                                >
                                    <img :src="src" class="h-full w-full object-cover" />
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="flex justify-end gap-3 border-t border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <Button variant="outline" @click="gamificationModalOpen = false">Cancelar</Button>
                        <Button :disabled="gamificationModalSaving" @click="confirmAddAchievement">{{ gamificationModalSaving ? (gamificationEditingIndex != null ? 'Salvando…' : 'Criando…') : (gamificationEditingIndex != null ? 'Salvar' : 'Criar conquista') }}</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
