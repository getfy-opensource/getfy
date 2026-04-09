<script setup>
import { ref, computed, watch } from 'vue';
import { ShoppingBag, Check } from 'lucide-vue-next';

const props = defineProps({
    orderBumps: { type: Array, default: () => [] },
    selectedIds: { type: Array, default: () => [] },
    primaryColor: { type: String, default: '#7427F1' },
    /** Cor do card e da tag "Oferta especial" (borda, fundo, badge). Default amarelo/âmbar. */
    orderBumpColor: { type: String, default: '#F59E0B' },
    t: { type: Function, default: (k) => k },
    formatPrice: { type: Function, default: (v, c) => `R$ ${Number(v).toFixed(2)}` },
    displayCurrency: { type: String, default: 'BRL' },
});

const emit = defineEmits(['update:selectedIds']);

const selectedIds = ref([...props.selectedIds]);

const selectedSet = computed(() => new Set(selectedIds.value));

function toggle(bump) {
    const id = bump.id;
    if (selectedSet.value.has(id)) {
        selectedIds.value = selectedIds.value.filter((x) => x !== id);
    } else {
        selectedIds.value = [...selectedIds.value, id];
    }
    emit('update:selectedIds', selectedIds.value);
}

watch(
    () => props.selectedIds,
    (val) => {
        selectedIds.value = Array.isArray(val) ? [...val] : [];
    },
    { immediate: true }
);

watch(
    () => props.orderBumps,
    () => {
        const ids = (props.orderBumps || []).map((b) => b.id);
        selectedIds.value = selectedIds.value.filter((id) => ids.includes(id));
        emit('update:selectedIds', selectedIds.value);
    },
    { immediate: true }
);

const selectedBumps = computed(() =>
    props.orderBumps.filter((b) => selectedSet.value.has(b.id))
);
const ob = computed(() => props.orderBumpColor || '#F59E0B');
const orderBumpCardStyle = computed(() => ({
    borderColor: `${ob.value}cc`,
    background: `linear-gradient(to bottom right, ${ob.value}20, white)`,
}));
const orderBumpTagStyle = computed(() => ({
    backgroundColor: `${ob.value}e6`,
    color: '#1f2937',
}));
const totalBumpsBrl = computed(() =>
    selectedBumps.value.reduce((sum, b) => sum + (Number(b.amount_brl) || 0), 0)
);

defineExpose({
    selectedIds,
    selectedBumps,
    totalBumpsBrl,
});
</script>

<template>
    <section v-if="orderBumps && orderBumps.length" class="mb-8" data-id="order_bumps" data-checkout="order-bumps">
        <div class="mb-4 flex items-center justify-between gap-3" data-checkout="order-bumps-header">
            <div class="flex items-center gap-3">
                <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-600" aria-hidden="true">
                    <ShoppingBag class="h-5 w-5" />
                </span>
                <div>
                    <h2 class="text-lg font-semibold tracking-tight text-gray-900">
                        {{ t('checkout.voce_pode_gostar') || 'Você pode gostar' }}
                    </h2>
                    <p v-if="t('checkout.voce_pode_gostar_subtitle')" class="mt-0.5 text-sm text-gray-500">
                        {{ t('checkout.voce_pode_gostar_subtitle') }}
                    </p>
                </div>
            </div>
            <button
                v-if="selectedIds.length > 0"
                type="button"
                class="text-sm font-medium text-gray-500 hover:text-gray-700"
                @click="selectedIds = []; $emit('update:selectedIds', [])"
            >
                {{ t('checkout.deselect_all') || 'Desmarcar todos' }}
            </button>
        </div>

        <ul class="space-y-4" data-checkout="order-bumps-list">
            <li
                v-for="bump in orderBumps"
                :key="bump.id"
                :data-order-bump-id="bump.id"
                class="relative overflow-hidden rounded-2xl border-2 border-dashed p-4 shadow-sm transition bg-white"
                data-checkout="order-bump-card"
                :style="orderBumpCardStyle"
            >
                <label class="block w-full cursor-pointer">
                    <input
                        type="checkbox"
                        :checked="selectedSet.has(bump.id)"
                        class="sr-only"
                        @change="toggle(bump)"
                    />
                    <div class="absolute right-3 top-3">
                        <span
                            class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide"
                            :style="orderBumpTagStyle"
                        >
                            {{ t('checkout.oferta_especial') || 'Oferta especial' }}
                        </span>
                    </div>
                    <!-- Foto + título e descrição -->
                    <div class="flex flex-row gap-4 pr-24">
                        <div class="flex h-16 w-16 shrink-0 overflow-hidden rounded-xl bg-gray-100 ring-2 ring-gray-100">
                            <img
                                v-if="bump.image_url"
                                :src="bump.image_url"
                                :alt="bump.target_name"
                                class="h-full w-full object-cover"
                            />
                            <div v-else class="flex h-full w-full items-center justify-center text-gray-400">
                                <ShoppingBag class="h-8 w-8" />
                            </div>
                        </div>
                        <div class="min-w-0 flex-1">
                            <h3 class="font-bold text-gray-900">{{ bump.title }}</h3>
                            <p v-if="bump.description" class="mt-1 text-sm leading-relaxed text-gray-600">
                                {{ bump.description }}
                            </p>
                        </div>
                    </div>
                    <!-- Abaixo da foto: CTA à esquerda, valor à direita (nas duas extremidades) -->
                    <div class="mt-3 flex w-full items-center justify-between gap-4">
                        <span
                            class="flex min-h-[2.25rem] min-w-0 items-center gap-1.5 rounded-lg border-2 px-3 py-2 text-xs font-medium leading-tight transition"
                            :class="selectedSet.has(bump.id)
                                ? 'border-[var(--primary)] bg-[var(--primary)]/10 text-gray-800'
                                : 'border-gray-200 bg-white text-gray-800 hover:border-gray-300 hover:bg-gray-50'"
                            :style="{ '--primary': primaryColor }"
                        >
                            <span
                                class="inline-flex h-5 w-5 shrink-0 items-center justify-center rounded-md border-2 transition-colors"
                                :class="selectedSet.has(bump.id)
                                    ? 'border-[var(--primary)] bg-[var(--primary)]'
                                    : 'border-gray-300 bg-white'"
                                :style="selectedSet.has(bump.id) ? { backgroundColor: primaryColor, borderColor: primaryColor } : {}"
                            >
                                <Check
                                    v-if="selectedSet.has(bump.id)"
                                    class="h-3 w-3 text-white"
                                />
                            </span>
                            <span class="whitespace-nowrap text-xs font-medium">{{ bump.cta_title }}</span>
                        </span>
                        <div class="shrink-0 flex flex-col items-end">
                            <template v-if="bump.original_amount_brl != null && bump.original_amount_brl > bump.amount_brl">
                                <span class="text-sm font-normal text-gray-400 line-through">
                                    +{{ formatPrice(bump.original_amount_brl, displayCurrency) }}
                                </span>
                                <span class="text-base font-bold" :style="{ color: primaryColor }">
                                    +{{ formatPrice(bump.amount_brl, displayCurrency) }}
                                </span>
                            </template>
                            <template v-else>
                                <span class="text-base font-bold" :style="{ color: primaryColor }">
                                    +{{ formatPrice(bump.amount_brl, displayCurrency) }}
                                </span>
                            </template>
                        </div>
                    </div>
                </label>
            </li>
        </ul>
    </section>
</template>
