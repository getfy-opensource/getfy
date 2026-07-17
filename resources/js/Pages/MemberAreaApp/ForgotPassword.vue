<script setup>
import { computed } from 'vue';
import { useForm, Head, usePage } from '@inertiajs/vue3';
import { Mail } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import MemberAreaSplitLoginLayout from '@/components/member-area/MemberAreaSplitLoginLayout.vue';

const props = defineProps({
    slug: { type: String, required: true },
    product: { type: Object, required: true },
});

const page = usePage();
const status = computed(() => page.props.flash?.status ?? null);
const isV2 = computed(() => (props.product.template || 'v1') === 'v2');

const form = useForm({ email: '' });

function submit() {
    form.post(`/m/${props.slug}/esqueci-senha`, {
        preserveScroll: true,
        onFinish: () => form.reset('email'),
    });
}

const backgroundStyle = () => {
    if (props.product.background_image) {
        return { backgroundImage: `url(${props.product.background_image})` };
    }
    return { backgroundColor: props.product.background_color || '#18181b' };
};

const formHeading = computed(() => props.product.title || props.product.name || 'Área de Membros');
</script>

<template>
    <Head>
        <title>Recuperar senha – {{ formHeading }}</title>
        <meta name="theme-color" :content="product.primary_color || '#0ea5e9'" />
    </Head>

    <MemberAreaSplitLoginLayout
        v-if="isV2"
        form-side="right"
        :logo-light="product.logo_light"
        :logo-dark="product.logo_dark"
        :hero-image="product.background_image"
        :primary="product.primary_color || '#0ea5e9'"
        :hero-title="formHeading"
        hero-subtitle="Recuperação de acesso"
        :app-name="product.name || formHeading"
        form-heading="Recuperar senha"
        form-subheading="Informe seu e-mail para receber o link de redefinição de senha."
        :hero-overlay-opacity="product.background_overlay_opacity ?? 50"
    >
        <div v-if="status" class="mb-4 rounded-2xl border border-emerald-200/80 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800/60 dark:bg-emerald-950/40 dark:text-emerald-200">
            {{ status }}
        </div>
        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <label for="fp-email-v2" class="mb-2 block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    E-mail
                </label>
                <div class="relative">
                    <Mail
                        class="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400 dark:text-zinc-500"
                        aria-hidden="true"
                    />
                    <input
                        id="fp-email-v2"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="wl-input block w-full rounded-xl border border-zinc-200 bg-zinc-50 py-3 pl-11 pr-4 text-sm text-zinc-900 placeholder-zinc-400 transition dark:border-zinc-600 dark:bg-zinc-900/60 dark:text-white dark:placeholder-zinc-500"
                        placeholder="seu@email.com"
                    />
                </div>
                <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
            </div>
            <Button
                type="submit"
                class="wl-submit !h-12 w-full !rounded-xl !text-base !font-semibold hover:!opacity-90"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Enviando…' : 'Enviar link' }}
            </Button>
        </form>
        <p class="mt-4 text-center">
            <a :href="`/m/${slug}/login`" class="wl-link text-sm font-medium transition hover:underline">
                Voltar ao login
            </a>
        </p>
    </MemberAreaSplitLoginLayout>

    <div
        v-else
        class="relative flex min-h-screen flex-col items-center justify-center bg-cover bg-center px-4 py-12 transition-colors"
        :style="{
            '--ma-primary': product.primary_color || '#0ea5e9',
            ...backgroundStyle(),
        }"
    >
        <div v-if="product.background_image" class="absolute inset-0 bg-black/50" aria-hidden="true" />
        <div
            class="relative z-10 w-full max-w-md rounded-2xl border border-white/10 bg-zinc-900/90 p-8 shadow-2xl backdrop-blur-sm"
        >
            <div class="flex flex-col items-center text-center">
                <img
                    v-if="product.logo_light"
                    :src="product.logo_light"
                    :alt="formHeading"
                    class="mb-6 h-12 w-auto max-w-[200px] object-contain object-center"
                />
                <h1 class="text-2xl font-bold tracking-tight text-white">
                    {{ formHeading }}
                </h1>
                <p class="mt-1.5 text-sm text-zinc-400">
                    Informe seu e-mail para receber o link de redefinição de senha.
                </p>
            </div>

            <div v-if="status" class="mt-6 rounded-xl bg-emerald-500/20 px-4 py-3 text-sm text-emerald-300">
                {{ status }}
            </div>

            <form class="mt-6 space-y-5" @submit.prevent="submit">
                <div>
                    <label for="fp-email" class="mb-1.5 block text-sm font-medium text-zinc-300">E-mail</label>
                    <input
                        id="fp-email"
                        v-model="form.email"
                        type="email"
                        autocomplete="email"
                        required
                        class="w-full rounded-xl border border-zinc-600 bg-zinc-800/80 px-4 py-3 text-white placeholder-zinc-500 transition focus:border-[var(--ma-primary)] focus:outline-none focus:ring-2 focus:ring-[var(--ma-primary)]/30"
                        placeholder="seu@email.com"
                    />
                    <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-400">{{ form.errors.email }}</p>
                </div>
                <button
                    type="submit"
                    class="w-full rounded-xl px-4 py-3.5 font-semibold text-white shadow-lg transition hover:opacity-95 disabled:opacity-50"
                    :style="{ backgroundColor: product.primary_color || '#0ea5e9' }"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Enviando…' : 'Enviar link' }}
                </button>
            </form>

            <p class="mt-6 text-center">
                <a
                    :href="`/m/${slug}/login`"
                    class="text-sm font-medium transition hover:underline"
                    :style="{ color: product.primary_color || '#0ea5e9' }"
                >
                    Voltar ao login
                </a>
            </p>
        </div>
    </div>
</template>
