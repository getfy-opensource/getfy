<script setup>
import { ref, watch, computed } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import { X, Loader2 } from 'lucide-vue-next';

const props = defineProps({
    open: { type: Boolean, default: false },
    productId: { type: [String, Number], required: true },
    priceBrl: { type: Number, default: 0 },
    modelValue: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'update:modelValue', 'save']);

const local = ref({});
const loading = ref(false);
const loadError = ref('');
const platformHint = ref(null);
const fieldErrors = ref({});

function emptyRules() {
    return {
        max_installments: null,
        down_payment_cents: null,
        min_down_payment_bps: null,
        max_down_payment_bps: null,
        early_payment_discount_bps: 0,
        payoff_discount_bps: 0,
        overdue_payoff_discount_bps: 0,
    };
}

watch(
    () => [props.open, props.modelValue],
    ([open, val]) => {
        if (open) {
            local.value = { ...emptyRules(), ...(val && typeof val === 'object' ? val : {}) };
            fieldErrors.value = {};
            fetchPlatformRules();
        }
    },
    { immediate: true },
);

async function fetchPlatformRules() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await axios.get(`/produtos/${props.productId}/pix-parcelado/platform-rules`);
        platformHint.value = data?.merged ?? data;
    } catch (e) {
        loadError.value = e?.response?.data?.message || 'Não foi possível carregar regras da plataforma.';
        platformHint.value = null;
    } finally {
        loading.value = false;
    }
}

const maxInstallmentsHint = computed(() => {
    const max = platformHint.value?.platform_max_installments ?? platformHint.value?.max_installments;
    return max ? `${max}x` : '—';
});

function bpsToPercent(bps) {
    if (bps === null || bps === undefined || bps === '') return '';
    return String(Number(bps) / 100);
}

function percentToBps(val) {
    if (val === '' || val == null) return null;
    const n = parseFloat(String(val).replace(',', '.'));
    if (Number.isNaN(n)) return null;
    return Math.round(n * 100);
}

function centsToReais(cents) {
    if (cents === null || cents === undefined || cents === '') return '';
    return (Number(cents) / 100).toFixed(2).replace('.', ',');
}

function reaisToCents(val) {
    if (val === '' || val == null) return null;
    const n = parseFloat(String(val).replace(/\./g, '').replace(',', '.'));
    if (Number.isNaN(n)) return null;
    return Math.round(n * 100);
}

const downPaymentReais = computed({
    get: () => centsToReais(local.value.down_payment_cents),
    set: (v) => {
        local.value.down_payment_cents = reaisToCents(v);
    },
});

function save() {
    fieldErrors.value = {};
    emit('update:modelValue', { ...local.value });
    emit('save', { ...local.value });
    emit('close');
}

function close() {
    emit('close');
}
</script>

<template>
    <Teleport to="body">
        <div v-show="open" class="fixed inset-0 z-[100000] flex justify-end" aria-modal="true" role="dialog">
            <div class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60" aria-hidden="true" @click="close" />
            <aside class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-xl dark:bg-zinc-900">
                <div class="flex items-center justify-between rounded-tl-2xl border-b border-zinc-200 px-4 py-4 dark:border-zinc-700">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Regras PIX Parcelado</h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div class="flex flex-1 flex-col overflow-y-auto p-4">
                    <p class="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
                        Configure entrada, parcelas e descontos congelados no plano CajuPay. Valor mínimo R$ 50,00.
                    </p>

                    <div v-if="loading" class="mb-4 flex items-center gap-2 text-sm text-zinc-500">
                        <Loader2 class="h-4 w-4 animate-spin" />
                        Carregando faixas da plataforma…
                    </div>
                    <p v-else-if="loadError" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        {{ loadError }}
                    </p>
                    <p v-else-if="platformHint" class="mb-4 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800/50 dark:text-zinc-300">
                        Com preço R$ {{ priceBrl.toFixed(2).replace('.', ',') }}, a plataforma permite até
                        <strong>{{ maxInstallmentsHint }}</strong> parcelas.
                    </p>

                    <div class="space-y-4">
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Máximo de parcelas (opcional)</label>
                            <input
                                v-model.number="local.max_installments"
                                type="number"
                                min="1"
                                max="24"
                                placeholder="Usar só limite da plataforma"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Entrada fixa (R$)</label>
                            <input
                                v-model="downPaymentReais"
                                type="text"
                                inputmode="decimal"
                                placeholder="Opcional"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Entrada mínima (%)</label>
                                <input
                                    :value="bpsToPercent(local.min_down_payment_bps)"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="Ex.: 20"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                    @input="local.min_down_payment_bps = percentToBps($event.target.value)"
                                />
                            </div>
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Entrada máxima (%)</label>
                                <input
                                    :value="bpsToPercent(local.max_down_payment_bps)"
                                    type="text"
                                    inputmode="decimal"
                                    placeholder="Ex.: 60"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                    @input="local.max_down_payment_bps = percentToBps($event.target.value)"
                                />
                            </div>
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Desconto antecipação (%)</label>
                            <input
                                :value="bpsToPercent(local.early_payment_discount_bps)"
                                type="text"
                                inputmode="decimal"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                @input="local.early_payment_discount_bps = percentToBps($event.target.value) ?? 0"
                            />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Desconto quitação (%)</label>
                            <input
                                :value="bpsToPercent(local.payoff_discount_bps)"
                                type="text"
                                inputmode="decimal"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                @input="local.payoff_discount_bps = percentToBps($event.target.value) ?? 0"
                            />
                        </div>
                        <div>
                            <label class="mb-1.5 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Desconto quitação em atraso (%)</label>
                            <input
                                :value="bpsToPercent(local.overdue_payoff_discount_bps)"
                                type="text"
                                inputmode="decimal"
                                class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2.5 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                @input="local.overdue_payoff_discount_bps = percentToBps($event.target.value) ?? 0"
                            />
                        </div>
                    </div>
                </div>

                <div class="border-t border-zinc-200 p-4 dark:border-zinc-700">
                    <Button type="button" class="w-full" @click="save">Salvar regras</Button>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
