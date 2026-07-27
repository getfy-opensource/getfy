<script setup>
import { ref, reactive, computed, watch, onMounted, onUnmounted, nextTick, defineAsyncComponent, toRaw } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { useSidebar } from '@/composables/useSidebar';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import ImageUpload from '@/components/checkout/ImageUpload.vue';
import CheckoutContentBlocksEditor from '@/components/checkout-builder/CheckoutContentBlocksEditor.vue';
import HorizontalScrollTabs from '@/components/ui/HorizontalScrollTabs.vue';
import { initContentBlocksFromAppearance } from '@/lib/checkoutContentFormats';
import {
    PREVIEW_MESSAGE_TYPE,
    PREVIEW_ACK_TYPE,
    PREVIEW_WINDOW_CALLBACK,
    broadcastPreviewPayload,
} from '@/lib/checkoutBuilderPreview';
import { buildCheckoutEmbedSnippet } from '@/lib/checkoutEmbed';
import {
    ChevronDown,
    ChevronRight,
    DollarSign,
    Palette,
    Clock,
    Bell,
    Video,
    ExternalLink,
    Save,
    UserRound,
    CreditCard,
    Phone,
    Monitor,
    Smartphone,
    RefreshCw,
    Code2,
    BookOpen,
} from 'lucide-vue-next';

const CheckoutCodeEditor = defineAsyncComponent(() => import('@/components/checkout-builder/CheckoutCodeEditor.vue'));

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    produto: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    checkout_scope: {
        type: Object,
        default: () => ({ type: 'main', offer_id: null, plan_id: null, checkout_slug: null, label: '' }),
    },
    cupons: { type: Array, default: () => [] },
    plugin_checkout_templates: { type: Array, default: () => [] },
});

const activeTab = ref('geral');

const sectionsOpen = ref({
    price: true,
    customer_fields: true,
    appearance: true,
    timer: true,
    notification: true,
    video: true,
    redirect: true,
    embed: true,
    seo: true,
    support_button: true,
    footer: true,
    exit_popup: true,
});

function toggleSection(key) {
    sectionsOpen.value[key] = !sectionsOpen.value[key];
}

const configForm = reactive({
    summary: {
        previous_price: props.config?.summary?.previous_price ?? null,
        discount_text: props.config?.summary?.discount_text ?? '',
        show_description: props.config?.summary?.show_description !== false,
        mobile_sticky_footer: props.config?.summary?.mobile_sticky_footer !== false,
        mobile_sticky_icon_url: props.config?.summary?.mobile_sticky_icon_url ?? '',
    },
    appearance: {
        background_color: props.config?.appearance?.background_color ?? '#E3E3E3',
        primary_color: props.config?.appearance?.primary_color ?? '#0ea5e9',
        order_bump_color: props.config?.appearance?.order_bump_color ?? '#F59E0B',
        banners: Array.isArray(props.config?.appearance?.banners)
            ? [...props.config.appearance.banners]
            : [],
        side_banners: Array.isArray(props.config?.appearance?.side_banners)
            ? [...props.config.appearance.side_banners]
            : [],
        content_blocks: initContentBlocksFromAppearance(props.config?.appearance),
    },
    timer: {
        enabled: props.config?.timer?.enabled ?? false,
        text: props.config?.timer?.text ?? 'Esta oferta expira em:',
        hours: props.config?.timer?.hours ?? 0,
        minutes: props.config?.timer?.minutes ?? 15,
        background_color: props.config?.timer?.background_color ?? '#000000',
        text_color: props.config?.timer?.text_color ?? '#FFFFFF',
        sticky: props.config?.timer?.sticky !== false,
    },
    sales_notification: {
        enabled: props.config?.sales_notification?.enabled ?? false,
        title: props.config?.sales_notification?.title ?? 'acabou de comprar',
        names_per_line: props.config?.sales_notification?.names_per_line ?? 1,
        names: props.config?.sales_notification?.names ?? '',
        product_label: props.config?.sales_notification?.product_label ?? props.produto?.name ?? '',
        display_seconds: props.config?.sales_notification?.display_seconds ?? 5,
        interval_seconds: props.config?.sales_notification?.interval_seconds ?? 10,
    },
    customer_fields: {
        name: props.config?.customer_fields?.name !== false,
        cpf: props.config?.customer_fields?.cpf === true,
        phone: props.config?.customer_fields?.phone === true,
        coupon: props.config?.customer_fields?.coupon === true,
    },
    template: props.config?.template ?? 'original',
    youtube_url: props.config?.youtube_url ?? null,
    youtube_position: props.config?.youtube_position ?? 'top',
    redirect_after_purchase: props.config?.redirect_after_purchase ?? '',
    back_redirect: {
        enabled: props.config?.back_redirect?.enabled ?? false,
        url: props.config?.back_redirect?.url ?? '',
    },
    embed: {
        enabled: props.config?.embed?.enabled ?? false,
        allowed_origins: Array.isArray(props.config?.embed?.allowed_origins)
            ? [...props.config.embed.allowed_origins]
            : [],
    },
    seo: {
        title: props.config?.seo?.title ?? '',
        description: props.config?.seo?.description ?? '',
        og_image: props.config?.seo?.og_image ?? null,
        favicon: props.config?.seo?.favicon ?? null,
    },
    support_button: {
        enabled: props.config?.support_button?.enabled ?? false,
        text: props.config?.support_button?.text ?? 'Suporte',
        icon: props.config?.support_button?.icon ?? 'whatsapp',
        position: props.config?.support_button?.position ?? 'bottom-right',
        url: props.config?.support_button?.url ?? '',
        color: props.config?.support_button?.color ?? '#25D366',
    },
    footer: {
        enabled: props.config?.footer?.enabled ?? false,
        logo_url: props.config?.footer?.logo_url ?? '',
        support_email: props.config?.footer?.support_email ?? '',
        text: props.config?.footer?.text ?? '',
    },
    exit_popup: {
        enabled: props.config?.exit_popup?.enabled ?? false,
        triggers: {
            back_button: props.config?.exit_popup?.triggers?.back_button ?? true,
            tab_switch: props.config?.exit_popup?.triggers?.tab_switch ?? true,
            mouse_leave_top: props.config?.exit_popup?.triggers?.mouse_leave_top ?? false,
            timer_seconds: props.config?.exit_popup?.triggers?.timer_seconds ?? null,
        },
        coupon_id: props.config?.exit_popup?.coupon_id ?? null,
        image: props.config?.exit_popup?.image ?? null,
        frequency_per_session: props.config?.exit_popup?.frequency_per_session ?? 1,
        title: props.config?.exit_popup?.title ?? 'Espere! Temos um desconto para você',
        description: props.config?.exit_popup?.description ?? 'Use o cupom abaixo na próxima etapa',
        button_accept: props.config?.exit_popup?.button_accept ?? 'Quero o desconto',
        button_decline: props.config?.exit_popup?.button_decline ?? 'Não, obrigado',
    },
    reviews: Array.isArray(props.config?.reviews)
        ? props.config.reviews.map((r) => ({
              photo: r.photo ?? '',
              author: r.author ?? '',
              description: r.description ?? '',
              stars: Math.min(5, Math.max(1, Number(r.stars) || 5)),
              verified_badge: Boolean(r.verified_badge),
              testimonial_image: r.testimonial_image ?? '',
          }))
        : [],
    advanced: {
        custom_css: props.config?.advanced?.custom_css ?? '',
        custom_head_html: props.config?.advanced?.custom_head_html ?? '',
        custom_body_start_html: props.config?.advanced?.custom_body_start_html ?? '',
        custom_body_end_html: props.config?.advanced?.custom_body_end_html ?? '',
        custom_js: props.config?.advanced?.custom_js ?? '',
    },
});

const form = useForm({
    config: props.config ?? {},
    offer_id: null,
    plan_id: null,
});

function submit() {
    const config = JSON.parse(JSON.stringify(configForm));
    syncLegacyBannersFromContentBlocks(config);
    // Preservar upsell/downsell (configurados na aba do produto, não no Builder)
    if (props.config?.upsell) config.upsell = props.config.upsell;
    if (props.config?.downsell) config.downsell = props.config.downsell;
    form.config = config;
    form.offer_id = props.checkout_scope?.offer_id ?? null;
    form.plan_id = props.checkout_scope?.plan_id ?? null;
    form.put(`/produtos/${props.produto.id}/checkout-config`, {
        onSuccess: () => refreshPreview(),
    });
}

function syncLegacyBannersFromContentBlocks(config) {
    const blocks = config?.appearance?.content_blocks ?? [];
    config.appearance.banners = blocks
        .filter((b) => b?.type === 'image' && b.format === 'hero' && b.placement !== 'sidebar' && b.url)
        .map((b) => b.url);
    config.appearance.side_banners = blocks
        .filter((b) => b?.type === 'image' && b.placement === 'sidebar' && b.url)
        .map((b) => b.url);
}

function defaultReview() {
    return {
        photo: '',
        author: '',
        description: '',
        stars: 5,
        verified_badge: false,
        testimonial_image: '',
    };
}

function addReview() {
    configForm.reviews.push(defaultReview());
}

function removeReview(index) {
    configForm.reviews.splice(index, 1);
}

const previewUrl = computed(() => {
    if (typeof window === 'undefined') return null;
    const scope = props.checkout_scope || {};
    const scopedSlug = (scope.checkout_slug || '').trim();
    const productSlug = (props.produto?.checkout_slug || '').trim();

    const slug = scopedSlug || productSlug;
    if (!slug) return null;

    const url = new URL(`${window.location.origin}/c/${slug}`);
    if (scope.type === 'offer' && scope.offer_id != null && !scopedSlug) {
        url.searchParams.set('offer_id', String(scope.offer_id));
    }
    if (scope.type === 'plan' && scope.plan_id != null && !scopedSlug) {
        url.searchParams.set('plan_id', String(scope.plan_id));
    }
    return url.toString();
});
const previewIframeUrl = computed(() => {
    if (!previewUrl.value) return null;
    const url = new URL(previewUrl.value);
    url.searchParams.set('preview', '1');
    return url.toString();
});

const embedAllowedOriginsText = computed({
    get: () => (configForm.embed.allowed_origins || []).join('\n'),
    set: (val) => {
        configForm.embed.allowed_origins = String(val || '')
            .split(/[\r\n,]+/)
            .map((s) => s.trim())
            .filter(Boolean);
    },
});

const embedSnippet = computed(() => {
    if (!previewUrl.value || !configForm.embed.enabled) {
        return '';
    }
    return buildCheckoutEmbedSnippet(previewUrl.value);
});

const embedCopied = ref(false);

async function copyEmbedSnippet() {
    if (!embedSnippet.value) {
        return;
    }
    try {
        await navigator.clipboard.writeText(embedSnippet.value);
        embedCopied.value = true;
        setTimeout(() => {
            embedCopied.value = false;
        }, 2000);
    } catch (_) {}
}

const previewIframeSrc = computed(() => {
    if (!previewIframeUrl.value) return null;
    const url = new URL(previewIframeUrl.value);
    if (previewReloadNonce.value > 0) {
        url.searchParams.set('_r', String(previewReloadNonce.value));
    }
    return url.toString();
});

function refreshPreview() {
    if (!previewIframeUrl.value) {
        return;
    }
    previewRefreshing.value = true;
    previewSyncedAt.value = null;
    previewReloadNonce.value = Date.now();
}

const previewIframeRef = ref(null);
const previewSyncedAt = ref(null);
const previewViewMode = ref('desktop');
const previewReloadNonce = ref(0);
const previewRefreshing = ref(false);
let previewMessageSeq = 0;
let previewRafId = null;

function buildPreviewConfigSnapshot() {
    const config = JSON.parse(JSON.stringify(toRaw(configForm)));
    syncLegacyBannersFromContentBlocks(config);
    if (props.config?.upsell) config.upsell = props.config.upsell;
    if (props.config?.downsell) config.downsell = props.config.downsell;
    return config;
}

function sendPreviewConfig() {
    const iframeEl = previewIframeRef.value;
    const win = iframeEl?.contentWindow;
    if (!win || !previewIframeUrl.value) {
        return;
    }

    previewMessageSeq += 1;
    const payload = {
        type: PREVIEW_MESSAGE_TYPE,
        config: buildPreviewConfigSnapshot(),
        previewViewport: previewViewMode.value,
        seq: previewMessageSeq,
    };

    try {
        const applyPreview = win[PREVIEW_WINDOW_CALLBACK];
        if (typeof applyPreview === 'function') {
            applyPreview(payload);
        }
    } catch (_) {
        /* cross-origin — fallback postMessage / BroadcastChannel */
    }

    try {
        win.postMessage(payload, '*');
    } catch (_) {}

    broadcastPreviewPayload(payload);
}

function sendPreviewNow() {
    if (previewRafId != null) {
        cancelAnimationFrame(previewRafId);
        previewRafId = null;
    }
    sendPreviewConfig();
}

function onPreviewAckMessage(event) {
    if (event?.data?.type !== PREVIEW_ACK_TYPE) {
        return;
    }
    previewSyncedAt.value = event.data.at ?? Date.now();
}

function schedulePreviewUpdate() {
    if (previewRafId != null) {
        return;
    }
    previewRafId = requestAnimationFrame(() => {
        previewRafId = null;
        sendPreviewConfig();
    });
}

/** Deep watch: `toRaw` + stringify não rastreia mutações aninhadas do reactive(). */
watch(
    configForm,
    () => schedulePreviewUpdate(),
    { deep: true }
);

watch(
    () => configForm.appearance.content_blocks,
    () => sendPreviewNow(),
    { deep: true }
);

watch(previewViewMode, () => sendPreviewNow());

const previewSyncedLabel = computed(() => {
    if (!previewSyncedAt.value) return null;
    const d = new Date(previewSyncedAt.value);
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' });
});

const { setExpanded } = useSidebar();
function onPreviewIframeLoad() {
    previewRefreshing.value = false;
    sendPreviewConfig();
    /** Reenvios: o listener no checkout pode registrar depois do primeiro postMessage no evento load. */
    [30, 120, 400].forEach((ms) => setTimeout(() => sendPreviewConfig(), ms));
}

onMounted(() => {
    setExpanded(false);
    if (typeof window !== 'undefined') {
        window.addEventListener('message', onPreviewAckMessage);
    }
    schedulePreviewUpdate();
    nextTick(() => sendPreviewConfig());
});

onUnmounted(() => {
    if (typeof window !== 'undefined') {
        window.removeEventListener('message', onPreviewAckMessage);
    }
});
const uploadUrl = computed(() => `/produtos/${props.produto?.id}/checkout-upload`);

/** @type {import('vue').Ref<'css' | 'head_html' | 'body_start_html' | 'body_end_html' | 'js'>} */
const activeAdvancedSection = ref('css');
const advancedReferenceOpen = ref(true);

const advancedEditorTabs = [
    { id: 'css', label: 'CSS' },
    { id: 'head_html', label: 'HTML (head)' },
    { id: 'body_start_html', label: 'HTML início' },
    { id: 'body_end_html', label: 'HTML fim' },
    { id: 'js', label: 'JavaScript' },
];

/** Templates de checkout disponíveis. Core + plugin_checkout_templates (manifest). */
const availableCheckoutTemplates = computed(() => {
    const core = [
        { id: 'original', name: 'Original', description: 'Layout padrão do checkout (resumo, formulário e sidebar).' },
    ];
    const fromPlugins = (props.plugin_checkout_templates || []).map((t) => ({
        id: t.id,
        name: t.name,
        description: t.description || '',
        plugin_slug: t.plugin_slug,
    }));
    return [...core, ...fromPlugins];
});

const inputClass =
    'block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-zinc-900 placeholder-zinc-400 transition focus:border-[var(--color-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary)]/20 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500';
</script>

<template>
    <div class="flex h-[calc(100dvh-10rem)] min-h-0 flex-col gap-3">
        <div class="shrink-0">
            <nav class="text-sm text-zinc-500 dark:text-zinc-400" aria-label="Breadcrumb">
                <Link href="/produtos" class="hover:text-zinc-700 dark:hover:text-zinc-300">Produtos</Link>
                <span class="mx-1">/</span>
                <Link :href="`/produtos/${produto.id}/edit`" class="hover:text-zinc-700 dark:hover:text-zinc-300">
                    {{ produto.name }}
                </Link>
                <span class="mx-1">/</span>
                <span class="text-zinc-900 dark:text-white">Editar checkout{{ checkout_scope?.label ? ` — ${checkout_scope.label}` : '' }}</span>
            </nav>
        </div>

        <div class="flex min-h-0 flex-1 gap-4 lg:flex-row">
            <!-- Sidebar esquerda: rolagem apenas aqui -->
            <div class="min-h-0 w-full shrink-0 space-y-4 overflow-y-auto lg:w-[380px] lg:max-h-full">
                <!-- Tabs -->
                <HorizontalScrollTabs
                    aria-label="Abas do checkout"
                    :bleed="false"
                    nav-class="gap-1 rounded-xl border border-zinc-200 bg-white p-1 dark:border-zinc-700 dark:bg-zinc-800"
                >
                    <button
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-2 text-xs font-medium transition sm:text-sm',
                            activeTab === 'geral'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-700',
                        ]"
                        @click="activeTab = 'geral'"
                    >
                        Geral
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-2 text-xs font-medium transition sm:text-sm',
                            activeTab === 'recursos'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-700',
                        ]"
                        @click="activeTab = 'recursos'"
                    >
                        Recursos
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-2 text-xs font-medium transition sm:text-sm',
                            activeTab === 'vendas'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-700',
                        ]"
                        @click="activeTab = 'vendas'"
                    >
                        Conteúdo
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-2 text-xs font-medium transition sm:text-sm',
                            activeTab === 'template'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-700',
                        ]"
                        @click="activeTab = 'template'"
                    >
                        Template
                    </button>
                    <button
                        type="button"
                        :class="[
                            'rounded-lg px-3 py-2 text-xs font-medium transition sm:text-sm',
                            activeTab === 'social'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-700',
                        ]"
                        @click="activeTab = 'social'"
                    >
                        Social
                    </button>
                    <button
                        type="button"
                        :class="[
                            'flex items-center gap-1 rounded-lg px-3 py-2 text-xs font-medium transition sm:text-sm',
                            activeTab === 'avancado'
                                ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-700',
                        ]"
                        @click="activeTab = 'avancado'"
                    >
                        <Code2 class="h-3.5 w-3.5 shrink-0 opacity-80" />
                        Avançado
                    </button>
                </HorizontalScrollTabs>

                <!-- Aba Geral -->
                <div v-show="activeTab === 'geral'" class="space-y-4">
                <!-- Preço / Desconto -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('price')"
                    >
                        <span class="flex items-center gap-2">
                            <DollarSign class="h-5 w-5 text-zinc-500" />
                            Preço / Desconto
                        </span>
                        <ChevronDown v-if="sectionsOpen.price" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.price" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Preço anterior
                                </label>
                                <input
                                    v-model.number="configForm.summary.previous_price"
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    :class="inputClass"
                                    placeholder="0.00"
                                />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Texto de desconto (ex.: 50% OFF)
                                </label>
                                <input
                                    v-model="configForm.summary.discount_text"
                                    type="text"
                                    :class="inputClass"
                                    placeholder="50% OFF"
                                />
                            </div>
                            <Toggle
                                v-model="configForm.summary.show_description"
                                label="Exibir descrição do produto no resumo"
                            />
                            <Toggle
                                v-model="configForm.summary.mobile_sticky_footer"
                                label="Resumo fixo no rodapé (somente mobile)"
                            />
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Mostra um resumo expansível do pedido fixo na parte inferior da tela em celulares.
                            </p>
                            <div v-show="configForm.summary.mobile_sticky_footer" class="space-y-2 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Ícone do resumo (opcional)
                                </label>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Sem imagem, usa o ícone padrão de sacola. Envie uma imagem quadrada para personalizar.
                                </p>
                                <ImageUpload
                                    v-model="configForm.summary.mobile_sticky_icon_url"
                                    :upload-url="uploadUrl"
                                    label="Imagem do ícone"
                                    recommended-size="128×128 px (quadrada)"
                                    aspect-format="square"
                                />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Campos do formulário -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('customer_fields')"
                    >
                        <span class="flex items-center gap-2">
                            <UserRound class="h-5 w-5 text-zinc-500" />
                            Campos do formulário
                        </span>
                        <ChevronDown v-if="sectionsOpen.customer_fields" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.customer_fields" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                            Escolha quais campos exibir no checkout. E-mail é sempre obrigatório.
                        </p>
                        <div class="space-y-4">
                            <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                <span class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    <UserRound class="h-4 w-4 text-zinc-500" />
                                    Nome
                                </span>
                                <Toggle v-model="configForm.customer_fields.name" />
                            </div>
                            <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                <span class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    <CreditCard class="h-4 w-4 text-zinc-500" />
                                    CPF
                                </span>
                                <Toggle v-model="configForm.customer_fields.cpf" />
                            </div>
                            <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                <span class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    <Phone class="h-4 w-4 text-zinc-500" />
                                    Telefone (com código do país)
                                </span>
                                <Toggle v-model="configForm.customer_fields.phone" />
                            </div>
                            <div class="flex items-center justify-between rounded-xl border border-zinc-100 bg-zinc-50/50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                <span class="flex items-center gap-2 text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Cupom / desconto
                                </span>
                                <Toggle v-model="configForm.customer_fields.coupon" />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aparência -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('appearance')"
                    >
                        <span class="flex items-center gap-2">
                            <Palette class="h-5 w-5 text-zinc-500" />
                            Aparência
                        </span>
                        <ChevronDown v-if="sectionsOpen.appearance" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.appearance" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Cor de fundo
                                </label>
                                <div class="flex gap-2">
                                    <input
                                        v-model="configForm.appearance.background_color"
                                        type="color"
                                        class="h-10 w-14 cursor-pointer rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-600"
                                    />
                                    <input
                                        v-model="configForm.appearance.background_color"
                                        type="text"
                                        :class="inputClass + ' flex-1'"
                                    />
                                </div>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Cor primária
                                </label>
                                <div class="flex gap-2">
                                    <input
                                        v-model="configForm.appearance.primary_color"
                                        type="color"
                                        class="h-10 w-14 cursor-pointer rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-600"
                                    />
                                    <input
                                        v-model="configForm.appearance.primary_color"
                                        type="text"
                                        :class="inputClass + ' flex-1'"
                                    />
                                </div>
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Cor do order bump
                                </label>
                                <div class="flex gap-2">
                                    <input
                                        v-model="configForm.appearance.order_bump_color"
                                        type="color"
                                        class="h-10 w-14 cursor-pointer rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-600"
                                    />
                                    <input
                                        v-model="configForm.appearance.order_bump_color"
                                        type="text"
                                        :class="inputClass + ' flex-1'"
                                    />
                                </div>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Cor da borda, fundo e tag "Oferta especial" dos produtos sugeridos.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cronômetro -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('timer')"
                    >
                        <span class="flex items-center gap-2">
                            <Clock class="h-5 w-5 text-zinc-500" />
                            Cronômetro
                        </span>
                        <ChevronDown v-if="sectionsOpen.timer" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.timer" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <div class="space-y-4">
                            <Toggle v-model="configForm.timer.enabled" label="Ativar cronômetro" />
                            <div v-show="configForm.timer.enabled">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Texto</label>
                                    <input v-model="configForm.timer.text" type="text" :class="inputClass" />
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Horas</label>
                                        <input
                                            v-model.number="configForm.timer.hours"
                                            type="number"
                                            min="0"
                                            max="168"
                                            :class="inputClass"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Minutos</label>
                                        <input
                                            v-model.number="configForm.timer.minutes"
                                            type="number"
                                            min="0"
                                            max="999"
                                            :class="inputClass"
                                        />
                                    </div>
                                </div>
                                <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    Ex.: 5 horas e 30 minutos. Se alterar o tempo, o cronômetro reinicia no checkout.
                                </p>
                                <div class="mt-3 grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="mb-1 block text-xs text-zinc-500">Fundo</label>
                                        <input v-model="configForm.timer.background_color" type="color" class="h-9 w-full cursor-pointer rounded border" />
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-xs text-zinc-500">Texto</label>
                                        <input v-model="configForm.timer.text_color" type="color" class="h-9 w-full cursor-pointer rounded border" />
                                    </div>
                                </div>
                                <div class="pt-2">
                                    <Toggle v-model="configForm.timer.sticky" label="Fixar no topo ao rolar" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Notificações -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('notification')"
                    >
                        <span class="flex items-center gap-2">
                            <Bell class="h-5 w-5 text-zinc-500" />
                            Notificações
                        </span>
                        <ChevronDown v-if="sectionsOpen.notification" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.notification" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <div class="space-y-4">
                            <Toggle v-model="configForm.sales_notification.enabled" label="Ativar notificações" />
                            <div v-show="configForm.sales_notification.enabled">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Texto após o nome (ex.: acabou de comprar)</label>
                                    <input v-model="configForm.sales_notification.title" type="text" :class="inputClass" placeholder="acabou de comprar" />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Exibido como: <strong>Nome, [este texto]</strong></p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nomes (um por linha)</label>
                                    <textarea v-model="configForm.sales_notification.names" rows="4" :class="inputClass" placeholder="Maria&#10;João&#10;Ana" />
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Texto do produto na notificação (descrição)</label>
                                    <input v-model="configForm.sales_notification.product_label" type="text" :class="inputClass" :placeholder="produto.name" />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Por padrão usa o nome do produto.</p>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Tempo de exibição (s)</label>
                                        <input v-model.number="configForm.sales_notification.display_seconds" type="number" min="0" step="0.5" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Intervalo (s)</label>
                                        <input v-model.number="configForm.sales_notification.interval_seconds" type="number" min="0" step="0.5" :class="inputClass" />
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Vídeo YouTube -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('video')"
                    >
                        <span class="flex items-center gap-2">
                            <Video class="h-5 w-5 text-zinc-500" />
                            Vídeo YouTube
                        </span>
                        <ChevronDown v-if="sectionsOpen.video" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.video" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL do vídeo</label>
                                <input v-model="configForm.youtube_url" type="url" :class="inputClass" placeholder="https://www.youtube.com/watch?v=..." />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Posição do vídeo</label>
                                <select v-model="configForm.youtube_position" :class="inputClass">
                                    <option value="top">No topo da página</option>
                                    <option value="bottom">Em baixo da página</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Redirecionamento -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('redirect')"
                    >
                        <span class="flex items-center gap-2">Redirecionamento</span>
                        <ChevronDown v-if="sectionsOpen.redirect" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.redirect" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <div class="space-y-4">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Redirecionar após compra (URL)</label>
                                <input v-model="configForm.redirect_after_purchase" type="url" :class="inputClass" placeholder="https://... ou /obrigado" />
                            </div>
                            <Toggle v-model="configForm.back_redirect.enabled" label="Ativar back redirect" />
                            <div v-show="configForm.back_redirect.enabled">
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL ao clicar voltar</label>
                                <input v-model="configForm.back_redirect.url" type="url" :class="inputClass" placeholder="https://..." />
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Incorporar em site externo (iframe) -->
                <div class="panel-card-lg">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                        @click="toggleSection('embed')"
                    >
                        <span class="flex items-center gap-2">
                            <Code2 class="h-4 w-4 text-zinc-500" />
                            Incorporar em iframe
                        </span>
                        <ChevronDown v-if="sectionsOpen.embed" class="h-5 w-5 shrink-0" />
                        <ChevronRight v-else class="h-5 w-5 shrink-0" />
                    </button>
                    <div v-show="sectionsOpen.embed" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                        <div class="space-y-4">
                            <Toggle
                                v-model="configForm.embed.enabled"
                                label="Permitir carregar o checkout em iframe em páginas externas"
                            />
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Quando ativo, o checkout pode ser embutido no seu site de vendas, landing page ou blog.
                                Requer HTTPS em produção para pagamentos com cartão e carteiras digitais.
                            </p>
                            <div v-show="configForm.embed.enabled">
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                    Domínios permitidos (opcional)
                                </label>
                                <textarea
                                    v-model="embedAllowedOriginsText"
                                    rows="3"
                                    :class="inputClass"
                                    placeholder="https://meusite.com.br&#10;https://www.outrosite.com"
                                />
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    Um domínio por linha. Deixe vazio para permitir qualquer site incorporar o checkout.
                                </p>
                                <div v-if="previewUrl" class="mt-4 space-y-2">
                                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        Código para colar no seu site
                                    </label>
                                    <textarea
                                        :value="embedSnippet"
                                        rows="10"
                                        readonly
                                        class="w-full rounded-lg border border-zinc-200 bg-zinc-50 font-mono text-xs text-zinc-800 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200"
                                    />
                                    <div class="flex flex-wrap items-center gap-2">
                                        <Button type="button" size="sm" @click="copyEmbedSnippet">
                                            {{ embedCopied ? 'Copiado!' : 'Copiar código' }}
                                        </Button>
                                        <a
                                            :href="previewUrl"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="inline-flex items-center gap-1 text-sm font-medium text-sky-600 hover:underline dark:text-sky-400"
                                        >
                                            <ExternalLink class="h-3.5 w-3.5" />
                                            Abrir checkout
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                </div>

                <!-- Aba Recursos -->
                <div v-show="activeTab === 'recursos'" class="space-y-4">
                    <!-- SEO e Compartilhamento -->
                    <div class="panel-card-lg">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                            @click="toggleSection('seo')"
                        >
                            <span class="flex items-center gap-2">SEO e Compartilhamento</span>
                            <ChevronDown v-if="sectionsOpen.seo" class="h-5 w-5 shrink-0" />
                            <ChevronRight v-else class="h-5 w-5 shrink-0" />
                        </button>
                        <div v-show="sectionsOpen.seo" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                            <div class="space-y-4">
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Título para compartilhamento</label>
                                    <input v-model="configForm.seo.title" type="text" :class="inputClass" placeholder="Ex.: Nome do produto - Checkout" />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Aparece na aba do navegador e ao compartilhar o link (redes sociais). Vazio = nome do produto.</p>
                                </div>
                                <div>
                                    <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Descrição</label>
                                    <textarea v-model="configForm.seo.description" rows="2" :class="inputClass" placeholder="Descrição para redes sociais" />
                                </div>
                                <div>
                                    <ImageUpload
                                        v-model="configForm.seo.og_image"
                                        :upload-url="uploadUrl"
                                        label="Imagem Open Graph (compartilhamento)"
                                        recommended-size="1200×630 px"
                                    />
                                </div>
                                <div>
                                    <ImageUpload
                                        v-model="configForm.seo.favicon"
                                        :upload-url="uploadUrl"
                                        label="Favicon"
                                        recommended-size="32×32 px ou 48×48 px (quadrado)"
                                    />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Vazio = favicon da plataforma</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- Rodapé do checkout -->
                    <div class="panel-card-lg">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                            @click="toggleSection('footer')"
                        >
                            <span class="flex items-center gap-2">Rodapé do checkout</span>
                            <ChevronDown v-if="sectionsOpen.footer" class="h-5 w-5 shrink-0" />
                            <ChevronRight v-else class="h-5 w-5 shrink-0" />
                        </button>
                        <div v-show="sectionsOpen.footer" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                            <div class="space-y-4">
                                <Toggle v-model="configForm.footer.enabled" label="Ativar rodapé personalizado" />
                                <template v-if="configForm.footer.enabled">
                                    <div>
                                        <ImageUpload
                                            v-model="configForm.footer.logo_url"
                                            :upload-url="uploadUrl"
                                            label="Logo do rodapé (opcional)"
                                            recommended-size="240×80 px (horizontal)"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Nome/Texto</label>
                                        <input v-model="configForm.footer.text" type="text" :class="inputClass" placeholder="Ex.: Minha Empresa" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">E-mail de suporte</label>
                                        <input v-model="configForm.footer.support_email" type="email" :class="inputClass" placeholder="suporte@exemplo.com" />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <!-- Botão de suporte -->
                    <div class="panel-card-lg">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                            @click="toggleSection('support_button')"
                        >
                            <span class="flex items-center gap-2">Botão de suporte</span>
                            <ChevronDown v-if="sectionsOpen.support_button" class="h-5 w-5 shrink-0" />
                            <ChevronRight v-else class="h-5 w-5 shrink-0" />
                        </button>
                        <div v-show="sectionsOpen.support_button" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                            <div class="space-y-4">
                                <Toggle v-model="configForm.support_button.enabled" label="Ativar botão de suporte" />
                                <template v-if="configForm.support_button.enabled">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Texto do botão</label>
                                        <input v-model="configForm.support_button.text" type="text" :class="inputClass" placeholder="Suporte" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Ícone</label>
                                        <select v-model="configForm.support_button.icon" :class="inputClass">
                                            <option value="whatsapp">WhatsApp</option>
                                            <option value="message-circle">Mensagem</option>
                                            <option value="headset">Headset</option>
                                            <option value="help-circle">Ajuda</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Cor do botão</label>
                                        <div class="flex gap-2">
                                            <input
                                                v-model="configForm.support_button.color"
                                                type="color"
                                                class="h-10 w-14 cursor-pointer rounded-lg border border-zinc-200 p-0.5 dark:border-zinc-600"
                                            />
                                            <input
                                                v-model="configForm.support_button.color"
                                                type="text"
                                                :class="inputClass + ' flex-1'"
                                            />
                                        </div>
                                        <p class="mt-1 text-xs text-zinc-500">Padrão: verde WhatsApp</p>
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Posição</label>
                                        <select v-model="configForm.support_button.position" :class="inputClass">
                                            <option value="bottom-right">Inferior direito</option>
                                            <option value="bottom-left">Inferior esquerdo</option>
                                            <option value="top-right">Superior direito</option>
                                            <option value="top-left">Superior esquerdo</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">URL de redirecionamento</label>
                                        <input v-model="configForm.support_button.url" type="url" :class="inputClass" placeholder="https://..." />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                    <!-- Exit popup -->
                    <div class="panel-card-lg">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left font-semibold text-zinc-900 dark:text-white"
                            @click="toggleSection('exit_popup')"
                        >
                            <span class="flex items-center gap-2">Exit popup</span>
                            <ChevronDown v-if="sectionsOpen.exit_popup" class="h-5 w-5 shrink-0" />
                            <ChevronRight v-else class="h-5 w-5 shrink-0" />
                        </button>
                        <div v-show="sectionsOpen.exit_popup" class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                            <div class="space-y-4">
                                <Toggle v-model="configForm.exit_popup.enabled" label="Ativar exit popup" />
                                <template v-if="configForm.exit_popup.enabled">
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Gatilhos</label>
                                        <div class="space-y-2 rounded-xl border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                                            <label class="flex items-center gap-2">
                                                <input v-model="configForm.exit_popup.triggers.back_button" type="checkbox" class="rounded border-zinc-300" />
                                                <span class="text-sm">Botão voltar do navegador</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input v-model="configForm.exit_popup.triggers.tab_switch" type="checkbox" class="rounded border-zinc-300" />
                                                <span class="text-sm">Troca de aba</span>
                                            </label>
                                            <label class="flex items-center gap-2">
                                                <input v-model="configForm.exit_popup.triggers.mouse_leave_top" type="checkbox" class="rounded border-zinc-300" />
                                                <span class="text-sm">Mouse saindo pelo topo</span>
                                            </label>
                                            <div class="flex items-center gap-2 pt-1">
                                                <label class="text-sm">Timer (segundos):</label>
                                                <input
                                                    v-model.number="configForm.exit_popup.triggers.timer_seconds"
                                                    type="number"
                                                    min="0"
                                                    :class="inputClass + ' w-20'"
                                                    placeholder="0"
                                                />
                                                <span class="text-xs text-zinc-500">0 = desativado</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Cupom de desconto</label>
                                        <select v-model="configForm.exit_popup.coupon_id" :class="inputClass">
                                            <option :value="null">Nenhum</option>
                                            <option v-for="c in cupons" :key="c.id" :value="c.id">
                                                {{ c.code }} ({{ c.type === 'percent' ? c.value + '%' : 'R$ ' + c.value }})
                                            </option>
                                        </select>
                                    </div>
                                    <div>
                                        <ImageUpload
                                            v-model="configForm.exit_popup.image"
                                            :upload-url="uploadUrl"
                                            label="Imagem do popup (opcional)"
                                            recommended-size="600×400 px"
                                        />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Frequência (vezes por sessão)</label>
                                        <input v-model.number="configForm.exit_popup.frequency_per_session" type="number" min="1" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Título</label>
                                        <input v-model="configForm.exit_popup.title" type="text" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Descrição</label>
                                        <textarea v-model="configForm.exit_popup.description" rows="2" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Texto do botão aceitar</label>
                                        <input v-model="configForm.exit_popup.button_accept" type="text" :class="inputClass" />
                                    </div>
                                    <div>
                                        <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Texto do botão recusar</label>
                                        <input v-model="configForm.exit_popup.button_decline" type="text" :class="inputClass" />
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aba Conteúdo (banners, hero e textos do checkout) -->
                <div v-show="activeTab === 'vendas'" class="space-y-4">
                    <div class="panel-card-lg p-4">
                        <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                            Configure hero, banners laterais e blocos de texto acima do formulário. A miniatura abaixo de cada bloco mostra como ficará no checkout.
                        </p>
                        <CheckoutContentBlocksEditor
                            v-model="configForm.appearance.content_blocks"
                            :upload-url="uploadUrl"
                            @changed="sendPreviewNow"
                        />
                    </div>
                </div>

                <!-- Aba Template: lista de templates (Original + futuros via plugins) -->
                <div v-show="activeTab === 'template'" class="space-y-4">
                    <div class="panel-card-lg">
                        <p class="mb-5 text-sm font-medium text-zinc-700 dark:text-zinc-300">Escolha o template do checkout</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <button
                                v-for="tpl in availableCheckoutTemplates"
                                :key="tpl.id"
                                type="button"
                                :class="[
                                    'flex flex-col items-start gap-2 rounded-xl border-2 p-4 text-left transition',
                                    configForm.template === tpl.id
                                        ? 'border-zinc-900 bg-zinc-50 dark:border-zinc-100 dark:bg-zinc-700/50'
                                        : 'border-zinc-200 hover:border-zinc-300 hover:bg-zinc-50/50 dark:border-zinc-600 dark:hover:border-zinc-500 dark:hover:bg-zinc-700/30',
                                ]"
                                @click="configForm.template = tpl.id"
                            >
                                <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ tpl.name }}</span>
                                <span v-if="tpl.description" class="text-xs text-zinc-500 dark:text-zinc-400">{{ tpl.description }}</span>
                                <span
                                    v-if="configForm.template === tpl.id"
                                    class="mt-1 text-xs font-medium text-emerald-600 dark:text-emerald-400"
                                >
                                    Em uso
                                </span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Aba Social -->
                <div v-show="activeTab === 'social'" class="space-y-4">
                    <div class="panel-card-lg">
                        <div class="px-4 py-3 font-semibold text-zinc-900 dark:text-white">Provas sociais (avaliações)</div>
                        <div class="border-t border-zinc-200 px-4 py-4 dark:border-zinc-700">
                            <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                                Adicione depoimentos de clientes para exibir no checkout.
                            </p>
                            <div class="space-y-4">
                                <div
                                    v-for="(review, idx) in configForm.reviews"
                                    :key="'r-' + idx"
                                    class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
                                >
                                    <div class="mb-3 flex items-center justify-between">
                                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Avaliação {{ idx + 1 }}</span>
                                        <Button type="button" variant="outline" size="icon" @click="removeReview(idx)">×</Button>
                                    </div>
                                    <div class="space-y-3">
                                        <ImageUpload
                                            v-model="review.photo"
                                            :upload-url="uploadUrl"
                                            label="Foto do cliente"
                                            recommended-size="200×200 px (quadrado)"
                                        />
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-zinc-500">Autor</label>
                                            <input v-model="review.author" type="text" :class="inputClass" placeholder="Nome" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-zinc-500">Descrição</label>
                                            <textarea v-model="review.description" rows="2" :class="inputClass" placeholder="Depoimento" />
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-zinc-500">Estrelas (1-5)</label>
                                            <select v-model.number="review.stars" :class="inputClass">
                                                <option :value="1">1</option>
                                                <option :value="2">2</option>
                                                <option :value="3">3</option>
                                                <option :value="4">4</option>
                                                <option :value="5">5</option>
                                            </select>
                                        </div>
                                        <div class="flex items-center justify-between">
                                            <span class="text-xs font-medium text-zinc-500">Badge cliente verificado</span>
                                            <Toggle v-model="review.verified_badge" />
                                        </div>
                                        <ImageUpload
                                            v-model="review.testimonial_image"
                                            :upload-url="uploadUrl"
                                            label="Imagem do depoimento (print, feedback)"
                                            recommended-size="800×600 px"
                                        />
                                    </div>
                                </div>
                                <Button type="button" variant="secondary" size="sm" @click="addReview">+ Adicionar avaliação</Button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Aba Avançado: CSS / HTML / JS -->
                <div v-show="activeTab === 'avancado'" class="space-y-4">
                    <div
                        class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100"
                    >
                        <p class="font-medium">Código personalizado na página pública</p>
                        <p class="mt-1 text-xs opacity-90">
                            Apenas administradores podem editar. HTML e JavaScript são exibidos para todos os compradores; use com cuidado.
                            Em caso de conta comprometida, isso pode ser vetor de XSS.
                        </p>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-semibold text-zinc-900 dark:text-white"
                            @click="advancedReferenceOpen = !advancedReferenceOpen"
                        >
                            <span class="flex items-center gap-2">
                                <BookOpen class="h-4 w-4 text-zinc-500" />
                                Referência rápida (seletores)
                            </span>
                            <ChevronDown v-if="advancedReferenceOpen" class="h-5 w-5 shrink-0" />
                            <ChevronRight v-else class="h-5 w-5 shrink-0" />
                        </button>
                        <div
                            v-show="advancedReferenceOpen"
                            class="space-y-3 border-t border-zinc-200 px-4 py-3 text-xs text-zinc-600 dark:border-zinc-700 dark:text-zinc-400"
                        >
                            <p>
                                No editor CSS/JS/HTML use <strong>Ctrl+Espaço</strong> (ou Cmd+Espaço no Mac) para autocomplete com
                                dezenas de seletores (<code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">data-checkout</code>,
                                <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">#getfy-checkout-root</code>,
                                <code class="rounded bg-zinc-100 px-1 dark:bg-zinc-800">data-payment-method</code>, etc.).
                            </p>
                            <p class="text-zinc-500 dark:text-zinc-500">Exemplos rápidos:</p>
                            <ul class="list-disc space-y-1 pl-4 font-mono text-[11px] text-zinc-800 dark:text-zinc-300">
                                <li>#getfy-checkout-root</li>
                                <li>[data-checkout="card-main"]</li>
                                <li>[data-checkout="form-submit"]</li>
                                <li>[data-payment-method="pix"]</li>
                            </ul>
                            <a
                                v-if="previewUrl"
                                :href="previewUrl"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 font-medium text-sky-600 hover:underline dark:text-sky-400"
                            >
                                <ExternalLink class="h-3.5 w-3.5" />
                                Abrir checkout em nova aba
                            </a>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1 rounded-xl border border-zinc-200 bg-zinc-50 p-1 dark:border-zinc-700 dark:bg-zinc-900/50">
                        <button
                            v-for="tab in advancedEditorTabs"
                            :key="tab.id"
                            type="button"
                            :class="[
                                'rounded-lg px-2.5 py-1.5 text-xs font-medium transition sm:text-sm',
                                activeAdvancedSection === tab.id
                                    ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                    : 'text-zinc-600 hover:bg-zinc-200/80 dark:text-zinc-400 dark:hover:bg-zinc-700',
                            ]"
                            @click="activeAdvancedSection = tab.id"
                        >
                            {{ tab.label }}
                        </button>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-white p-2 dark:border-zinc-700 dark:bg-zinc-800">
                        <CheckoutCodeEditor
                            v-if="activeAdvancedSection === 'css'"
                            :key="'adv-css'"
                            v-model="configForm.advanced.custom_css"
                            language="css"
                            min-height="240px"
                        />
                        <CheckoutCodeEditor
                            v-else-if="activeAdvancedSection === 'head_html'"
                            :key="'adv-head'"
                            v-model="configForm.advanced.custom_head_html"
                            language="html"
                            min-height="200px"
                        />
                        <CheckoutCodeEditor
                            v-else-if="activeAdvancedSection === 'body_start_html'"
                            :key="'adv-bs'"
                            v-model="configForm.advanced.custom_body_start_html"
                            language="html"
                            min-height="200px"
                        />
                        <CheckoutCodeEditor
                            v-else-if="activeAdvancedSection === 'body_end_html'"
                            :key="'adv-be'"
                            v-model="configForm.advanced.custom_body_end_html"
                            language="html"
                            min-height="200px"
                        />
                        <CheckoutCodeEditor
                            v-else-if="activeAdvancedSection === 'js'"
                            :key="'adv-js'"
                            v-model="configForm.advanced.custom_js"
                            language="javascript"
                            min-height="260px"
                        />
                    </div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        HTML (head): meta tags, fontes externas, etc. HTML início/fim: blocos visíveis no corpo. JavaScript: executado após o carregamento;
                        no preview ao vivo pode rodar de novo a cada alteração.
                    </p>
                </div>

                <Button type="button" class="w-full" :disabled="form.processing" @click="submit">
                    <Save class="h-4 w-4" />
                    Salvar checkout
                </Button>
            </div>

            <!-- Área direita: preview ocupa toda a altura disponível -->
            <div class="panel-table flex min-h-0 min-w-0 flex-1 flex-col overflow-hidden">
                <div class="flex shrink-0 flex-wrap items-center justify-between gap-2 border-b border-zinc-200 px-4 py-2 dark:border-zinc-700">
                    <div>
                        <p class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Preview em tempo real</p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                            Use o botão de atualizar para recarregar o preview.
                            <span v-if="previewSyncedLabel" class="ml-1 inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-emerald-500" />
                                {{ previewSyncedLabel }}
                            </span>
                        </p>
                    </div>
                    <div class="flex items-center gap-2">
                        <div class="flex rounded-lg border border-zinc-200 bg-white p-0.5 dark:border-zinc-600 dark:bg-zinc-700">
                            <button
                                type="button"
                                :class="[
                                    'rounded-md p-1.5 transition',
                                    previewViewMode === 'desktop'
                                        ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-600',
                                ]"
                                aria-label="Preview desktop"
                                title="Desktop"
                                @click="previewViewMode = 'desktop'"
                            >
                                <Monitor class="h-4 w-4" />
                            </button>
                            <button
                                type="button"
                                :class="[
                                    'rounded-md p-1.5 transition',
                                    previewViewMode === 'mobile'
                                        ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900'
                                        : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-600',
                                ]"
                                aria-label="Preview mobile"
                                title="Mobile"
                                @click="previewViewMode = 'mobile'"
                            >
                                <Smartphone class="h-4 w-4" />
                            </button>
                        </div>
                        <button
                            type="button"
                            :disabled="!previewIframeUrl || previewRefreshing"
                            class="rounded-lg border border-zinc-200 bg-white p-1.5 text-zinc-600 transition hover:border-zinc-300 hover:bg-zinc-50 hover:text-zinc-800 disabled:cursor-not-allowed disabled:opacity-50 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:bg-zinc-600 dark:hover:text-zinc-200"
                            aria-label="Atualizar preview"
                            title="Atualizar preview"
                            @click="refreshPreview"
                        >
                            <RefreshCw :class="['h-4 w-4', previewRefreshing && 'animate-spin']" />
                        </button>
                        <a
                            v-if="previewUrl"
                            :href="previewUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="rounded-lg border border-zinc-200 bg-white p-1.5 text-zinc-600 transition hover:border-zinc-300 hover:bg-zinc-50 hover:text-zinc-800 dark:border-zinc-600 dark:bg-zinc-700 dark:text-zinc-400 dark:hover:border-zinc-500 dark:hover:bg-zinc-600 dark:hover:text-zinc-200"
                            aria-label="Visualizar checkout em nova aba"
                            title="Visualizar checkout"
                        >
                            <ExternalLink class="h-4 w-4" />
                        </a>
                    </div>
                </div>
                <div class="flex min-h-0 flex-1 flex-col overflow-hidden p-3">
                    <div
                        v-if="previewIframeUrl"
                        class="flex min-h-0 flex-1"
                        :class="previewViewMode === 'mobile' ? 'justify-center' : 'justify-stretch'"
                    >
                        <div
                            :class="[
                                'flex min-h-0 flex-1 flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-950',
                                previewViewMode === 'mobile' ? 'w-[375px] max-w-full shrink-0' : 'w-full',
                            ]"
                        >
                            <iframe
                                ref="previewIframeRef"
                                :src="previewIframeSrc"
                                title="Preview do checkout"
                                class="block h-full min-h-0 w-full flex-1 border-0 bg-white"
                                @load="onPreviewIframeLoad"
                            />
                        </div>
                    </div>
                    <div
                        v-else
                        class="flex min-h-0 flex-1 items-center justify-center rounded-xl border border-zinc-200 bg-zinc-100/80 text-sm text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800/80 dark:text-zinc-400"
                    >
                        Configure o slug do checkout do produto para ver o preview.
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
