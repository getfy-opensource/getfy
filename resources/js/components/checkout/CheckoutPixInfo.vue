<script setup>
import { CircleCheck, CreditCard, FileText } from 'lucide-vue-next';

defineProps({
    primaryColor: { type: String, default: '#7427F1' },
    t: { type: Function, default: (k) => k },
});

const steps = [
    {
        icon: FileText,
        titleKey: 'checkout.pix_step1_title',
        descKey: 'checkout.pix_step1_desc',
    },
    {
        icon: CreditCard,
        titleKey: 'checkout.pix_step2_title',
        descKey: 'checkout.pix_step2_desc',
    },
    {
        icon: CircleCheck,
        titleKey: 'checkout.pix_step3_title',
        descKey: 'checkout.pix_step3_desc',
    },
];
</script>

<template>
    <div class="space-y-2.5" data-checkout="pix-info">
        <div
            class="flex items-center gap-3 rounded-xl border px-3.5 py-3"
            :style="{
                borderColor: primaryColor + '40',
                backgroundColor: primaryColor + '12',
            }"
        >
            <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white/80"
                aria-hidden="true"
            >
                <img src="/images/gateways/pix.svg" alt="" class="h-5 w-5 object-contain" />
            </span>
            <div class="min-w-0">
                <p
                    class="text-[15px] font-bold leading-tight"
                    :style="{ color: primaryColor }"
                >
                    {{ t('checkout.pague_com_pix') || 'Pague com Pix' }}
                </p>
                <p class="mt-1 flex items-center gap-1.5 text-xs font-medium leading-snug text-emerald-700">
                    <CircleCheck class="h-3.5 w-3.5 shrink-0" stroke-width="2.5" aria-hidden="true" />
                    <span>{{ t('checkout.pix_aprovacao_instantanea') || 'Aprovação instantânea' }}</span>
                </p>
            </div>
        </div>

        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="divide-y divide-dashed divide-gray-200">
                <div
                    v-for="(step, index) in steps"
                    :key="index"
                    class="flex items-start gap-3 px-3.5 py-3"
                >
                    <span
                        class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                        :style="{
                            backgroundColor: primaryColor + '14',
                            color: primaryColor,
                        }"
                        aria-hidden="true"
                    >
                        <component :is="step.icon" class="h-4 w-4" stroke-width="2" />
                    </span>
                    <div class="min-w-0">
                        <h3 class="text-sm font-bold leading-snug text-gray-900">
                            {{ t(step.titleKey) }}
                        </h3>
                        <p class="mt-0.5 text-xs leading-snug text-gray-500">
                            {{ t(step.descKey) }}
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
