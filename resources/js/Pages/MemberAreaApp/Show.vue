<script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight } from 'lucide-vue-next';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: MemberAreaAppLayout });

const carouselRefs = ref({});
/** Só true quando o carrossel tem conteúdo para rolar (scrollWidth > clientWidth). Só atualizamos quando muda para evitar loop de re-render. */
const carouselHasOverflow = ref({});

function checkCarouselOverflow(sectionId) {
    const el = carouselRefs.value[sectionId];
    if (!el || typeof el.scrollWidth !== 'number') return;
    const hasOverflow = el.scrollWidth > el.clientWidth;
    if (carouselHasOverflow.value[sectionId] === hasOverflow) return;
    carouselHasOverflow.value = { ...carouselHasOverflow.value, [sectionId]: hasOverflow };
}

function setCarouselRef(sectionId, el) {
    if (el) {
        carouselRefs.value[sectionId] = el;
        setTimeout(() => checkCarouselOverflow(sectionId), 0);
    } else {
        carouselRefs.value[sectionId] = null;
        if (carouselHasOverflow.value[sectionId] !== false) {
            carouselHasOverflow.value = { ...carouselHasOverflow.value, [sectionId]: false };
        }
    }
}

function scrollCarousel(sectionId, direction) {
    const el = carouselRefs.value[sectionId];
    if (!el) return;
    const step = 272; // w-64 + gap-4
    el.scrollBy({ left: step * direction, behavior: 'smooth' });
}

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    sections: { type: Array, default: () => [] },
    progress_percent: { type: Number, default: 0 },
    continue_watching: { type: Array, default: () => [] },
    internal_products: { type: Array, default: () => [] },
    community_enabled: { type: Boolean, default: false },
    certificate_enabled: { type: Boolean, default: false },
    can_issue_certificate: { type: Boolean, default: false },
    base_url: { type: String, default: '' },
    slug: { type: String, required: true },
});

const hero = props.config?.hero ?? {};
const heroDesktopBg = hero.image_url_desktop || hero.image_url || null;
const heroMobileBg = hero.image_url_mobile || hero.image_url_desktop || hero.image_url || null;
const heroGradient = 'linear-gradient(135deg, var(--ma-primary) 0%, #27272a 100%)';

</script>

<template>
    <div class="flex gap-6">
        <div class="min-w-0 flex-1 space-y-8">
        <!-- Hero (estilo Netflix: desktop e mobile com banners separados) -->
        <section
            class="relative -mx-6 -mt-14 flex min-h-[55vh] items-end justify-start overflow-hidden bg-cover bg-center px-8 pb-10 pt-24 md:min-h-[65vh] md:px-10 md:pb-14 md:pt-28"
            :style="{ backgroundImage: heroGradient }"
        >
            <!-- Banner desktop (visível em md+) -->
            <div
                v-if="heroDesktopBg"
                class="absolute inset-0 hidden bg-cover bg-center md:block"
                :style="{ backgroundImage: `url(${heroDesktopBg})` }"
            />
            <!-- Banner mobile (visível em telas pequenas) -->
            <div
                v-if="heroMobileBg"
                class="absolute inset-0 bg-cover bg-center md:hidden"
                :style="{ backgroundImage: `url(${heroMobileBg})` }"
            />
            <div v-if="hero.overlay" class="absolute inset-0 bg-black/50" />
            <!-- Overlay gradiente embaixo: esfumaça na cor do fundo -->
            <div
                class="pointer-events-none absolute inset-x-0 bottom-0 h-1/2"
                :style="{ background: `linear-gradient(to top, var(--ma-bg) 0%, transparent 100%)` }"
            />
            <div class="relative z-10 max-w-2xl">
                <h1 class="text-4xl font-bold text-white drop-shadow-lg md:text-5xl">
                    {{ hero.title || product.name }}
                </h1>
                <p v-if="hero.subtitle" class="mt-3 text-xl text-white/90 drop-shadow md:text-2xl">
                    {{ hero.subtitle }}
                </p>
            </div>
        </section>

        <!-- Continuar assistindo (carrossel: um item por seção) -->
        <section v-if="continue_watching?.length" class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-xl font-semibold">Continuar assistindo</h2>
                <div v-if="carouselHasOverflow['continue']" class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-700 hover:text-white"
                        aria-label="Rolar para a esquerda"
                        @click="scrollCarousel('continue', -1)"
                    >
                        <ChevronLeft class="h-5 w-5" />
                    </button>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-700 hover:text-white"
                        aria-label="Rolar para a direita"
                        @click="scrollCarousel('continue', 1)"
                    >
                        <ChevronRight class="h-5 w-5" />
                    </button>
                </div>
            </div>
            <div
                :ref="(el) => setCarouselRef('continue', el)"
                class="no-scrollbar flex gap-4 overflow-x-auto"
            >
                <Link
                    v-for="item in continue_watching"
                    :key="item.lesson_id"
                    :href="item.module_id ? `/m/${slug}/modulo/${item.module_id}?aula=${item.lesson_id}` : `/m/${slug}/aula/${item.lesson_id}`"
                    class="flex w-64 shrink-0 items-center gap-4 rounded-xl border border-zinc-700 bg-zinc-800/50 p-4 transition hover:bg-zinc-800"
                >
                    <div class="relative h-14 w-24 shrink-0 overflow-hidden rounded-lg bg-zinc-700">
                        <img
                            v-if="item.module_thumbnail"
                            :src="item.module_thumbnail"
                            :alt="item.module_title || item.title"
                            class="h-full w-full object-cover"
                        />
                        <div v-else class="flex h-full w-full items-center justify-center bg-[var(--ma-primary)]/20 text-[var(--ma-primary)]">
                            <svg class="h-6 w-6" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                    </div>
                    <div class="min-w-0">
                        <p class="font-medium truncate">{{ item.title }}</p>
                        <p v-if="item.module_title" class="text-sm text-zinc-400 truncate">{{ item.module_title }}</p>
                    </div>
                </Link>
            </div>
        </section>

        <!-- Módulos por seção (conteúdo conforme tipo: cursos, outros produtos, links externos) -->
        <section v-for="section in sections" :key="section.id" class="space-y-4">
            <div class="flex items-center justify-between gap-2">
                <h2 class="text-xl font-semibold">{{ section.title }}</h2>
                <div v-if="carouselHasOverflow[section.id]" class="flex shrink-0 items-center gap-1">
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-700 hover:text-white"
                        aria-label="Rolar para a esquerda"
                        @click="scrollCarousel(section.id, -1)"
                    >
                        <ChevronLeft class="h-5 w-5" />
                    </button>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-400 transition hover:bg-zinc-700 hover:text-white"
                        aria-label="Rolar para a direita"
                        @click="scrollCarousel(section.id, 1)"
                    >
                        <ChevronRight class="h-5 w-5" />
                    </button>
                </div>
            </div>
            <div
                :ref="(el) => setCarouselRef(section.id, el)"
                class="no-scrollbar flex gap-4 overflow-x-auto"
            >
                <!-- Cursos/Aulas: link para módulo (lista de aulas) -->
                <template v-if="(section.section_type ?? 'courses') === 'courses'">
                    <template v-for="mod in section.modules" :key="mod.id">
                        <Link
                            v-if="!mod.is_locked"
                            :href="`/m/${slug}/modulo/${mod.id}`"
                            class="flex w-64 shrink-0 flex-col rounded-xl overflow-hidden bg-zinc-800/50 text-left transition hover:bg-zinc-800"
                        >
                            <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-700 flex items-center justify-center overflow-hidden']">
                                <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="absolute inset-0 h-full w-full object-cover" />
                                <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                <div v-if="mod.show_title_on_cover !== false" class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent px-3 pb-3 pt-8">
                                    <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                </div>
                            </div>
                        </Link>
                        <div
                            v-else
                            class="flex w-64 shrink-0 cursor-not-allowed flex-col rounded-xl overflow-hidden bg-zinc-800/30 text-left opacity-70"
                        >
                            <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-700 flex items-center justify-center overflow-hidden']">
                                <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="absolute inset-0 h-full w-full object-cover" />
                                <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                <div class="absolute inset-0 bg-black/50" />
                                <div class="absolute inset-x-0 bottom-0 px-3 pb-3 pt-8">
                                    <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                    <p v-if="mod.lock_message" class="mt-1 text-xs text-white/80">{{ mod.lock_message }}</p>
                                </div>
                            </div>
                        </div>
                    </template>
                </template>
                <!-- Outros produtos: link para área do produto (se tem acesso) ou checkout (se pago) -->
                <template v-else-if="(section.section_type ?? 'courses') === 'products'">
                    <component
                        v-for="mod in section.modules"
                        :key="mod.id"
                        :is="(!mod.has_access && mod.access_type === 'paid') ? 'a' : Link"
                        :href="(!mod.has_access && mod.access_type === 'paid') ? (mod.related_product?.checkout_url || `/c/${mod.related_product?.checkout_slug}`) : `/m/${mod.related_product?.member_area_slug ?? mod.related_product?.checkout_slug}`"
                        :target="(!mod.has_access && mod.access_type === 'paid') ? '_blank' : undefined"
                        :rel="(!mod.has_access && mod.access_type === 'paid') ? 'noopener' : undefined"
                        class="flex w-64 shrink-0 flex-col rounded-xl overflow-hidden bg-zinc-800/50 text-left transition hover:bg-zinc-800"
                    >
                        <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-700 flex items-center justify-center overflow-hidden']">
                            <img v-if="mod.related_product?.image_url || mod.thumbnail" :src="mod.related_product?.image_url || mod.thumbnail" :alt="mod.title" class="absolute inset-0 h-full w-full object-cover" />
                            <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14" /></svg>
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent px-3 pb-3 pt-8">
                                <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                <p v-if="mod.related_product?.name" class="truncate text-sm text-white/80">{{ mod.related_product.name }}</p>
                                <span v-if="!mod.has_access && mod.access_type === 'paid'" class="mt-1 inline-block text-xs font-medium text-amber-300">Comprar para acessar</span>
                                <span v-else-if="mod.has_access" class="mt-1 inline-block text-xs font-medium text-emerald-300">Acessar</span>
                                <span v-else class="mt-1 inline-block text-xs font-medium text-white/80">Liberado</span>
                            </div>
                        </div>
                    </component>
                </template>
                <!-- Links externos: abrir URL em nova aba -->
                <template v-else>
                    <a
                        v-for="mod in section.modules"
                        :key="mod.id"
                        :href="mod.external_url"
                        target="_blank"
                        rel="noopener"
                        class="flex w-64 shrink-0 flex-col rounded-xl overflow-hidden bg-zinc-800/50 text-left transition hover:bg-zinc-800"
                    >
                        <div :class="[(section.cover_mode === 'horizontal' ? 'aspect-video' : 'aspect-[2/3]'), 'relative w-full bg-zinc-700 flex items-center justify-center overflow-hidden']">
                            <img v-if="mod.thumbnail" :src="mod.thumbnail" :alt="mod.title" class="absolute inset-0 h-full w-full object-cover" />
                            <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" /></svg>
                            <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 to-transparent px-3 pb-3 pt-8">
                                <p class="truncate text-base font-medium text-white">{{ mod.title }}</p>
                                <span class="mt-1 inline-block text-xs text-white/80">Abrir link externo</span>
                            </div>
                        </div>
                    </a>
                </template>
            </div>
        </section>

        <!-- Loja interna -->
        <section v-if="internal_products?.length" class="space-y-4">
            <h2 class="text-xl font-semibold">Loja</h2>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="ip in internal_products" :key="ip.id" class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">
                    <div class="aspect-video bg-zinc-700 flex items-center justify-center">
                        <img v-if="ip.image_url" :src="ip.image_url" :alt="ip.name" class="h-full w-full object-cover" />
                        <svg v-else class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6 8h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg>
                    </div>
                    <div class="p-3">
                        <p class="font-medium truncate">{{ ip.name }}</p>
                        <Link
                            v-if="ip.has_access"
                            :href="`/m/${slug}/loja`"
                            class="mt-2 inline-block text-sm text-[var(--ma-primary)] hover:underline"
                        >
                            Acessar
                        </Link>
                        <a
                            v-else
                            :href="`/c/${ip.checkout_slug}`"
                            target="_blank"
                            rel="noopener"
                            class="mt-2 inline-block"
                        >
                            <Button size="sm">Comprar</Button>
                        </a>
                    </div>
                </div>
            </div>
        </section>

        <!-- Certificado -->
        <section v-if="certificate_enabled && can_issue_certificate" class="flex flex-wrap gap-4">
            <Link
                :href="`/m/${slug}/certificado`"
                class="inline-flex items-center gap-2 rounded-xl border border-[var(--ma-primary)] bg-[var(--ma-primary)]/20 px-4 py-3 text-[var(--ma-primary)] transition hover:bg-[var(--ma-primary)]/30"
            >
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z" /></svg>
                Emitir certificado
            </Link>
        </section>
        </div>
    </div>
</template>
