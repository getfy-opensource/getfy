<script setup>
import { Link } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    sections: { type: Array, default: () => [] },
    slug: { type: String, required: true },
});

function formatUnlockDate(isoDate) {
    if (!isoDate) return '';
    const d = new Date(isoDate);
    const now = new Date();
    const diffMs = d - now;
    const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));
    if (diffDays <= 0) return 'Em breve';
    if (diffDays === 1) return 'Amanhã';
    return `em ${diffDays} dias`;
}
</script>

<template>
    <div class="space-y-8">
        <h1 class="text-2xl font-bold">Módulos</h1>
        <div class="space-y-8">
            <section v-for="section in sections" :key="section.id" class="space-y-4">
                <h2 class="text-xl font-semibold text-zinc-300">{{ section.title }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="mod in section.modules" :key="mod.id" class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">
                        <!-- Módulo bloqueado (drip) -->
                        <div v-if="mod.is_locked" class="opacity-70">
                            <div class="relative aspect-video w-full bg-zinc-700 flex items-center justify-center">
                                <div class="flex flex-col items-center gap-2">
                                    <Lock class="h-8 w-8 text-zinc-400" />
                                    <span class="text-sm text-zinc-400">Disponível {{ formatUnlockDate(mod.unlocks_at) }}</span>
                                </div>
                            </div>
                            <div class="p-4">
                                <p class="font-medium text-zinc-400">{{ mod.title }}</p>
                            </div>
                        </div>
                        <!-- Módulo liberado -->
                        <Link v-else :href="`/m/${slug}/modulo/${mod.id}`" class="block">
                            <div class="aspect-video w-full bg-zinc-700 flex items-center justify-center">
                                <svg class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                            </div>
                            <div class="p-4">
                                <p class="font-medium">{{ mod.title }}</p>
                                <ul class="mt-2 space-y-1">
                                    <li v-for="lesson in mod.lessons" :key="lesson.id" class="flex items-center gap-2 text-sm">
                                        <span v-if="lesson.is_completed" class="text-emerald-400">✓</span>
                                        <Link :href="`/m/${slug}/modulo/${mod.id}?aula=${lesson.id}`" class="hover:text-[var(--ma-primary)] truncate">{{ lesson.title }}</Link>
                                    </li>
                                </ul>
                            </div>
                        </Link>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
