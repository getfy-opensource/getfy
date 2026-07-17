<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PwaInstallPrompt from '@/components/member-area/PwaInstallPrompt.vue';
import MemberAreaSplitLoginLayout from '@/components/member-area/MemberAreaSplitLoginLayout.vue';
import MemberAreaLoginForm from '@/components/member-area/MemberAreaLoginForm.vue';

const props = defineProps({
    slug: { type: String, required: true },
    product: { type: Object, required: true },
});

const isV2 = computed(() => (props.product.template || 'v1') === 'v2');

const manifestUrl = computed(() => {
    if (typeof window === 'undefined') return null;
    return `${window.location.origin}/m/${props.slug}/manifest.json`;
});

const backgroundStyle = () => {
    if (props.product.background_image) {
        return { backgroundImage: `url(${props.product.background_image})` };
    }
    return { backgroundColor: props.product.background_color || '#18181b' };
};

const formHeading = computed(() => props.product.title || props.product.name || 'Área de Membros');
const formSubheading = computed(
    () => props.product.subtitle
        || (props.product.login_without_password ? 'Entre com seu e-mail' : 'Entre com seu e-mail e senha')
);
</script>

<template>
    <Head>
        <title>{{ product.title || product.name || 'Área de Membros' }}</title>
        <link v-if="manifestUrl" rel="manifest" :href="manifestUrl" />
        <meta name="theme-color" :content="product.primary_color || '#0ea5e9'" />
        <meta name="mobile-web-app-capable" content="yes" />
    </Head>

    <MemberAreaSplitLoginLayout
        v-if="isV2"
        form-side="right"
        :logo-light="product.logo_light"
        :logo-dark="product.logo_dark"
        :hero-image="product.background_image"
        :primary="product.primary_color || '#0ea5e9'"
        :hero-title="formHeading"
        :hero-subtitle="formSubheading"
        :app-name="product.name || formHeading"
        :form-heading="formHeading"
        :form-subheading="formSubheading"
        :hero-overlay-opacity="product.background_overlay_opacity ?? 50"
    >
        <MemberAreaLoginForm :slug="slug" :product="product" variant="v2" />
    </MemberAreaSplitLoginLayout>
    <PwaInstallPrompt v-if="isV2" :app-name="product?.name || product?.title || 'App'" :slug="slug" />

    <div
        v-else
        class="relative flex min-h-screen flex-col items-center justify-center bg-cover bg-center px-4 py-12 transition-colors"
        :style="{
            '--ma-primary': product.primary_color || '#0ea5e9',
            ...backgroundStyle(),
        }"
    >
        <div
            v-if="product.background_image"
            class="absolute inset-0 bg-black"
            :style="{ opacity: ((product.background_overlay_opacity ?? 50) <= 1 ? (product.background_overlay_opacity ?? 50) * 100 : (product.background_overlay_opacity ?? 50)) / 100 }"
            aria-hidden="true"
        />
        <div
            class="relative z-10 w-full max-w-md rounded-2xl border border-white/10 bg-zinc-900/90 p-8 shadow-2xl backdrop-blur-sm"
        >
            <div class="flex flex-col items-center text-center">
                <img
                    v-if="product.logo_light"
                    :src="product.logo_light"
                    :alt="product.title || product.name"
                    class="mb-6 h-12 w-auto max-w-[200px] object-contain object-center"
                />
                <h1 class="text-2xl font-bold tracking-tight text-white">
                    {{ formHeading }}
                </h1>
                <p class="mt-1.5 text-sm text-zinc-400">
                    {{ formSubheading }}
                </p>
            </div>
            <div class="mt-8">
                <MemberAreaLoginForm :slug="slug" :product="product" variant="v1" />
            </div>
        </div>
        <PwaInstallPrompt :app-name="product?.name || product?.title || 'App'" :slug="slug" />
    </div>
</template>
