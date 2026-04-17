<script setup>
import { ref, computed, reactive, nextTick, onMounted, watch } from 'vue';
import axios from 'axios';
import MemberBuilderPreview from '@/components/member-builder/MemberBuilderPreview.vue';
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

const props = defineProps({
    produto: { type: Object, required: true },
    tenant_products: { type: Array, default: () => [] },
    app_url: { type: String, default: '' },
    dns_target_host: { type: String, default: null },
    dns_target_ip: { type: String, default: null },
});

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const activeTab = ref('aparencia');
const processing = ref(false);
const heroDesktopUploading = ref(false);
const heroDesktopFileInput = ref(null);
const certBgFileInput = ref(null);
const heroMobileUploading = ref(false);
const heroMobileFileInput = ref(null);
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

const defaultConfig = () => ({
    theme: { primary: '#0ea5e9', background: '#18181b', text: '#f8fafc', sidebar_bg: '#27272a', ...props.produto.member_area_config?.theme },
    hero: { title: '', subtitle: '', image_url: '', image_url_desktop: '', image_url_mobile: '', overlay: false, ...props.produto.member_area_config?.hero },
    header: { logo_url: '', ...props.produto.member_area_config?.header },
    logos: props.produto.member_area_config?.logos ?? {},
    sidebar: { collapsible: false, items: [], ...props.produto.member_area_config?.sidebar },
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
    certificate: { enabled: false, title: '', completion_percent: 100, signature_text: '', font_family: 'sans-serif', duration_text: '', platform_name: '', primary_color: '', background_image_url: '', background_overlay_enabled: false, background_overlay_color: '#000000', background_overlay_opacity: 50, text_color: '', title_color: '', signature_font_family: 'Dancing Script', print_format: 'A4', ...props.produto.member_area_config?.certificate },
    community_enabled: props.produto.member_area_config?.community_enabled ?? false,
    community_users_can_delete_own_posts: props.produto.member_area_config?.community_users_can_delete_own_posts ?? true,
    comments_enabled: props.produto.member_area_config?.comments_enabled ?? false,
    comments_require_approval: props.produto.member_area_config?.comments_require_approval ?? true,
    gamification: { enabled: false, achievements: [], ...props.produto.member_area_config?.gamification },
});

const configForm = reactive({
    member_area_config: defaultConfig(),
    domain_type: props.produto.member_area_domain?.type ?? 'path',
    domain_value: props.produto.member_area_domain?.value ?? props.produto.checkout_slug ?? '',
});

const tabs = [
    { id: 'aparencia', label: 'Aparência', icon: Palette, hasPreview: true, previewMode: 'area' },
    { id: 'header', label: 'Header', icon: LayoutList, hasPreview: true, previewMode: 'area' },
    { id: 'modulos', label: 'Módulos', icon: Layers, hasPreview: true, previewMode: 'area' },
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
    [activeTab, () => props.produto.sections],
    () => {
        if (activeTab.value === 'modulos' && (props.produto.sections?.length ?? 0) > 0) {
            expandAllModulos();
        }
    },
    { immediate: true }
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
const modulosSelectedModuleId = ref(null);
const modulosLessonForm = ref(null);
const modulosLessonFormSaving = ref(false);
const lessonPdfFileInput = ref(null);
const lessonPdfUploading = ref(false);

/** Material (download) ou apresentação (visualização com pdf.js) — mesmos campos no backend. */
function isLessonPdfContentType(type) {
    return type === 'pdf' || type === 'pdf_presentation';
}

const modulosSelectedModule = computed(() => {
    const id = modulosSelectedModuleId.value;
    if (!id) return null;
    for (const s of props.produto.sections ?? []) {
        const mod = s.modules?.find((m) => m.id === id);
        if (mod) return mod;
    }
    return null;
});

function selectModuleForAulas(moduleId) {
    modulosSelectedModuleId.value = moduleId;
    modulosLessonForm.value = null;
}

function openModulosLessonForm(lesson) {
    if (lesson) {
        const existingFiles = Array.isArray(lesson.content_files) ? lesson.content_files : [];
        const fileLabel = lesson.type === 'pdf_presentation' ? 'Apresentação' : 'Material';
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
            release_mode: lesson.release_at_date ? 'date' : (lesson.release_after_days ? 'days' : 'none'),
            release_after_days: lesson.release_after_days ? String(lesson.release_after_days) : '',
            release_at_date: lesson.release_at_date || '',
        };
    } else {
        modulosLessonForm.value = {
            title: '',
            type: 'video',
            content_url: '',
            link_title: '',
            content_files: [],
            content_text: '',
            watermark_enabled: false,
            release_mode: 'none',
            release_after_days: '',
            release_at_date: '',
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
        const msg = e.response?.data?.message ?? e.message ?? 'Erro ao enviar material.';
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

function lessonPayload(form) {
    const contentFiles = Array.isArray(form.content_files)
        ? form.content_files
              .map((it) => ({
                  url: (it?.url ?? '').toString().trim(),
                  name: (it?.name ?? '').toString().trim(),
              }))
              .filter((it) => it.url)
        : [];
    const firstFileUrl = contentFiles[0]?.url ?? '';
    let release_after_days = null;
    let release_at_date = null;
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
        release_after_days,
        release_at_date,
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
const editingModuleReleaseMode = ref('none'); // none | days | date
const editingModuleReleaseAfterDays = ref('');
const editingModuleReleaseAtDate = ref('');

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
const moduleModalReleaseMode = ref('none'); // none | days | date
const moduleModalReleaseAfterDays = ref('');
const moduleModalReleaseAtDate = ref('');

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
    if (mod.release_at_date) {
        editingModuleReleaseMode.value = 'date';
        editingModuleReleaseAtDate.value = mod.release_at_date;
        editingModuleReleaseAfterDays.value = '';
    } else if (mod.release_after_days) {
        editingModuleReleaseMode.value = 'days';
        editingModuleReleaseAfterDays.value = String(mod.release_after_days);
        editingModuleReleaseAtDate.value = '';
    } else {
        editingModuleReleaseMode.value = 'none';
        editingModuleReleaseAfterDays.value = '';
        editingModuleReleaseAtDate.value = '';
    }
    startEditModule(mod.id);
}

async function saveModuleTitle() {
    const id = editingModuleId.value;
    if (!id) return;
    const mod = editingModule.value;
    const section = props.produto.sections?.find((s) => s.modules?.some((m) => m.id === id));
    const sectionType = section?.section_type ?? 'courses';
    const payload = { title: editingModuleTitle.value };
    if (sectionType === 'courses') {
        payload.show_title_on_cover = editingModuleShowTitleOnCover.value;
        if (editingModuleReleaseMode.value === 'days') {
            const days = parseInt(editingModuleReleaseAfterDays.value, 10);
            payload.release_after_days = Number.isFinite(days) && days > 0 ? days : null;
            payload.release_at_date = null;
        } else if (editingModuleReleaseMode.value === 'date') {
            payload.release_at_date = editingModuleReleaseAtDate.value?.trim() || null;
            payload.release_after_days = null;
        } else {
            payload.release_after_days = null;
            payload.release_at_date = null;
        }
    } else if (sectionType === 'products') {
        payload.related_product_id = editingModuleRelatedProductId.value;
        payload.access_type = editingModuleAccessType.value;
        payload.show_title_on_cover = editingModuleShowTitleOnCover.value;
    } else if (sectionType === 'external_links') {
        payload.external_url = editingModuleExternalUrl.value?.trim() ?? '';
        payload.show_title_on_cover = editingModuleShowTitleOnCover.value;
    }
    try {
        await axios.put(`${base.value}/modules/${id}`, payload, { headers: headers() });
        cancelEdit();
        reload();
    } catch (_) {}
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
    for (const s of props.produto.sections ?? []) {
        const mod = s.modules?.find((m) => m.id === id);
        if (mod) return mod;
    }
    return null;
});

const editingModuleSection = computed(() => {
    const id = editingModuleId.value;
    if (!id) return null;
    return props.produto.sections?.find((s) => s.modules?.some((m) => m.id === id)) ?? null;
});

const moduleThumbnailUploading = ref(false);
const moduleThumbnailFileInput = ref(null);

async function onModuleThumbnailChange(event) {
    const file = event.target?.files?.[0];
    const id = editingModuleId.value;
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
    const id = editingModuleId.value;
    if (!id) return;
    axios.put(`${base.value}/modules/${id}`, { thumbnail: '' }, { headers: headers() }).then(() => reload()).catch(() => {});
}

const headers = () => ({
    'X-CSRF-TOKEN': csrfToken(),
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
});

async function saveConfig() {
    processing.value = true;
    try {
        const cleanedConfig = JSON.parse(JSON.stringify(configForm.member_area_config));
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

async function onHeroDesktopChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    heroDesktopUploading.value = true;
    try {
        await doUpload(file, (url) => { configForm.member_area_config.hero.image_url_desktop = url; });
    } catch (e) {
        alert(e?.response?.data?.message || 'Falha ao enviar imagem. Verifique o tamanho (máx. 4 MB) e o formato.');
    } finally {
        heroDesktopUploading.value = false;
        if (heroDesktopFileInput.value) heroDesktopFileInput.value.value = '';
    }
}

async function onHeroMobileChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    heroMobileUploading.value = true;
    try {
        await doUpload(file, (url) => { configForm.member_area_config.hero.image_url_mobile = url; });
    } catch (e) {
        alert(e?.response?.data?.message || 'Falha ao enviar imagem. Verifique o tamanho (máx. 4 MB) e o formato.');
    } finally {
        heroMobileUploading.value = false;
        if (heroMobileFileInput.value) heroMobileFileInput.value.value = '';
    }
}

function removeHeroDesktop() {
    configForm.member_area_config.hero.image_url_desktop = '';
    saveConfig();
}

function removeHeroMobile() {
    configForm.member_area_config.hero.image_url_mobile = '';
    saveConfig();
}

async function onCertBgChange(event) {
    const file = event.target?.files?.[0];
    if (!file || !file.type.startsWith('image/')) return;
    try {
        await doUpload(file, (url) => { configForm.member_area_config.certificate.background_image_url = url; });
    } catch (e) {
        alert(e?.response?.data?.message || 'Falha ao enviar imagem.');
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
        alert(e?.response?.data?.message || 'Falha ao enviar logo. Verifique o tamanho (máx. 4 MB) e o formato.');
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
        alert(e?.response?.data?.message || 'Falha ao enviar logo. Verifique o tamanho (máx. 4 MB) e o formato.');
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
        alert(e?.response?.data?.message || 'Falha ao enviar ícone. Verifique o tamanho (máx. 4 MB) e o formato.');
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
        alert(e?.response?.data?.message || 'Falha ao enviar imagem. Verifique o tamanho (máx. 4 MB) e o formato.');
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
function openModuleModal(sectionId) {
    const section = props.produto.sections?.find((s) => s.id === sectionId);
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
            if (moduleModalReleaseMode.value === 'days') {
                const days = parseInt(moduleModalReleaseAfterDays.value, 10);
                payload.release_after_days = Number.isFinite(days) && days > 0 ? days : null;
                payload.release_at_date = null;
            } else if (moduleModalReleaseMode.value === 'date') {
                payload.release_at_date = moduleModalReleaseAtDate.value?.trim() || null;
                payload.release_after_days = null;
            } else {
                payload.release_after_days = null;
                payload.release_at_date = null;
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
const communityPageModalSaving = ref(false);
const communityPageModalBannerPath = ref('');
const communityPageModalBannerPreviewUrl = ref('');
const communityPageModalBannerFile = ref(null);
const communityPageModalBannerUploading = ref(false);
const communityPageModalBannerInputRef = ref(null);
/** Qual seletor está aberto: 'emoji' | 'icon' | null */
const communityPageIconPickerOpen = ref(null);

function openCommunityPageModal(page = null) {
    communityPageModalEditing.value = page ?? null;
    if (page) {
        communityPageModalTitle.value = page.title ?? '';
        communityPageModalIcon.value = page.icon ?? '';
        communityPageModalPublic.value = page.is_public_posting !== false;
        communityPageModalDefault.value = page.is_default === true;
        communityPageModalBannerPath.value = page.banner ?? '';
        communityPageModalBannerPreviewUrl.value = page.banner_url ?? '';
        communityPageModalBannerFile.value = null;
    } else {
        communityPageModalTitle.value = '';
        communityPageModalIcon.value = '';
        communityPageModalPublic.value = true;
        communityPageModalDefault.value = false;
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
        };
        let res;
        if (editing) {
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
        <div :class="['flex min-h-0 flex-1 flex-col overflow-hidden', showPreview ? 'lg:flex-row' : '']">
            <aside
                :class="[
                    'flex min-h-0 min-w-0 flex-col border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900',
                    'lg:shrink-0',
                    showPreview ? 'lg:w-80 lg:border-b-0 lg:border-r lg:overflow-y-auto' : 'flex-1 w-full overflow-x-hidden',
                    activeTab === 'modulos' && showPreview ? 'lg:w-[44rem]' : '',
                    activeTab === 'modulos' && !showPreview ? 'flex-1 overflow-hidden' : '',
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
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Tamanho ideal: 180×40 px (ou proporção similar). PNG ou SVG com fundo transparente. Máx. 4 MB.</p>
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
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Usado na aba do navegador e no PWA. Tamanho ideal: 192×192 ou 512×512 px. Máx. 4 MB.</p>
                            </div>
                            <div>
                                <input v-model="configForm.member_area_config.theme.primary" type="color" class="h-9 w-full cursor-pointer rounded-lg border dark:border-zinc-600" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Fundo</label>
                                <input v-model="configForm.member_area_config.theme.background" type="color" class="h-9 w-full cursor-pointer rounded-lg border dark:border-zinc-600" />
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Banner do hero — Desktop</label>
                                <input
                                    ref="heroDesktopFileInput"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onHeroDesktopChange"
                                />
                                <div class="flex flex-col gap-2">
                                    <div v-if="configForm.member_area_config.hero.image_url_desktop" class="relative">
                                        <img :src="configForm.member_area_config.hero.image_url_desktop" alt="Hero desktop" class="h-24 w-full rounded-lg object-cover" />
                                        <div class="mt-1 flex gap-2">
                                            <Button type="button" size="sm" variant="outline" :disabled="heroDesktopUploading" @click="heroDesktopFileInput?.click()">
                                                Trocar
                                            </Button>
                                            <Button type="button" size="sm" variant="ghost" class="text-red-600" :disabled="heroDesktopUploading" @click="removeHeroDesktop">
                                                Remover
                                            </Button>
                                        </div>
                                    </div>
                                    <template v-else>
                                        <Button type="button" variant="outline" size="sm" :disabled="heroDesktopUploading" @click="heroDesktopFileInput?.click()">
                                            {{ heroDesktopUploading ? 'Enviando…' : 'Enviar banner desktop' }}
                                        </Button>
                                    </template>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Tamanho ideal: 1920×600 px (banner horizontal). Usado em telas maiores. Máx. 4 MB.</p>
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Banner do hero — Mobile</label>
                                <input
                                    ref="heroMobileFileInput"
                                    type="file"
                                    accept="image/*"
                                    class="hidden"
                                    @change="onHeroMobileChange"
                                />
                                <div class="flex flex-col gap-2">
                                    <div v-if="configForm.member_area_config.hero.image_url_mobile" class="relative">
                                        <img :src="configForm.member_area_config.hero.image_url_mobile" alt="Hero mobile" class="h-24 w-full rounded-lg object-cover" />
                                        <div class="mt-1 flex gap-2">
                                            <Button type="button" size="sm" variant="outline" :disabled="heroMobileUploading" @click="heroMobileFileInput?.click()">
                                                Trocar
                                            </Button>
                                            <Button type="button" size="sm" variant="ghost" class="text-red-600" :disabled="heroMobileUploading" @click="removeHeroMobile">
                                                Remover
                                            </Button>
                                        </div>
                                    </div>
                                    <template v-else>
                                        <Button type="button" variant="outline" size="sm" :disabled="heroMobileUploading" @click="heroMobileFileInput?.click()">
                                            {{ heroMobileUploading ? 'Enviando…' : 'Enviar banner mobile' }}
                                        </Button>
                                    </template>
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Tamanho ideal: 800×600 px ou 800×900 px (vertical). Usado em celulares. Se não enviar, usa o banner desktop. Máx. 4 MB.</p>
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
                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Imagem de fundo</label>
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
                            <div>
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
                        <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden lg:flex-row">
                            <div class="min-w-0 flex-1 overflow-y-auto overflow-x-hidden pr-2">
                        <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Estrutura do curso</h2>
                        <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Seções agrupam módulos. Clique em um módulo (ou em &quot;Aulas&quot;) para ver e editar aulas no painel à direita.</p>
                        <div class="mb-3 flex flex-wrap items-center gap-2">
                            <Button size="sm" @click="openSectionModal"><Plus class="mr-1.5 h-4 w-4" /> Nova seção</Button>
                            <span class="text-zinc-400 dark:text-zinc-500">|</span>
                            <button type="button" class="text-xs text-zinc-500 underline hover:text-zinc-700 dark:hover:text-zinc-300" @click="expandAllModulos">Expandir tudo</button>
                            <button type="button" class="text-xs text-zinc-500 underline hover:text-zinc-700 dark:hover:text-zinc-300" @click="collapseAllModulos">Recolher tudo</button>
                        </div>
                        <div class="space-y-2">
                            <template v-for="section in produto.sections" :key="section.id">
                                <div class="min-w-0 rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900">
                                    <div class="flex min-w-0 items-start gap-2 py-2 px-3">
                                        <button type="button" class="mt-0.5 shrink-0 rounded p-0.5 text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300" @click="toggleSection(section.id)" aria-label="Expandir ou recolher">
                                            <ChevronRight v-if="!expandedSections.has(section.id)" class="h-4 w-4" />
                                            <ChevronDown v-else class="h-4 w-4" />
                                        </button>
                                        <div class="min-w-0 flex-1 flex flex-col gap-2">
                                            <!-- Tags sempre em cima -->
                                            <div class="flex flex-wrap items-center gap-1.5">
                                                <span class="inline-flex shrink-0 items-center gap-1 rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400">
                                                    <FolderOpen class="h-3 w-3" /> Seção
                                                </span>
                                                <span class="shrink-0 rounded bg-zinc-200 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600 dark:bg-zinc-600 dark:text-zinc-300">{{ sectionTypeLabel(section.section_type ?? 'courses') }}</span>
                                            </div>
                                            <!-- Conteúdo: edição ou visualização -->
                                            <template v-if="editingSectionId === section.id">
                                                <input v-model="editingSectionTitle" type="text" :class="inputClass" class="!py-1.5 !text-sm min-w-0 w-full" placeholder="Título da seção" @keydown.enter="saveSectionTitle" @keydown.escape="cancelEdit" />
                                                <div class="space-y-1.5">
                                                    <span class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">Modo de capa dos módulos</span>
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <button
                                                            type="button"
                                                            :aria-pressed="editingSectionCoverMode === 'vertical'"
                                                            :class="editingSectionCoverMode === 'vertical'
                                                                ? 'border-sky-500 bg-sky-500/10 ring-1 ring-sky-500/30 dark:bg-sky-500/15'
                                                                : 'border-zinc-200 dark:border-zinc-600 hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                                                            class="flex flex-col items-center gap-1 rounded-lg border-2 p-2 transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-1"
                                                            @click="editingSectionCoverMode = 'vertical'"
                                                        >
                                                            <div class="aspect-[2/3] w-8 rounded bg-gradient-to-b from-zinc-400 to-zinc-500 dark:from-zinc-500 dark:to-zinc-600" aria-hidden="true" />
                                                            <span class="text-[10px] font-medium text-zinc-600 dark:text-zinc-300">Vertical</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            :aria-pressed="editingSectionCoverMode === 'horizontal'"
                                                            :class="editingSectionCoverMode === 'horizontal'
                                                                ? 'border-sky-500 bg-sky-500/10 ring-1 ring-sky-500/30 dark:bg-sky-500/15'
                                                                : 'border-zinc-200 dark:border-zinc-600 hover:bg-zinc-50 dark:hover:bg-zinc-800/60'"
                                                            class="flex flex-col items-center gap-1 rounded-lg border-2 p-2 transition-all duration-200 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 focus-visible:ring-offset-1"
                                                            @click="editingSectionCoverMode = 'horizontal'"
                                                        >
                                                            <div class="aspect-video w-10 rounded bg-gradient-to-r from-zinc-400 to-zinc-500 dark:from-zinc-500 dark:to-zinc-600" aria-hidden="true" />
                                                            <span class="text-[10px] font-medium text-zinc-600 dark:text-zinc-300">Banner</span>
                                                        </button>
                                                    </div>
                                                </div>
                                                <div class="flex flex-wrap items-center gap-2 pt-0.5">
                                                    <Button size="sm" class="shrink-0" @click="saveSectionTitle">Ok</Button>
                                                    <Button size="sm" variant="ghost" @click="cancelEdit">Cancelar</Button>
                                                </div>
                                            </template>
                                            <template v-else>
                                                <div class="flex min-w-0 items-center justify-between gap-2">
                                                    <span class="min-w-0 truncate cursor-pointer text-sm font-medium text-zinc-800 dark:text-zinc-200" @click="toggleSection(section.id)">{{ section.title }}</span>
                                                    <div class="flex shrink-0 items-center gap-1">
                                                        <button type="button" class="rounded p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" title="Editar seção" @click.stop="openSectionEdit(section)"><Pencil class="h-3.5 w-3.5" /></button>
                                                        <Button size="sm" variant="outline" class="!py-1 !text-xs" @click.stop="openModuleModal(section.id)">+ Módulo</Button>
                                                        <button type="button" class="rounded p-1.5 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover seção" @click.stop="deleteSection(section.id)"><Trash2 class="h-3.5 w-3.5" /></button>
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                    <div v-if="expandedSections.has(section.id)" class="min-w-0 border-t border-zinc-200 bg-zinc-50/50 px-3 pb-3 pt-2 dark:border-zinc-700 dark:bg-zinc-800/30">
                                        <!-- Seção tipo Cursos/Aulas: grid de cards de módulos -->
                                        <template v-if="(section.section_type ?? 'courses') === 'courses'">
                                            <div class="grid grid-cols-3 gap-2">
                                                <div
                                                    v-for="mod in section.modules"
                                                    :key="mod.id"
                                                    class="flex flex-col overflow-hidden rounded-lg border border-zinc-200 bg-white shadow-sm transition dark:border-zinc-700 dark:bg-zinc-800/80"
                                                    :class="{ 'ring-2 ring-sky-500/50 dark:ring-sky-400/40': modulosSelectedModuleId === mod.id }"
                                                >
                                                    <button type="button" class="flex min-w-0 flex-1 flex-col items-stretch text-left" @click="selectModuleForAulas(mod.id)">
                                                        <div class="h-14 w-full shrink-0 overflow-hidden bg-zinc-200 dark:bg-zinc-700">
                                                            <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="h-full w-full object-cover" />
                                                            <div v-else class="flex h-full w-full items-center justify-center text-zinc-400 dark:text-zinc-500">
                                                                <BookOpen class="h-5 w-5" />
                                                            </div>
                                                        </div>
                                                        <div class="min-w-0 flex-1 p-1.5">
                                                            <p class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-200">{{ mod.title }}</p>
                                                            <p class="text-[10px] text-zinc-500 dark:text-zinc-400">{{ (mod.lessons?.length ?? 0) }} {{ (mod.lessons?.length ?? 0) === 1 ? 'aula' : 'aulas' }}</p>
                                                        </div>
                                                    </button>
                                                    <div class="flex items-center gap-0.5 border-t border-zinc-200 p-1 dark:border-zinc-700">
                                                        <Button size="sm" variant="outline" class="!py-0.5 !text-[10px] flex-1 min-w-0" @click.stop="selectModuleForAulas(mod.id)">Aulas</Button>
                                                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-300" title="Editar módulo" @click.stop="openModuleEdit(mod)"><Pencil class="h-3 w-3" /></button>
                                                        <button type="button" class="rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover módulo" @click.stop="deleteModule(mod.id)"><Trash2 class="h-3 w-3" /></button>
                                                    </div>
                                                </div>
                                            </div>
                                            <!-- Edição do módulo (quando editingModuleId está neste módulo da seção) -->
                                            <template v-for="mod in section.modules" :key="'edit-' + mod.id">
                                                <div v-if="editingModuleId === mod.id" class="mt-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-600 dark:bg-zinc-800/50">
                                                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                                        <p class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Editar módulo</p>
                                                        <Toggle
                                                            :model-value="editingModuleShowTitleOnCover"
                                                            label="Mostrar título na capa"
                                                            class="!mb-0"
                                                            @update:model-value="(v) => { editingModuleShowTitleOnCover = v; setModuleShowTitleOnCover(v); }"
                                                        />
                                                    </div>
                                                    <div class="mb-3">
                                                        <input v-model="editingModuleTitle" type="text" :class="inputClass" class="!py-1.5 !text-sm w-full" placeholder="Título do módulo" @keydown.enter="saveModuleTitle" @keydown.escape="cancelEdit" />
                                                    </div>
                                                    <div class="mb-3">
                                                        <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Liberação</label>
                                                        <div class="grid gap-2 sm:grid-cols-3">
                                                            <select v-model="editingModuleReleaseMode" :class="inputClass" class="!py-1.5 !text-xs w-full">
                                                                <option value="none">Imediata</option>
                                                                <option value="days">Após X dias</option>
                                                                <option value="date">Na data</option>
                                                            </select>
                                                            <input
                                                                v-if="editingModuleReleaseMode === 'days'"
                                                                v-model="editingModuleReleaseAfterDays"
                                                                type="number"
                                                                min="1"
                                                                step="1"
                                                                :class="inputClass"
                                                                class="!py-1.5 !text-xs w-full"
                                                                placeholder="Ex.: 7"
                                                            />
                                                            <input
                                                                v-else-if="editingModuleReleaseMode === 'date'"
                                                                v-model="editingModuleReleaseAtDate"
                                                                type="date"
                                                                :class="inputClass"
                                                                class="!py-1.5 !text-xs w-full"
                                                            />
                                                            <div v-else class="hidden sm:block" />
                                                        </div>
                                                    </div>
                                                    <div v-if="editingModule?.thumbnail" class="mb-3 flex items-center gap-3">
                                                        <div :class="section.cover_mode === 'horizontal' ? 'aspect-video w-24 shrink-0' : 'aspect-[2/3] h-20 w-14 shrink-0'" class="overflow-hidden rounded-lg shadow-sm">
                                                            <img :src="editingModule.thumbnail" alt="Capa" class="h-full w-full object-cover" />
                                                        </div>
                                                        <div class="flex flex-wrap gap-2">
                                                            <Button type="button" size="sm" variant="outline" class="!py-1 !text-xs" :disabled="moduleThumbnailUploading" @click="moduleThumbnailFileInput?.click()">Trocar imagem</Button>
                                                            <Button type="button" size="sm" variant="ghost" class="!py-1 !text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" :disabled="moduleThumbnailUploading" @click="removeModuleThumbnail">Remover</Button>
                                                        </div>
                                                    </div>
                                                    <template v-else>
                                                        <div class="mb-3 flex flex-wrap items-center gap-2">
                                                            <Button type="button" size="sm" variant="outline" class="!py-1.5 !text-xs" :disabled="moduleThumbnailUploading" @click="moduleThumbnailFileInput?.click()">
                                                                {{ moduleThumbnailUploading ? 'Enviando…' : 'Enviar capa' }}
                                                            </Button>
                                                            <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ section.cover_mode === 'horizontal' ? 'Banner.' : 'Vertical.' }} Máx. 4 MB.</span>
                                                        </div>
                                                    </template>
                                                    <div class="flex gap-2">
                                                        <Button size="sm" @click="saveModuleTitle">Ok</Button>
                                                        <Button size="sm" variant="ghost" @click="cancelEdit">Cancelar</Button>
                                                    </div>
                                                </div>
                                            </template>
                                        </template>
                                        <!-- Seção tipo Outros produtos -->
                                        <template v-else-if="(section.section_type ?? 'courses') === 'products'">
                                            <template v-for="mod in section.modules" :key="mod.id">
                                                <div class="ml-2 mt-2 min-w-0 rounded-md border border-zinc-200 bg-white/80 dark:border-zinc-600 dark:bg-zinc-800/50">
                                                    <div class="flex min-w-0 flex-wrap items-center gap-2 py-1.5 px-2">
                                                        <span class="flex shrink-0 items-center gap-1 rounded bg-zinc-100/80 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400"><ShoppingBag class="h-3 w-3" /> Produto</span>
                                                        <template v-if="editingModuleId === mod.id">
                                                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                                                <input v-model="editingModuleTitle" type="text" :class="inputClass" class="!py-1.5 !text-xs min-w-0 w-full" placeholder="Título" @keydown.enter="saveModuleTitle" @keydown.escape="cancelEdit" />
                                                                <select v-model="editingModuleRelatedProductId" :class="inputClass" class="!py-1.5 !text-xs min-w-0 w-full">
                                                                    <option :value="null">Selecione o produto</option>
                                                                    <option v-for="p in tenant_products" :key="p.id" :value="p.id">{{ p.name }}</option>
                                                                </select>
                                                                <div class="flex flex-wrap items-center gap-2">
                                                                    <select v-model="editingModuleAccessType" :class="inputClass" class="!py-1 !text-xs w-full min-w-0 sm:w-auto">
                                                                        <option value="paid">Pago</option>
                                                                        <option value="free">Liberado</option>
                                                                    </select>
                                                                    <Button size="sm" class="!py-0.5 !text-xs shrink-0" @click="saveModuleTitle">Ok</Button>
                                                                    <Button size="sm" variant="ghost" class="!py-0.5 !text-xs shrink-0" @click="cancelEdit">Cancelar</Button>
                                                                </div>
                                                            </div>
                                                        </template>
                                                        <template v-else>
                                                            <span class="min-w-0 flex-1 truncate text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ mod.title }}</span>
                                                            <span class="shrink-0 truncate text-xs text-zinc-500 dark:text-zinc-400" :title="mod.related_product?.name">{{ mod.related_product?.name ?? '#' + mod.related_product_id }}</span>
                                                            <span class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium" :class="mod.access_type === 'free' ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'">{{ mod.access_type === 'free' ? 'Liberado' : 'Pago' }}</span>
                                                            <div class="flex shrink-0 items-center gap-0.5">
                                                                <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700" title="Editar" @click.stop="openModuleEdit(mod)"><Pencil class="h-3 w-3" /></button>
                                                                <button type="button" class="rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover" @click.stop="deleteModule(mod.id)"><Trash2 class="h-3 w-3" /></button>
                                                            </div>
                                                        </template>
                                                    </div>
                                                    <!-- Capa do módulo (Outros produtos) -->
                                                    <div v-if="editingModuleId === mod.id" class="ml-6 mt-2 rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-600 dark:bg-zinc-800/50">
                                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                                            <p class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Capa do módulo ({{ (section.cover_mode === 'horizontal' ? 'banner' : 'vertical') }})</p>
                                                            <Toggle
                                                                :model-value="editingModuleShowTitleOnCover"
                                                                label="Mostrar título na capa"
                                                                class="!mb-0"
                                                                @update:model-value="(v) => { editingModuleShowTitleOnCover = v; setModuleShowTitleOnCover(v); }"
                                                            />
                                                        </div>
                                                        <div v-if="editingModule?.thumbnail" class="flex items-center gap-3">
                                                            <div :class="section.cover_mode === 'horizontal' ? 'aspect-video w-24 shrink-0' : 'aspect-[2/3] h-20 w-14 shrink-0'" class="overflow-hidden rounded-lg shadow-sm">
                                                                <img :src="editingModule.thumbnail" alt="Capa" class="h-full w-full object-cover" />
                                                            </div>
                                                            <div class="flex flex-wrap gap-2">
                                                                <Button type="button" size="sm" variant="outline" class="!py-1 !text-xs" :disabled="moduleThumbnailUploading" @click="moduleThumbnailFileInput?.click()">Trocar imagem</Button>
                                                                <Button type="button" size="sm" variant="ghost" class="!py-1 !text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" :disabled="moduleThumbnailUploading" @click="removeModuleThumbnail">Remover</Button>
                                                            </div>
                                                        </div>
                                                        <template v-else>
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <Button type="button" size="sm" variant="outline" class="!py-1.5 !text-xs" :disabled="moduleThumbnailUploading" @click="moduleThumbnailFileInput?.click()">
                                                                    {{ moduleThumbnailUploading ? 'Enviando…' : 'Enviar capa' }}
                                                                </Button>
                                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ section.cover_mode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. 4 MB.</span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </template>
                                        <!-- Seção tipo Links externos -->
                                        <template v-else>
                                            <template v-for="mod in section.modules" :key="mod.id">
                                                <div class="ml-2 mt-2 min-w-0 rounded-md border border-zinc-200 bg-white/80 dark:border-zinc-600 dark:bg-zinc-800/50">
                                                    <div class="flex min-w-0 flex-wrap items-center gap-2 py-1.5 px-2">
                                                        <span class="flex shrink-0 items-center gap-1 rounded bg-zinc-100/80 px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-zinc-500 dark:bg-zinc-700 dark:text-zinc-400"><ExternalLink class="h-3 w-3" /> Link</span>
                                                        <template v-if="editingModuleId === mod.id">
                                                            <div class="flex min-w-0 flex-1 flex-col gap-2">
                                                                <input v-model="editingModuleTitle" type="text" :class="inputClass" class="!py-1.5 !text-xs min-w-0 w-full" placeholder="Título" @keydown.enter="saveModuleTitle" @keydown.escape="cancelEdit" />
                                                                <input v-model="editingModuleExternalUrl" type="url" :class="inputClass" class="!py-1.5 !text-xs min-w-0 w-full" placeholder="https://..." />
                                                                <div class="flex flex-wrap items-center gap-2">
                                                                    <Button size="sm" class="!py-0.5 !text-xs shrink-0" @click="saveModuleTitle">Ok</Button>
                                                                    <Button size="sm" variant="ghost" class="!py-0.5 !text-xs shrink-0" @click="cancelEdit">Cancelar</Button>
                                                                </div>
                                                            </div>
                                                        </template>
                                                        <template v-else>
                                                            <span class="min-w-0 flex-1 truncate text-xs font-medium text-zinc-700 dark:text-zinc-300">{{ mod.title }}</span>
                                                            <a :href="mod.external_url" target="_blank" rel="noopener" class="min-w-0 max-w-[50%] truncate text-xs text-sky-600 hover:underline dark:text-sky-400" :title="mod.external_url">{{ mod.external_url }}</a>
                                                            <div class="flex shrink-0 items-center gap-0.5">
                                                                <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-700" title="Editar" @click.stop="openModuleEdit(mod)"><Pencil class="h-3 w-3" /></button>
                                                                <button type="button" class="rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover" @click.stop="deleteModule(mod.id)"><Trash2 class="h-3 w-3" /></button>
                                                            </div>
                                                        </template>
                                                    </div>
                                                    <!-- Capa do módulo (Links externos) -->
                                                    <div v-if="editingModuleId === mod.id" class="ml-6 mt-2 rounded-lg border border-zinc-200 bg-zinc-50/80 p-3 dark:border-zinc-600 dark:bg-zinc-800/50">
                                                        <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                                                            <p class="text-xs font-medium text-zinc-600 dark:text-zinc-400">Capa do módulo ({{ (section.cover_mode === 'horizontal' ? 'banner' : 'vertical') }})</p>
                                                            <Toggle
                                                                :model-value="editingModuleShowTitleOnCover"
                                                                label="Mostrar título na capa"
                                                                class="!mb-0"
                                                                @update:model-value="(v) => { editingModuleShowTitleOnCover = v; setModuleShowTitleOnCover(v); }"
                                                            />
                                                        </div>
                                                        <div v-if="editingModule?.thumbnail" class="flex items-center gap-3">
                                                            <div :class="section.cover_mode === 'horizontal' ? 'aspect-video w-24 shrink-0' : 'aspect-[2/3] h-20 w-14 shrink-0'" class="overflow-hidden rounded-lg shadow-sm">
                                                                <img :src="editingModule.thumbnail" alt="Capa" class="h-full w-full object-cover" />
                                                            </div>
                                                            <div class="flex flex-wrap gap-2">
                                                                <Button type="button" size="sm" variant="outline" class="!py-1 !text-xs" :disabled="moduleThumbnailUploading" @click="moduleThumbnailFileInput?.click()">Trocar imagem</Button>
                                                                <Button type="button" size="sm" variant="ghost" class="!py-1 !text-xs text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" :disabled="moduleThumbnailUploading" @click="removeModuleThumbnail">Remover</Button>
                                                            </div>
                                                        </div>
                                                        <template v-else>
                                                            <div class="flex flex-wrap items-center gap-2">
                                                                <Button type="button" size="sm" variant="outline" class="!py-1.5 !text-xs" :disabled="moduleThumbnailUploading" @click="moduleThumbnailFileInput?.click()">
                                                                    {{ moduleThumbnailUploading ? 'Enviando…' : 'Enviar capa' }}
                                                                </Button>
                                                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ section.cover_mode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. 4 MB.</span>
                                                            </div>
                                                        </template>
                                                    </div>
                                                </div>
                                            </template>
                                        </template>
                                        <p v-if="!section.modules?.length" class="ml-4 mt-2 text-xs text-zinc-400 dark:text-zinc-500">Nenhum módulo. Clique em + Módulo.</p>
                                    </div>
                                </div>
                            </template>
                            <p v-if="!produto.sections?.length" class="rounded-lg border border-dashed border-zinc-300 py-6 text-center text-sm text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">Nenhuma seção. Clique em &quot;Nova seção&quot; para começar.</p>
                        </div>
                            </div>

                            <!-- Backdrop mobile: fecha o sidebar ao clicar (só abaixo de lg) -->
                            <div
                                v-if="modulosSelectedModuleId"
                                class="fixed inset-0 z-30 bg-black/50 lg:hidden"
                                aria-hidden="true"
                                @click="modulosSelectedModuleId = null; closeModulosLessonForm()"
                            />

                            <!-- Sidebar direito: aulas do módulo + formulário (overlay no mobile, coluna no lg) -->
                            <aside
                                v-if="modulosSelectedModuleId"
                                class="fixed inset-y-0 right-0 z-40 flex w-full max-w-[20rem] flex-col rounded-l-xl border-l border-zinc-200 bg-white shadow-xl dark:border-zinc-700 dark:bg-zinc-900 lg:static lg:inset-auto lg:max-w-none lg:w-64 lg:min-h-0 lg:shrink-0 lg:rounded-l-lg lg:border-t lg:border-zinc-200 lg:bg-zinc-50/50 lg:shadow-none dark:lg:bg-zinc-800/30 lg:min-h-[20rem] lg:border-l lg:border-t-0"
                            >
                                <div class="flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto p-4">
                                    <div class="mb-3 flex items-center justify-between gap-2">
                                        <h3 class="text-sm font-semibold text-zinc-800 dark:text-zinc-200">Aulas do módulo</h3>
                                        <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700 dark:hover:bg-zinc-600 dark:hover:text-zinc-300" title="Fechar" @click="modulosSelectedModuleId = null; closeModulosLessonForm()"><X class="h-4 w-4" /></button>
                                    </div>
                                    <p v-if="modulosSelectedModule" class="mb-3 truncate text-xs text-zinc-500 dark:text-zinc-400">{{ modulosSelectedModule.title }}</p>

                                    <template v-if="!modulosLessonForm">
                                        <ul class="space-y-1">
                                            <li
                                                v-for="lesson in (modulosSelectedModule?.lessons ?? [])"
                                                :key="lesson.id"
                                                class="flex cursor-pointer items-center justify-between gap-2 rounded-lg py-2 px-2 text-sm transition hover:bg-zinc-200/80 dark:hover:bg-zinc-700/50"
                                                @click="openModulosLessonForm(lesson)"
                                            >
                                                <span class="flex min-w-0 flex-1 items-center gap-2 truncate">
                                                    <FileVideo v-if="lesson.type === 'video'" class="h-4 w-4 shrink-0 text-zinc-500" />
                                                    <Link v-else-if="lesson.type === 'link'" class="h-4 w-4 shrink-0 text-zinc-500" />
                                                    <Presentation v-else-if="lesson.type === 'pdf_presentation'" class="h-4 w-4 shrink-0 text-zinc-500" />
                                                    <FileText v-else-if="lesson.type === 'pdf'" class="h-4 w-4 shrink-0 text-zinc-500" />
                                                    <FileText v-else class="h-4 w-4 shrink-0 text-zinc-500" />
                                                    <span class="truncate text-zinc-700 dark:text-zinc-300">{{ lesson.title || 'Sem título' }}</span>
                                                </span>
                                                <button type="button" class="shrink-0 rounded p-1 text-red-600 hover:bg-red-50 dark:hover:bg-red-950/30" title="Remover aula" @click.stop="deleteLesson(lesson.id)"><Trash2 class="h-3 w-3" /></button>
                                            </li>
                                        </ul>
                                        <p v-if="!modulosSelectedModule?.lessons?.length" class="py-3 text-xs text-zinc-500 dark:text-zinc-400">Nenhuma aula neste módulo.</p>
                                        <Button size="sm" class="mt-3 w-full" @click="openModulosLessonForm(null)">
                                            <Plus class="mr-2 h-4 w-4" />
                                            Nova aula
                                        </Button>
                                    </template>

                                    <template v-else>
                                        <div class="space-y-3">
                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título</label>
                                                <input v-model="modulosLessonForm.title" type="text" :class="inputClass" class="w-full" placeholder="Título da aula" />
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Tipo</label>
                                                <select v-model="modulosLessonForm.type" :class="inputClass" class="w-full">
                                                    <option value="video">Vídeo</option>
                                                    <option value="link">Link</option>
                                                    <option value="pdf">Material</option>
                                                    <option value="pdf_presentation">Apresentação (PDF)</option>
                                                    <option value="text">Texto</option>
                                                </select>
                                            </div>
                                            <div>
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Liberação</label>
                                                <div class="grid gap-2">
                                                    <select v-model="modulosLessonForm.release_mode" :class="inputClass" class="w-full">
                                                        <option value="none">Imediata</option>
                                                        <option value="days">Após X dias</option>
                                                        <option value="date">Na data</option>
                                                    </select>
                                                    <input
                                                        v-if="modulosLessonForm.release_mode === 'days'"
                                                        v-model="modulosLessonForm.release_after_days"
                                                        type="number"
                                                        min="1"
                                                        step="1"
                                                        :class="inputClass"
                                                        class="w-full"
                                                        placeholder="Ex.: 7"
                                                    />
                                                    <input
                                                        v-else-if="modulosLessonForm.release_mode === 'date'"
                                                        v-model="modulosLessonForm.release_at_date"
                                                        type="date"
                                                        :class="inputClass"
                                                        class="w-full"
                                                    />
                                                </div>
                                            </div>
                                            <div v-if="modulosLessonForm.type === 'link'">
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Título do link</label>
                                                <input v-model="modulosLessonForm.link_title" type="text" :class="inputClass" class="w-full" placeholder="Ex: Abrir material complementar" />
                                            </div>
                                            <div v-if="modulosLessonForm.type === 'video' || modulosLessonForm.type === 'link' || modulosLessonForm.type === 'pdf' || modulosLessonForm.type === 'pdf_presentation'">
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">URL do conteúdo</label>
                                                <input v-model="modulosLessonForm.content_url" type="url" :class="inputClass" class="w-full" placeholder="https://..." />
                                                <p v-if="modulosLessonForm.type === 'video'" class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                                    Aceita links do YouTube, Vimeo, Wistia, Loom e outras plataformas de vídeo compatíveis.
                                                </p>
                                            </div>
                                            <div v-if="modulosLessonForm.type === 'pdf' || modulosLessonForm.type === 'pdf_presentation'" class="space-y-2">
                                                <input ref="lessonPdfFileInput" type="file" accept=".pdf,application/pdf" multiple class="hidden" @change="onLessonPdfChange" />
                                                <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400">
                                                    {{ modulosLessonForm.type === 'pdf_presentation' ? 'Enviar arquivo (apresentação)' : 'Enviar arquivo (material)' }}
                                                </label>
                                                <div class="flex flex-wrap items-center gap-2">
                                                    <Button type="button" size="sm" variant="outline" :disabled="lessonPdfUploading" @click="lessonPdfFileInput?.click()">
                                                        {{ lessonPdfUploading ? 'Enviando…' : modulosLessonForm.type === 'pdf_presentation' ? 'Selecionar PDFs' : 'Selecionar materiais' }}
                                                    </Button>
                                                    <span v-if="(modulosLessonForm.content_files?.length ?? 0) > 0" class="text-xs text-zinc-500 dark:text-zinc-400">
                                                        {{ modulosLessonForm.content_files.length }} arquivo(s) anexado(s)
                                                    </span>
                                                    <button v-if="(modulosLessonForm.content_files?.length ?? 0) > 0" type="button" class="text-xs text-red-600 hover:underline" @click="clearLessonPdf">Remover todos</button>
                                                </div>
                                                <div v-if="(modulosLessonForm.content_files?.length ?? 0) > 0" class="space-y-1">
                                                    <div
                                                        v-for="(f, i) in modulosLessonForm.content_files"
                                                        :key="`${f.url}-${i}`"
                                                        class="flex items-center justify-between gap-2 rounded-md border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800/50"
                                                    >
                                                        <span class="min-w-0 flex-1 truncate text-zinc-600 dark:text-zinc-300">{{ f.name || (modulosLessonForm.type === 'pdf_presentation' ? 'Apresentação' : 'Material') }}</span>
                                                        <button type="button" class="shrink-0 text-red-600 hover:underline" @click="removeLessonPdfAt(i)">Remover</button>
                                                    </div>
                                                </div>
                                                <p class="text-xs text-zinc-500 dark:text-zinc-400">Ou use a URL acima se o material estiver hospedado em outro site. Máx. 20 MB.</p>
                                            </div>
                                            <div v-if="modulosLessonForm.type === 'text'">
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Texto</label>
                                                <textarea v-model="modulosLessonForm.content_text" :class="inputClass" class="w-full" rows="3" placeholder="Conteúdo da aula..." />
                                            </div>
                                            <div v-if="modulosLessonForm.type === 'video' || modulosLessonForm.type === 'link' || modulosLessonForm.type === 'pdf' || modulosLessonForm.type === 'pdf_presentation'">
                                                <label class="mb-1 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Descrição</label>
                                                <textarea v-model="modulosLessonForm.content_text" :class="inputClass" class="w-full" rows="3" placeholder="Texto ou links para complementar a aula (opcional)..." />
                                            </div>
                                            <div v-if="modulosLessonForm.type === 'video'" class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                                <div class="flex items-center">
                                                    <Toggle v-model="modulosLessonForm.watermark_enabled" label="Marca d'água (proteção DRM)" />
                                                </div>
                                            </div>
                                            <div class="flex gap-2">
                                                <Button variant="outline" size="sm" class="flex-1" @click="closeModulosLessonForm">Cancelar</Button>
                                                <Button size="sm" class="flex-1" :disabled="modulosLessonFormSaving" @click="saveLessonFromSidebar">{{ modulosLessonFormSaving ? 'Salvando…' : 'Salvar' }}</Button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </aside>
                        </div>

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
                            <Button size="sm" @click="openCommunityPageModal">Nova página</Button>
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
                                        <div>
                                            <label class="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">Tipo de URL</label>
                                            <select v-model="configForm.domain_type" :class="inputClass" class="w-full">
                                                <option value="path">Slug</option>
                                                <option value="custom">Domínio</option>
                                            </select>
                                        </div>
                                        <div v-if="configForm.domain_type === 'path'">
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
                                        <div v-else-if="configForm.domain_type === 'custom'">
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
                v-if="showPreview"
                class="hidden min-h-0 flex-1 flex-col overflow-hidden bg-zinc-200 p-4 dark:bg-zinc-900 lg:flex"
            >
                <p class="mb-2 shrink-0 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Preview</p>
                <div class="min-h-0 flex-1 overflow-auto">
                    <MemberBuilderPreview
                        :key="previewKey"
                        :mode="previewMode"
                        :config="configForm.member_area_config"
                        :product-name="produto.name"
                        :sections="produto.sections ?? []"
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
                <div class="w-full max-w-sm overflow-hidden rounded-xl bg-white shadow-xl dark:bg-zinc-900" @click.stop>
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
                                    <select v-model="moduleModalReleaseMode" :class="inputClass" class="w-full">
                                        <option value="none">Imediata</option>
                                        <option value="days">Após X dias</option>
                                        <option value="date">Na data</option>
                                    </select>
                                    <input
                                        v-if="moduleModalReleaseMode === 'days'"
                                        v-model="moduleModalReleaseAfterDays"
                                        type="number"
                                        min="1"
                                        step="1"
                                        :class="inputClass"
                                        class="w-full"
                                        placeholder="Ex.: 7"
                                    />
                                    <input
                                        v-else-if="moduleModalReleaseMode === 'date'"
                                        v-model="moduleModalReleaseAtDate"
                                        type="date"
                                        :class="inputClass"
                                        class="w-full"
                                    />
                                    <div v-else class="hidden sm:block" />
                                </div>
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
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ moduleModalCoverMode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. 4 MB.</p>
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
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ moduleModalCoverMode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. 4 MB.</p>
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
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ moduleModalCoverMode === 'horizontal' ? 'Recomendado: 1200×630 px (banner).' : 'Recomendado: 400×600 px (vertical).' }} Máx. 4 MB.</p>
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
                class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4"
                @click.self="closeCommunityPageModal"
            >
                <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-xl dark:bg-zinc-900" @click.stop>
                    <div class="border-b border-zinc-200 px-5 py-4 dark:border-zinc-700">
                        <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ communityPageModalEditing ? 'Editar página' : 'Nova página da comunidade' }}</h3>
                    </div>
                    <div class="space-y-5 p-5">
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
                            <!-- Painel Emojis -->
                            <div v-if="communityPageIconPickerOpen === 'emoji'" class="max-h-48 overflow-y-auto rounded-xl border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-600 dark:bg-zinc-800/80">
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
                            <!-- Painel Ícones (Lucide) -->
                            <div v-if="communityPageIconPickerOpen === 'icon'" class="max-h-48 overflow-y-auto rounded-xl border border-zinc-200 bg-zinc-50 p-2 dark:border-zinc-600 dark:bg-zinc-800/80">
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
                                <img :src="communityPageModalBannerPreviewUrl" alt="Preview" class="h-28 w-full rounded-xl object-cover object-center" />
                                <button type="button" class="absolute right-2 top-2 rounded-lg bg-black/60 px-2 py-1 text-xs text-white hover:bg-black/80" @click="clearCommunityPageBanner">Remover</button>
                            </div>
                            <Button v-else type="button" size="sm" variant="outline" class="w-full" :disabled="communityPageModalBannerUploading" @click="communityPageModalBannerInputRef?.click()">
                                {{ communityPageModalBannerUploading ? 'Enviando…' : 'Escolher imagem' }}
                            </Button>
                            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">Tamanho ideal: 1200×400 px (proporção 3:1). Máx. 4 MB.</p>
                        </div>
                        <div>
                            <p class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Quem pode publicar?</p>
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    type="button"
                                    :class="[
                                        'flex flex-col items-center gap-2 rounded-xl border-2 p-4 text-center transition',
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
                                        'flex flex-col items-center gap-2 rounded-xl border-2 p-4 text-center transition',
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
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/30">
                            <p class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Página padrão</p>
                            <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">Ao entrar na comunidade, esta página será aberta por padrão. Apenas uma página pode ser a padrão.</p>
                            <Toggle v-model="communityPageModalDefault" label="Definir como página padrão" />
                        </div>
                    </div>
                    <div class="flex justify-end gap-2 border-t border-zinc-200 px-5 py-3 dark:border-zinc-700">
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
