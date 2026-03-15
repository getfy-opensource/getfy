<script setup>
import { Link } from '@inertiajs/vue3';
import MemberAreaAppLayout from '@/Layouts/MemberAreaAppLayout.vue';

defineOptions({ layout: MemberAreaAppLayout });

const props = defineProps({
    product: { type: Object, required: true },
    config: { type: Object, default: () => ({}) },
    sections: { type: Array, default: () => [] },
    slug: { type: String, required: true },
});
</script>

<template>
    <div class="space-y-8">
        <h1 class="text-2xl font-bold">Módulos</h1>
        <div class="space-y-8">
            <section v-for="section in sections" :key="section.id" class="space-y-4">
                <h2 class="text-xl font-semibold text-zinc-300">{{ section.title }}</h2>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <div v-for="mod in section.modules" :key="mod.id" class="rounded-xl border border-zinc-700 bg-zinc-800/50 overflow-hidden">
                        <Link v-if="!mod.is_locked" :href="`/m/${slug}/modulo/${mod.id}`" class="block">
                            <div class="aspect-video w-full bg-zinc-700 flex items-center justify-center">
                                <svg class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                            </div>
                        </Link>
                        <div v-else class="block opacity-70">
                            <div class="aspect-video w-full bg-zinc-700 flex items-center justify-center relative">
                                <svg class="h-12 w-12 text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z" /></svg>
                                <div class="absolute inset-0 bg-black/40" />
                            </div>
                        </div>
                        <div class="p-4">
                            <p class="font-medium">{{ mod.title }}</p>
                            <p v-if="mod.is_locked && mod.lock_message" class="mt-1 text-xs text-zinc-400">{{ mod.lock_message }}</p>
                            <ul class="mt-2 space-y-1">
                                <li v-for="lesson in mod.lessons" :key="lesson.id" class="flex items-center justify-between gap-2 text-sm">
                                    <span class="flex min-w-0 items-center gap-2 truncate">
                                        <span v-if="lesson.is_completed" class="text-emerald-400">✓</span>
                                        <Link
                                            v-if="!mod.is_locked && !lesson.is_locked"
                                            :href="`/m/${slug}/modulo/${mod.id}?aula=${lesson.id}`"
                                            class="hover:text-[var(--ma-primary)] truncate"
                                        >
                                            {{ lesson.title }}
                                        </Link>
                                        <span v-else class="truncate text-zinc-500">{{ lesson.title }}</span>
                                    </span>
                                    <span v-if="!mod.is_locked && lesson.is_locked && lesson.lock_message" class="shrink-0 text-[10px] text-zinc-500">{{ lesson.lock_message }}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
