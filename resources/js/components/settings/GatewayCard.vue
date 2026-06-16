<script setup>
import { computed } from 'vue';
import { CreditCard } from 'lucide-vue-next';
import {
    TooltipRoot,
    TooltipTrigger,
    TooltipContent,
    TooltipPortal,
    TooltipProvider,
} from 'radix-vue';

const props = defineProps({
    gateway: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['click']);

const methodLabels = {
    pix: 'PIX',
    card: 'Cartão',
    boleto: 'Boleto',
    pix_auto: 'Pix Auto',
    pix_parcelado: 'PIX Parcelado',
};

const methods = computed(() =>
    (props.gateway.methods || []).map((m) => methodLabels[m] || m)
);

const imageUrl = computed(() => {
    const img = props.gateway.image;
    if (!img) return null;
    if (img.startsWith('http') || img.startsWith('//')) return img;
    return `/${img.replace(/^\//, '')}`;
});

const countryFlagUrl = computed(() => {
    const custom = props.gateway.country_flag;
    if (custom) return `/images/gateways/paises/${custom.replace(/^\//, '')}`;
    const code = props.gateway.country;
    if (!code) return null;
    return `/images/gateways/paises/${code}.png`;
});

const countryName = computed(() => props.gateway.country_name || null);

const countries = computed(() => {
    const list = props.gateway.countries;
    return Array.isArray(list) && list.length > 0 ? list : null;
});

const hasMultipleCountries = computed(() => countries.value != null);
</script>

<template>
    <button
        type="button"
        class="group relative flex w-full flex-row gap-3 rounded-xl border border-zinc-200 bg-white p-3 text-left shadow-sm transition hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800"
        @click="emit('click')"
    >
        <!-- Bandeira(s) do(s) país(es) no canto superior direito -->
        <TooltipProvider :delay-duration="300">
            <TooltipRoot v-if="hasMultipleCountries">
                <TooltipTrigger as-child>
                    <div
                        class="absolute right-3 top-3 z-10 flex shrink-0 items-center gap-0.5"
                        @click.stop
                    >
                        <img
                            v-for="c in countries"
                            :key="c.flag"
                            :src="`/images/gateways/paises/${c.flag.replace(/^\//, '')}`"
                            :alt="c.name"
                            class="h-5 w-5 rounded-full border border-zinc-200 object-cover shadow-sm dark:border-zinc-600"
                            @error="($e) => ($e.target.style.display = 'none')"
                        />
                    </div>
                </TooltipTrigger>
                <TooltipPortal>
                    <TooltipContent
                        side="bottom"
                        :side-offset="6"
                        class="max-w-[12rem] rounded-lg border border-zinc-200 bg-zinc-900 px-3 py-2 text-sm font-medium text-white shadow-lg dark:border-zinc-700 dark:bg-zinc-100 dark:text-zinc-900"
                    >
                        <div class="flex flex-col gap-0.5">
                            <span v-for="c in countries" :key="c.flag">{{ c.name }}</span>
                        </div>
                    </TooltipContent>
                </TooltipPortal>
            </TooltipRoot>
            <TooltipRoot v-else-if="countryFlagUrl && countryName">
                <TooltipTrigger as-child>
                    <div
                        class="absolute right-3 top-3 z-10 flex h-5 w-5 shrink-0 items-center justify-center overflow-hidden rounded-full border border-zinc-200 bg-white shadow-sm dark:border-zinc-600 dark:bg-zinc-700"
                        @click.stop
                    >
                        <img
                            :src="countryFlagUrl"
                            :alt="countryName"
                            class="h-full w-full object-cover"
                            @error="($e) => ($e.target.style.display = 'none')"
                        />
                    </div>
                </TooltipTrigger>
                <TooltipPortal>
                    <TooltipContent
                        side="bottom"
                        :side-offset="6"
                        class="rounded-lg border border-zinc-200 bg-zinc-900 px-3 py-2 text-sm font-medium text-white shadow-lg dark:border-zinc-700 dark:bg-zinc-100 dark:text-zinc-900"
                    >
                        {{ countryName }}
                    </TooltipContent>
                </TooltipPortal>
            </TooltipRoot>
        </TooltipProvider>
        <div
            class="flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-zinc-200 bg-zinc-100 dark:border-zinc-600 dark:bg-zinc-700/50"
        >
            <img
                v-if="imageUrl"
                :src="imageUrl"
                :alt="gateway.name"
                class="h-full w-full object-cover"
                @error="($e) => ($e.target.style.display = 'none')"
            />
            <CreditCard
                v-else
                class="h-8 w-8 text-zinc-400 dark:text-zinc-500"
                aria-hidden="true"
            />
        </div>
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <span class="font-medium text-zinc-900 dark:text-white">
                    {{ gateway.name }}
                </span>
            </div>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                <span
                    v-for="method in methods"
                    :key="method"
                    class="inline-block rounded bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400"
                >
                    {{ method }}
                </span>
            </div>
            <div class="mt-1.5 flex items-center gap-2 text-xs">
                <span
                    v-if="gateway.is_connected"
                    class="text-emerald-600 dark:text-emerald-400"
                >
                    Conectado
                </span>
                <span
                    v-else-if="gateway.is_configured"
                    class="text-amber-600 dark:text-amber-400"
                >
                    Configurado
                </span>
                <span v-else class="text-zinc-500 dark:text-zinc-400">
                    Não configurado
                </span>
            </div>
        </div>
    </button>
</template>
