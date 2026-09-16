<script setup>
import { computed, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertCircle, CheckCircle2, CreditCard, Loader2 } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: null });

const props = defineProps({
    token: { type: String, required: true },
    subscription: { type: Object, required: true },
    product: { type: Object, required: true },
    plan: { type: Object, required: true },
    amount: { type: Number, required: true },
    amount_brl: { type: Number, required: true },
    available_payment_methods: { type: Array, default: () => [] },
    saved_payment_methods: { type: Array, default: () => [] },
    auto_pix_url: { type: String, default: null },
    card_offsession_available: { type: Boolean, default: false },
    flash: { type: Object, default: () => ({}) },
});

const form = useForm({
    token: props.token,
    payment_method: 'pix',
});

const methods = computed(() => {
    const list = props.available_payment_methods || [];
    if (list.length === 0) {
        return [{ id: 'manual', label: 'Outro (instruções por e-mail)' }];
    }
    return list.map((m) => ({ id: m.id, label: m.label }));
});

const onlyPix = computed(() => {
    const ids = methods.value.map((m) => m.id);
    return ids.length === 1 && ids[0] === 'pix';
});

watch(
    methods,
    (list) => {
        if (!list.some((m) => m.id === form.payment_method)) {
            form.payment_method = list[0]?.id ?? 'manual';
        }
    },
    { immediate: true }
);

watch(
    onlyPix,
    (yes) => {
        // Só PIX disponível: vai direto para a cobrança (sem tela de escolha).
        if (yes && props.auto_pix_url) {
            window.location.href = props.auto_pix_url;
        }
    },
    { immediate: true }
);

const amountFormatted = computed(() => {
    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
    }).format(props.amount_brl);
});

function submit() {
    const method = methods.value.find((m) => m.id === form.payment_method);
    form.payment_method = method ? method.id : 'manual';
    form.post('/renovar', {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head>
        <title>Renovar assinatura – {{ product.name }}</title>
    </Head>
    <div class="min-h-screen bg-zinc-100 dark:bg-zinc-900">
        <div class="mx-auto max-w-md px-4 py-12 sm:px-6">
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 sm:p-8">
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Renovar assinatura</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">{{ product.name }} · {{ plan.name }}</p>

                <div v-if="flash?.error" class="mt-4 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-200" role="alert">
                    <AlertCircle class="h-5 w-5 shrink-0" />
                    {{ flash.error }}
                </div>
                <div v-if="flash?.success" class="mt-4 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200" role="status">
                    <CheckCircle2 class="h-5 w-5 shrink-0" />
                    {{ flash.success }}
                </div>
                <div v-if="flash?.info" class="mt-4 flex items-center gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-800 dark:border-sky-800 dark:bg-sky-950/30 dark:text-sky-200" role="status">
                    <CheckCircle2 class="h-5 w-5 shrink-0" />
                    {{ flash.info }}
                </div>

                <div class="mt-6 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ amountFormatted }}</p>
                    <p v-if="subscription.current_period_end" class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                        Próxima renovação: {{ subscription.current_period_end }}
                    </p>
                </div>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <input v-model="form.token" type="hidden" name="token" />
                    <div>
                        <label class="mb-2 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Forma de pagamento</label>
                        <div class="space-y-2">
                            <label
                                v-for="m in methods"
                                :key="m.id"
                                class="flex cursor-pointer items-center gap-3 rounded-xl border-2 px-4 py-3 transition"
                                :class="form.payment_method === m.id
                                    ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5 dark:bg-[var(--color-primary)]/10'
                                    : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:hover:border-zinc-500'"
                            >
                                <input v-model="form.payment_method" type="radio" :value="m.id" class="h-4 w-4 border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]" />
                                <CreditCard class="h-5 w-5 text-zinc-500 dark:text-zinc-400" />
                                <span class="font-medium text-zinc-900 dark:text-white">{{ m.label }}</span>
                            </label>
                        </div>
                    </div>
                    <div v-if="form.payment_method === 'card'" class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-100">
                        A renovação com cartão exige digitação dos dados no checkout (sem cobrança automática off-session). Ao continuar, você será redirecionado.
                    </div>
                    <Button type="submit" class="w-full" :disabled="form.processing">
                        <Loader2 v-if="form.processing" class="mr-2 h-4 w-4 animate-spin" />
                        {{ form.processing ? 'Processando…' : (form.payment_method === 'pix' ? 'Gerar PIX e pagar' : 'Pagar e renovar') }}
                    </Button>
                </form>
            </div>
        </div>
    </div>
</template>
