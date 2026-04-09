<script setup>
import { Star, BadgeCheck } from 'lucide-vue-next';

const props = defineProps({
    reviews: { type: Array, default: () => [] },
    primaryColor: { type: String, default: '#7427F1' },
});
</script>

<template>
    <div v-if="reviews?.length" class="space-y-4" data-checkout="reviews">
        <h3 class="text-base font-bold tracking-tight text-gray-900">Avaliações</h3>
        <div class="space-y-4">
            <article
                v-for="(r, i) in reviews"
                :key="i"
                class="overflow-hidden rounded-2xl border border-white/20 bg-white/95 p-4 shadow-sm"
            >
                <div class="flex items-start gap-3">
                    <img
                        v-if="r.photo"
                        :src="r.photo"
                        :alt="r.author"
                        class="h-12 w-12 shrink-0 rounded-full object-cover"
                        @error="(e) => e?.target && (e.target.style.display = 'none')"
                    />
                    <div v-else class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-gray-200 text-lg font-semibold text-gray-500">
                        {{ (r.author || '?').charAt(0).toUpperCase() }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-semibold text-gray-900">{{ r.author || 'Cliente' }}</span>
                            <span
                                v-if="r.verified_badge"
                                class="inline-flex items-center gap-0.5 text-xs text-emerald-600"
                            >
                                <BadgeCheck class="h-3.5 w-3.5" />
                                Verificado
                            </span>
                        </div>
                        <div class="mt-1 flex gap-0.5" aria-label="Nota: {{ r.stars }} de 5">
                            <Star
                                v-for="s in 5"
                                :key="s"
                                class="h-4 w-4"
                                :class="s <= (r.stars || 0) ? 'fill-amber-400 text-amber-400' : 'text-gray-200'"
                            />
                        </div>
                        <p v-if="r.description" class="mt-2 text-sm text-gray-600">
                            {{ r.description }}
                        </p>
                        <img
                            v-if="r.testimonial_image"
                            :src="r.testimonial_image"
                            alt="Depoimento"
                            class="mt-3 max-w-full rounded-lg border border-gray-100 object-cover"
                            @error="(e) => e?.target && (e.target.style.display = 'none')"
                        />
                    </div>
                </div>
            </article>
        </div>
    </div>
</template>
