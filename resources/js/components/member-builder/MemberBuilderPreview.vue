<script setup>
import { computed } from 'vue';
import { MessageSquare } from 'lucide-vue-next';
import { getCommunityPageIconComponent } from '@/utils/communityPageIcons';
import { COMMUNITY_BANNER_ASPECT_CLASS, COMMUNITY_BANNER_CONTAINER_CLASS, COMMUNITY_BANNER_IMAGE_CLASS } from '@/utils/communityBanner';
import MemberAreaSplitLoginLayout from '@/components/member-area/MemberAreaSplitLoginLayout.vue';
import MemberAreaLoginForm from '@/components/member-area/MemberAreaLoginForm.vue';

const props = defineProps({
    mode: { type: String, default: 'area' },
    config: { type: Object, default: () => ({}) },
    productName: { type: String, default: '' },
    // Dados iguais aos da área real (Show.vue) — só exibe o que a área real exibe
    sections: { type: Array, default: () => [] },
    internal_products: { type: Array, default: () => [] },
    progress_percent: { type: Number, default: 0 },
    continue_watching: { type: Object, default: null },
    community_enabled: { type: Boolean, default: false },
    /** @see passa :community-pages no pai → Vue normaliza para communityPages */
    communityPages: { type: Array, default: () => [] },
    certificate_enabled: { type: Boolean, default: false },
    can_issue_certificate: { type: Boolean, default: false },
});

const theme = computed(() => props.config?.theme ?? {});
const hero = computed(() => props.config?.hero ?? {});
const heroDesktopBg = computed(() => hero.value?.image_url_desktop || hero.value?.image_url || null);
const heroMobileBg = computed(() => hero.value?.image_url_mobile || hero.value?.image_url_desktop || hero.value?.image_url || null);
const heroGradient = 'linear-gradient(135deg, var(--ma-primary) 0%, #27272a 100%)';
const heroOverlayOpacity = computed(() => {
    const raw = hero.value?.overlay_opacity ?? 50;
    return (raw <= 1 ? raw * 100 : raw) / 100;
});
const headerLogo = computed(() => props.config?.header?.logo_url ?? null);
const sidebar = computed(() => props.config?.sidebar ?? {});
const login = computed(() => props.config?.login ?? {});
const isLoginV2 = computed(() => (login.value.template || 'v1') === 'v2');
const loginPreviewProduct = computed(() => ({
    logo_light: login.value.logo || '',
    logo_dark: login.value.logo || '',
    title: login.value.title || 'Área de Membros',
    subtitle: login.value.subtitle || 'Entre com seu e-mail e senha',
    primary_color: login.value.primary_color || '#0ea5e9',
    background_image: login.value.background_image || '',
    background_overlay_opacity: login.value.background_overlay_opacity ?? 50,
    login_without_password: login.value.login_without_password ?? false,
    template: login.value.template || 'v1',
    name: props.productName || login.value.title || 'Área de Membros',
}));

const sidebarItems = computed(() => sidebar.value?.items ?? [
    { title: 'Início', icon: 'home', link: '/', open_external: false },
]);

const cssVars = computed(() => ({
    '--ma-primary': theme.value.primary || '#0ea5e9',
    '--ma-bg': theme.value.background || '#18181b',
    '--ma-sidebar-bg': theme.value.sidebar_bg || '#27272a',
    '--ma-text': theme.value.text || '#f8fafc',
}));

const certificate = computed(() => props.config?.certificate ?? {});
const certificateTitle = computed(() => certificate.value.title || props.productName || 'Nome do curso');
const certificatePlatformName = computed(() => certificate.value.platform_name || 'Nome da Plataforma');
const certPrimary = computed(() => certificate.value.primary_color || theme.value.primary || 'var(--ma-primary)');
const certBgUrl = computed(() => certificate.value.background_image_url || null);
const certTextColor = computed(() => certificate.value.text_color || '#262626');
const certTitleColor = computed(() => certificate.value.title_color || null);
const certSignatureFont = computed(() => certificate.value.signature_font_family || 'Dancing Script');
const certSignatureFontUrl = computed(() => {
    const name = certSignatureFont.value;
    if (!name) return null;
    return `https://fonts.googleapis.com/css2?family=${encodeURIComponent(name).replace(/%20/g, '+')}&display=swap`;
});
const certOverlayEnabled = computed(() => certBgUrl.value && certificate.value.background_overlay_enabled);
const certOverlayColor = computed(() => certificate.value.background_overlay_color || '#000000');
const certOverlayOpacity = computed(() => {
    const raw = certificate.value.background_overlay_opacity ?? 50;
    return (raw <= 1 ? raw * 100 : raw) / 100;
});
</script>

<template>
    <div
        class="h-full min-h-[400px] overflow-auto rounded-xl border border-zinc-300 dark:border-zinc-700"
        :style="{ ...cssVars, backgroundColor: 'var(--ma-bg)', color: 'var(--ma-text)' }"
    >
        <!-- Área: estrutura idêntica à página real; scroll único (hero + conteúdo rolam juntos) -->
        <template v-if="mode === 'area'">
            <div class="flex h-full min-h-[500px] w-full flex-col overflow-auto">
                <!-- Hero + header em overlay -->
                <div class="relative shrink-0">
                    <section
                        class="relative -mx-6 flex min-h-[55vh] items-end justify-start overflow-hidden bg-cover bg-center px-8 pb-10 pt-24 md:min-h-[65vh] md:px-10 md:pb-14 md:pt-28"
                        :style="{ backgroundImage: heroGradient }"
                    >
                        <div
                            v-if="heroDesktopBg"
                            class="absolute inset-0 hidden bg-cover bg-center md:block"
                            :style="{ backgroundImage: `url(${heroDesktopBg})` }"
                        />
                        <div
                            v-if="heroMobileBg"
                            class="absolute inset-0 bg-cover bg-center md:hidden"
                            :style="{ backgroundImage: `url(${heroMobileBg})` }"
                        />
                        <div
                            class="pointer-events-none absolute inset-0 bg-black"
                            :style="{ opacity: heroOverlayOpacity }"
                        />
                        <div
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2"
                            :style="{ background: `linear-gradient(to top, var(--ma-bg) 0%, transparent 100%)` }"
                        />
                        <div class="relative z-10 max-w-2xl">
                            <h1 class="text-4xl font-bold text-white drop-shadow-lg md:text-5xl">
                                {{ hero.title || productName }}
                            </h1>
                            <p v-if="hero.subtitle" class="mt-3 text-xl text-white/90 drop-shadow md:text-2xl">
                                {{ hero.subtitle }}
                            </p>
                            <p class="mt-5 text-sm text-white/80 md:text-base">
                                Seu progresso: {{ progress_percent }}%
                            </p>
                        </div>
                    </section>
                    <header
                        class="pointer-events-none absolute left-0 top-0 right-0 z-20 flex h-14 items-center justify-start gap-6 px-4 md:px-6"
                        :style="{ color: 'var(--ma-text)' }"
                    >
                        <span class="flex shrink-0 items-center gap-4">
                            <img
                                v-if="headerLogo"
                                :src="headerLogo"
                                :alt="productName || 'Logo'"
                                class="h-8 w-auto max-w-[180px] object-contain object-left"
                            />
                            <span v-else class="text-lg font-semibold text-white drop-shadow-md">
                                {{ productName || 'Área de Membros' }}
                            </span>
                        </span>
                        <nav class="flex items-center gap-1">
                            <span
                                v-for="(item, i) in sidebarItems"
                                :key="i"
                                class="rounded-lg px-3 py-2 text-sm font-medium text-white/90"
                            >
                                {{ item.title }}
                            </span>
                            <span class="rounded-lg px-3 py-2 text-sm text-white/80">Sair</span>
                        </nav>
                    </header>
                </div>
                <!-- Conteúdo (rola junto com a hero) -->
                <main class="shrink-0 px-6 pb-6 pt-0">
                    <div class="space-y-8">
                            <!-- Continuar assistindo — só existe na área real quando há continue_watching -->
                            <section v-if="continue_watching" class="space-y-4">
                                <h2 class="text-xl font-semibold">Continuar assistindo</h2>
                                <div class="flex max-w-md items-center gap-4 rounded-xl border border-zinc-700 bg-zinc-800/50 p-4 transition hover:bg-zinc-800">
                                    <div class="flex h-14 w-14 shrink-0 items-center justify-center rounded-lg bg-[var(--ma-primary)]/20 text-[var(--ma-primary)]">
                                        <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p class="truncate font-medium">{{ continue_watching.title }}</p>
                                        <p v-if="continue_watching.module_title" class="text-sm text-zinc-400">{{ continue_watching.module_title }}</p>
                                    </div>
                                </div>
                            </section>

                            <!-- Módulos por seção — igual Show.vue, só aparece se houver sections -->
                            <section v-for="section in sections" :key="section.id" class="space-y-4">
                                <h2 class="text-xl font-semibold">{{ section.title }}</h2>
                                <div class="flex gap-4 overflow-x-auto pb-2">
                                    <div
                                        v-for="mod in section.modules"
                                        :key="mod.id"
                                        class="flex w-64 shrink-0 flex-col rounded-xl overflow-hidden bg-zinc-800/50 transition hover:bg-zinc-800"
                                    >
                                        <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative flex w-full items-center justify-center overflow-hidden bg-zinc-700']">
                                            <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="absolute inset-0 h-full w-full object-cover" />
                                            <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                            <div v-if="mod.show_title_on_cover !== false" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent px-3 pb-3 pt-8">
                                                <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- Loja interna — só existe na área real quando há internal_products -->
                            <section v-if="internal_products?.length" class="space-y-4">
                                <h2 class="text-xl font-semibold">Loja</h2>
                                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                    <div v-for="ip in internal_products" :key="ip.id" class="overflow-hidden rounded-xl border border-zinc-700 bg-zinc-800/50">
                                        <div class="flex aspect-video items-center justify-center bg-zinc-700">
                                            <img v-if="ip.image_url || ip.related_product?.image_url" :src="ip.image_url || ip.related_product?.image_url" :alt="ip.related_product?.name || ip.name" class="h-full w-full object-cover" />
                                            <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                                        </div>
                                        <div class="p-3">
                                            <p class="truncate font-medium">{{ ip.related_product?.name ?? ip.name ?? '#' + ip.related_product_id }}</p>
                                            <span class="mt-2 inline-block text-sm text-[var(--ma-primary)]">Acessar</span>
                                        </div>
                                    </div>
                                </div>
                            </section>

                            <!-- Comunidade e Certificado — mesmos v-if da área real -->
                            <section class="flex flex-wrap gap-4">
                                <div
                                    v-if="community_enabled"
                                    class="inline-flex items-center gap-2 rounded-xl border border-zinc-700 bg-zinc-800/50 px-4 py-3 transition hover:bg-zinc-800"
                                >
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" /></svg>
                                    Comunidade
                                </div>
                                <div
                                    v-if="certificate_enabled && can_issue_certificate"
                                    class="inline-flex items-center gap-2 rounded-xl border border-[var(--ma-primary)] bg-[var(--ma-primary)]/20 px-4 py-3 text-[var(--ma-primary)] transition hover:bg-[var(--ma-primary)]/30"
                                >
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                                    Emitir certificado
                                </div>
                            </section>
                    </div>
                </main>
            </div>
        </template>

        <!-- Header: preview da barra superior (sem fundo, à esquerda) -->
        <template v-else-if="mode === 'sidebar'">
            <div class="flex h-full min-h-[500px] w-full flex-col">
                <header
                    class="flex h-14 shrink-0 items-center justify-start gap-6 px-4 md:px-6"
                    :style="{ color: 'var(--ma-text)' }"
                >
                    <span class="flex shrink-0 items-center">
                        <img
                            v-if="headerLogo"
                            :src="headerLogo"
                            :alt="productName || 'Logo'"
                            class="h-8 w-auto max-w-[180px] object-contain object-left"
                        />
                        <span v-else class="text-lg font-semibold text-white drop-shadow-md">{{ productName || 'Área de Membros' }}</span>
                    </span>
                    <nav class="flex items-center gap-1">
                        <span v-for="(item, i) in sidebarItems" :key="i" class="rounded-lg px-3 py-2 text-sm font-medium text-white/90">{{ item.title }}</span>
                        <span class="rounded-lg px-3 py-2 text-sm text-white/80">Sair</span>
                    </nav>
                </header>
                <div class="flex flex-1 items-center justify-center p-6 text-sm text-zinc-500" :style="{ backgroundColor: 'var(--ma-bg)' }">
                    Conteúdo principal
                </div>
            </div>
        </template>

        <!-- Certificado — preview do certificado -->
        <template v-else-if="mode === 'certificate'">
            <link v-if="certSignatureFontUrl" rel="stylesheet" :href="certSignatureFontUrl" />
            <div class="flex min-h-[500px] flex-col items-center justify-start overflow-auto p-6">
                <p class="mb-4 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Preview do certificado</p>
                <div
                    class="relative mx-auto w-full max-w-2xl overflow-hidden rounded-2xl border border-zinc-200 p-8 shadow-md dark:border-zinc-500"
                    :style="{
                        fontFamily: certificate.font_family || 'sans-serif',
                        backgroundColor: certBgUrl ? 'transparent' : '#fff',
                        backgroundImage: certBgUrl ? `url(${certBgUrl})` : 'none',
                        backgroundSize: certBgUrl ? 'cover' : undefined,
                        backgroundPosition: certBgUrl ? 'center' : undefined,
                        '--cert-primary': certPrimary,
                        '--cert-text': certBgUrl ? (certificate.text_color || '#171717') : certTextColor,
                        '--cert-title': certBgUrl && certificate.title_color ? certificate.title_color : certPrimary,
                    }"
                >
                    <!-- Cantos em L decorativos -->
                    <div class="absolute left-0 top-0 h-16 w-16 border-l-4 border-t-4 rounded-tl-lg" style="border-color: var(--cert-primary)" aria-hidden="true" />
                    <div class="absolute right-0 top-0 h-16 w-16 border-r-4 border-t-4 rounded-tr-lg" style="border-color: var(--cert-primary)" aria-hidden="true" />
                    <div class="absolute bottom-0 left-0 h-16 w-16 border-b-4 border-l-4 rounded-bl-lg" style="border-color: var(--cert-primary)" aria-hidden="true" />
                    <div class="absolute bottom-0 right-0 h-16 w-16 border-b-4 border-r-4 rounded-br-lg" style="border-color: var(--cert-primary)" aria-hidden="true" />

                    <!-- Overlay na imagem de fundo -->
                    <div
                        v-if="certOverlayEnabled"
                        class="pointer-events-none absolute inset-0"
                        style="z-index: 0"
                        :style="{ backgroundColor: certOverlayColor, opacity: certOverlayOpacity }"
                        aria-hidden="true"
                    />

                    <!-- Marca d'água -->
                    <div
                        class="pointer-events-none absolute inset-0 flex items-center justify-center opacity-[0.06]"
                        style="z-index: 0;"
                    >
                        <span
                            class="text-6xl font-bold whitespace-nowrap"
                            style="color: var(--cert-primary); transform: rotate(-35deg);"
                        >
                            {{ certificatePlatformName }}
                        </span>
                    </div>

                    <div class="relative" style="z-index: 1;">
                        <!-- Cabeçalho: ícone + CERTIFICADO DE CONCLUSÃO -->
                        <div class="flex flex-col items-center text-center">
                            <div class="relative flex h-14 w-14 items-center justify-center rounded-full text-[var(--cert-primary)]">
                                <div class="absolute inset-0 rounded-full" style="background-color: var(--cert-primary); opacity: 0.15" aria-hidden="true" />
                                <svg class="relative z-10 h-8 w-8" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" />
                                </svg>
                            </div>
                            <p class="mt-3 text-xs font-semibold uppercase tracking-[0.2em]" style="color: var(--cert-text)">
                                Certificado de conclusão
                            </p>
                        </div>

                        <!-- Título do curso -->
                        <h2 class="mt-6 text-center text-2xl font-bold" style="color: var(--cert-title)">
                            {{ certificateTitle }}
                        </h2>

                        <!-- Bloco central -->
                        <div class="mt-8 text-center" style="color: var(--cert-text)">
                            <p>Certificamos que</p>
                            <p class="mt-2">
                                <span class="inline-block border-b-2 px-1 font-bold" style="border-color: var(--cert-primary); color: var(--cert-text)">Nome do Aluno</span>
                            </p>
                            <p class="mt-3">
                                completou com sucesso o curso em <strong>{{ certificatePlatformName }}</strong>
                            </p>
                            <p class="mt-2" style="color: var(--cert-text); opacity: 0.9">
                                em 24/02/2025 14:30
                            </p>
                        </div>

                        <!-- Rodapé em duas colunas -->
                        <div class="mt-12 grid grid-cols-2 gap-8 border-t pt-8" style="border-color: rgba(0,0,0,0.12); color: var(--cert-text)">
                            <div>
                                <p class="text-xs font-medium uppercase tracking-wide" style="opacity: 0.85">Assinatura do Instrutor</p>
                                <p class="mt-1 font-medium" :style="{ fontFamily: certSignatureFont, color: 'var(--cert-text)' }">{{ certificate.signature_text || 'Instrutor' }}</p>
                            </div>
                            <div class="text-right">
                                <p class="font-semibold">{{ certificatePlatformName }}</p>
                                <p class="text-sm" style="opacity: 0.85">Plataforma de Cursos</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>

        <!-- Login — preview idêntico à tela real (MemberAreaApp/Login.vue) -->
        <template v-else-if="mode === 'login'">
            <MemberAreaSplitLoginLayout
                v-if="isLoginV2"
                preview
                form-side="right"
                :logo-light="loginPreviewProduct.logo_light"
                :logo-dark="loginPreviewProduct.logo_dark"
                :hero-image="loginPreviewProduct.background_image"
                :primary="loginPreviewProduct.primary_color"
                :hero-title="loginPreviewProduct.title"
                :hero-subtitle="loginPreviewProduct.subtitle"
                :app-name="loginPreviewProduct.name"
                :form-heading="loginPreviewProduct.title"
                :form-subheading="loginPreviewProduct.subtitle"
                :hero-overlay-opacity="loginPreviewProduct.background_overlay_opacity"
            >
                <MemberAreaLoginForm slug="preview" :product="loginPreviewProduct" variant="v2" preview />
            </MemberAreaSplitLoginLayout>
            <div
                v-else
                class="relative flex min-h-full h-full w-full flex-col items-center justify-center bg-cover bg-center px-4 py-12"
                :style="{
                    '--ma-primary': login.primary_color || '#0ea5e9',
                    backgroundColor: login.background_color || '#18181b',
                    backgroundImage: login.background_image ? `url(${login.background_image})` : 'none',
                }"
            >
                <div
                    v-if="login.background_image"
                    class="pointer-events-none absolute inset-0 bg-black"
                    :style="{ opacity: ((login.background_overlay_opacity ?? 50) <= 1 ? (login.background_overlay_opacity ?? 50) * 100 : (login.background_overlay_opacity ?? 50)) / 100 }"
                    aria-hidden="true"
                />
                <div class="relative z-10 w-full max-w-md rounded-2xl border border-white/10 bg-zinc-900/90 p-8 shadow-2xl backdrop-blur-sm">
                    <div class="flex flex-col items-center text-center">
                        <img
                            v-if="login.logo"
                            :src="login.logo"
                            :alt="login.title || 'Logo'"
                            class="mb-6 h-12 w-auto max-w-[200px] object-contain object-center"
                        />
                        <h1 class="text-2xl font-bold text-white">{{ login.title || 'Área de Membros' }}</h1>
                        <p class="mt-1 text-zinc-400">{{ login.subtitle || 'Entre com seu e-mail e senha' }}</p>
                    </div>
                    <div class="mt-8 space-y-4">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-300">E-mail</label>
                            <div class="h-12 rounded-xl border border-zinc-600 bg-zinc-800/80" />
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-zinc-300">Senha</label>
                            <div class="relative h-12 rounded-xl border border-zinc-600 bg-zinc-800/80">
                                <span class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-400">
                                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                    </svg>
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="h-4 w-4 rounded border border-zinc-600 bg-zinc-800/80" />
                            <span class="text-sm text-zinc-400">Lembrar de mim</span>
                        </div>
                        <button
                            type="button"
                            disabled
                            class="flex h-12 w-full items-center justify-center rounded-xl font-semibold text-white"
                            :style="{ backgroundColor: login.primary_color || '#0ea5e9' }"
                        >
                            Entrar
                        </button>
                        <div class="mt-3 flex w-full items-center justify-center gap-2 rounded-xl border border-white/30 bg-white/10 py-3 text-sm font-medium text-white/90 backdrop-blur-sm">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                            Instalar App
                        </div>
                        <p class="mt-2 text-center text-xs text-zinc-500">(Visível apenas em mobile, se o app não estiver instalado)</p>
                    </div>
                </div>
            </div>
        </template>
        <template v-else-if="mode === 'comunidade'">
            <div class="flex min-h-[500px] w-full flex-col gap-4 overflow-auto p-4 lg:flex-row lg:items-stretch lg:justify-start lg:gap-5">
                <aside class="hidden rounded-2xl bg-zinc-800/50 ring-1 ring-zinc-700/50 lg:block">
                    <div class="border-b border-zinc-700/50 p-3">
                        <div class="flex items-center gap-2">
                            <div class="h-8 w-8 rounded-lg bg-zinc-700/80" />
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="h-2.5 w-20 rounded bg-zinc-700/80" />
                                <div class="h-2 w-24 rounded bg-zinc-700/50" />
                            </div>
                        </div>
                    </div>
                    <div class="space-y-1 p-2">
                        <div v-for="p in communityPages.slice(0, 4)" :key="p.id" class="flex items-center gap-2 rounded-lg px-2 py-1.5">
                            <MessageSquare class="h-3.5 w-3.5 shrink-0 text-[var(--ma-primary)]" />
                            <span class="truncate text-xs text-zinc-300">{{ p.title }}</span>
                        </div>
                        <p v-if="!communityPages.length" class="px-2 py-3 text-xs text-zinc-500">Nenhuma página.</p>
                    </div>
                </aside>
                <main class="w-full max-w-[520px] shrink-0 space-y-4 lg:w-[520px]">
                    <div>
                        <h1 class="text-xl font-bold text-white">Comunidade</h1>
                        <p class="mt-1 text-xs text-zinc-400">Feed central estreito</p>
                    </div>
                    <div class="space-y-3">
                        <div
                            v-for="p in communityPages.slice(0, 2)"
                            :key="'card-' + p.id"
                            class="overflow-hidden rounded-xl bg-zinc-800/50 ring-1 ring-zinc-700/50"
                        >
                            <div v-if="p.banner_url" :class="[COMMUNITY_BANNER_ASPECT_CLASS, 'relative w-full overflow-hidden bg-zinc-900/80']">
                                <img :src="p.banner_url" :alt="p.title" :class="COMMUNITY_BANNER_IMAGE_CLASS" />
                            </div>
                            <div class="flex items-center gap-3 p-3">
                                <MessageSquare class="h-4 w-4 text-[var(--ma-primary)]" />
                                <span class="text-sm font-medium text-zinc-200">{{ p.title }}</span>
                            </div>
                        </div>
                    </div>
                    <p v-if="!communityPages.length" class="rounded-xl bg-zinc-800/30 p-4 text-center text-xs text-zinc-500">Adicione páginas no painel à esquerda.</p>
                </main>
                <aside class="hidden w-56 shrink-0 space-y-3 lg:block">
                    <div class="rounded-xl bg-zinc-800/50 p-3 ring-1 ring-zinc-700/50">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Em destaque</p>
                        <div class="mt-2 space-y-2">
                            <div class="h-8 rounded-lg bg-zinc-700/40" />
                            <div class="h-8 rounded-lg bg-zinc-700/30" />
                        </div>
                    </div>
                    <div class="rounded-xl bg-zinc-800/50 p-3 ring-1 ring-zinc-700/50">
                        <p class="text-[10px] font-semibold uppercase tracking-wide text-zinc-500">Outras páginas</p>
                        <div class="mt-2 h-16 rounded-lg bg-zinc-700/30" />
                    </div>
                </aside>
            </div>
        </template>
    </div>
</template>
