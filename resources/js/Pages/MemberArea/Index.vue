<script setup>
import { Link } from '@inertiajs/vue3';
import { ShoppingBag } from 'lucide-vue-next';
import LayoutGuest from '@/Layouts/LayoutGuest.vue';

defineOptions({ layout: LayoutGuest });

defineProps({
    produtos: { type: Array, default: () => [] },
});
</script>

<template>
    <div class="space-y-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Área de Membros</h1>
                <div class="flex items-center gap-3">
                    <Link
                        href="/meus-pedidos"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 px-3 py-2 text-sm font-medium text-zinc-700 dark:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition"
                    >
                        <ShoppingBag class="h-4 w-4" />
                        Meus Pedidos
                    </Link>
                    <Link href="/logout" method="post" as="button" class="text-sm text-zinc-600 hover:underline dark:text-zinc-400">Sair</Link>
                </div>
            </div>
            <p class="text-zinc-600 dark:text-zinc-400">Seus produtos e cursos.</p>
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <div
                    v-for="p in produtos"
                    :key="p.id"
                    class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-800/50 overflow-hidden transition hover:shadow-lg"
                >
                    <div v-if="p.image_url" class="aspect-video w-full bg-zinc-100 dark:bg-zinc-700">
                        <img :src="p.image_url" :alt="p.name" class="h-full w-full object-cover" />
                    </div>
                    <div class="p-4">
                        <h2 class="font-semibold text-zinc-900 dark:text-white">{{ p.name }}</h2>
                        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">{{ p.type }}</p>
                        <a
                            v-if="p.member_area_url"
                            :href="p.member_area_url"
                            class="mt-3 inline-block rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90"
                        >
                            Acessar área de membros →
                        </a>
                    </div>
                </div>
            </div>
            <p v-if="!produtos.length" class="text-zinc-500">Você ainda não tem acesso a nenhum produto.</p>
        </div>
</template>
