<script setup>
import { computed } from 'vue';
import { retryImageOnError } from '@/lib/imageLoadRetry';

const props = defineProps({
    formSide: { type: String, default: 'right' },
    logoLight: { type: String, default: '' },
    logoDark: { type: String, default: '' },
    heroImage: { type: String, default: '' },
    primary: { type: String, default: '#0ea5e9' },
    heroTitle: { type: String, default: '' },
    heroSubtitle: { type: String, default: '' },
    appName: { type: String, default: 'Área de Membros' },
    formHeading: { type: String, default: '' },
    formSubheading: { type: String, default: '' },
    heroOverlayOpacity: { type: Number, default: 50 },
    preview: { type: Boolean, default: false },
});

const resolvedHeroImage = computed(
    () => props.heroImage || 'https://cdn.getfy.cloud/login.webp'
);
const resolvedLogoDark = computed(() => props.logoDark || props.logoLight);
const formFirst = computed(() => props.formSide === 'left');
const heroOverlayOpacity = computed(() => {
    const raw = props.heroOverlayOpacity ?? 50;
    return (raw <= 1 ? raw * 100 : raw) / 100;
});
</script>

<template>
    <div
        class="wl-root flex min-h-screen overflow-hidden bg-zinc-50 dark:bg-zinc-900"
        :class="preview ? 'relative h-full min-h-[480px]' : 'fixed inset-0 z-0'"
        :style="{ '--wl-primary': primary }"
    >
        <!-- Hero -->
        <div
            class="relative hidden min-h-0 overflow-hidden bg-zinc-200 dark:bg-zinc-900 lg:flex lg:flex-1"
            :class="formFirst ? 'lg:order-2' : 'lg:order-1'"
            aria-hidden="true"
        >
            <div
                class="absolute inset-0 opacity-90"
                :style="{
                    background: `linear-gradient(135deg, color-mix(in srgb, ${primary} 18%, transparent) 0%, transparent 45%, rgba(24, 24, 27, 0.15) 100%)`,
                }"
            />
            <img
                :src="resolvedHeroImage"
                alt=""
                class="h-full w-full object-cover"
                @error="retryImageOnError"
            />
            <div
                class="absolute inset-0 bg-zinc-900"
                :style="{ opacity: heroOverlayOpacity }"
            />
            <div class="absolute inset-0 bg-gradient-to-t from-zinc-900/50 via-zinc-900/10 to-transparent" />
            <div class="absolute bottom-10 left-10 right-10 max-w-md">
                <p class="text-sm font-medium uppercase tracking-[0.2em] text-white/70">
                    {{ appName }}
                </p>
                <p class="mt-2 text-2xl font-semibold leading-snug text-white">
                    {{ heroTitle || appName }}
                </p>
                <p v-if="heroSubtitle" class="mt-2 text-base leading-relaxed text-white/80">
                    {{ heroSubtitle }}
                </p>
            </div>
        </div>

        <!-- Painel de login -->
        <div
            class="relative flex min-h-0 w-full flex-col overflow-hidden lg:w-[min(42%,480px)] lg:shrink-0"
            :class="[
                formFirst
                    ? 'lg:order-1 lg:border-r lg:border-zinc-200/80 dark:lg:border-zinc-800'
                    : 'lg:order-2 lg:border-l lg:border-zinc-200/80 dark:lg:border-zinc-800',
            ]"
        >
            <div
                class="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full opacity-[0.14] blur-3xl dark:opacity-[0.2]"
                :style="{ background: `color-mix(in srgb, ${primary} 70%, white)` }"
                aria-hidden="true"
            />
            <div
                class="pointer-events-none absolute -bottom-16 right-0 h-56 w-56 rounded-full opacity-[0.08] blur-2xl dark:opacity-[0.12]"
                :style="{ background: `color-mix(in srgb, ${primary} 50%, transparent)` }"
                aria-hidden="true"
            />

            <header class="login-fade relative z-10 px-6 pb-2 pt-6 sm:px-8">
                <template v-if="logoLight">
                    <img
                        :src="logoLight"
                        :alt="appName"
                        class="h-11 max-w-[200px] object-contain object-left dark:hidden"
                        @error="retryImageOnError"
                    />
                    <img
                        :src="resolvedLogoDark"
                        :alt="appName"
                        class="hidden h-11 max-w-[200px] object-contain object-left dark:block"
                        @error="retryImageOnError"
                    />
                </template>
                <span v-else class="text-lg font-semibold text-zinc-900 dark:text-white">{{ appName }}</span>
            </header>

            <div class="relative z-10 flex min-h-0 flex-1 flex-col justify-center overflow-y-auto overscroll-contain px-6 py-6 sm:px-10 sm:py-8">
                <div class="login-fade login-fade-delay-1 mx-auto w-full max-w-[400px]">
                    <h1 v-if="formHeading" class="text-3xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-[2rem]">
                        {{ formHeading }}
                    </h1>
                    <p v-if="formSubheading" class="mt-2 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
                        {{ formSubheading }}
                    </p>

                    <div
                        class="login-fade-delay-2 mt-8 rounded-2xl border border-zinc-200/80 bg-white p-6 shadow-sm dark:border-zinc-800/80 dark:bg-zinc-800/40 dark:shadow-none sm:p-7"
                    >
                        <slot />
                    </div>

                    <p class="login-fade-delay-3 mt-6 pb-2 text-center text-xs text-zinc-400 dark:text-zinc-500">
                        © {{ new Date().getFullYear() }} {{ appName }}. Todos os direitos reservados.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
:global(html:has(.wl-root:not(.relative))),
:global(body:has(.wl-root:not(.relative))) {
    overflow: hidden;
    height: 100%;
}

.login-fade {
    animation: login-fade-up 0.55s cubic-bezier(0.22, 1, 0.36, 1) both;
    backface-visibility: hidden;
}

.login-fade-delay-1 {
    animation-delay: 0.06s;
}

.login-fade-delay-2 {
    animation-delay: 0.12s;
}

.login-fade-delay-3 {
    animation-delay: 0.2s;
}

@keyframes login-fade-up {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

:deep(.wl-input:hover) {
    border-color: color-mix(in srgb, var(--wl-primary) 40%, #e4e4e7);
}

:deep(.wl-input:focus) {
    border-color: var(--wl-primary);
    outline: none;
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--wl-primary) 22%, transparent);
}

:deep(.wl-focus-ring:focus-visible) {
    outline: none;
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--wl-primary) 35%, transparent);
}

:deep(.wl-remember-active) {
    background-color: var(--wl-primary) !important;
}

:deep(.wl-submit) {
    background-color: var(--wl-primary) !important;
    color: #18181b !important;
}

:deep(.wl-link) {
    color: var(--wl-primary);
}

:deep(.wl-link:focus-visible) {
    outline: 2px solid color-mix(in srgb, var(--wl-primary) 40%, transparent);
    outline-offset: 2px;
    border-radius: 4px;
}
</style>
