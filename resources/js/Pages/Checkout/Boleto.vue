<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import { Copy, FileText, Check } from 'lucide-vue-next';
import confetti from 'canvas-confetti';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';

defineOptions({ layout: null });

const conversionPixelsRef = ref(null);

const props = defineProps({
    token: { type: String, required: true },
    order_id: { type: Number, required: true },
    amount_formatted: { type: String, default: 'R$ 0,00' },
    expire_at: { type: String, default: null },
    barcode: { type: String, default: '' },
    pdf_url: { type: String, default: null },
    product_name: { type: String, default: '' },
    checkout_slug: { type: String, default: '' },
    redirect_after_purchase: { type: String, default: null },
    customer_name: { type: String, default: null },
    customer_email: { type: String, default: null },
    customer_phone: { type: String, default: null },
    conversion_pixels: { type: Object, default: () => ({}) },
});

const copyButtonText = ref('Copiar código');
const status = ref('pending');
let pollInterval = null;

const expireAtFormatted = computed(() => {
    const raw = props.expire_at;
    if (!raw) return '—';
    const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})/);
    if (match) {
        return `${match[3]}/${match[2]}/${match[1]}`;
    }
    return raw;
});

const hasCustomerInfo = computed(
    () => (props.customer_name || '') !== '' || (props.customer_email || '') !== '' || (props.customer_phone || '') !== ''
);

async function checkOrderStatus() {
    try {
        const { data } = await axios.get('/checkout/order-status', { params: { token: props.token } });
        if (data.status === 'completed') {
            status.value = 'completed';
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            if (conversionPixelsRef.value?.firePurchase) {
                conversionPixelsRef.value.firePurchase(0, 'BRL', String(props.order_id), false, 'boleto');
            }
            const url = data.redirect_url || props.redirect_after_purchase || '/area-membros';
            if (url.startsWith('http') || url.startsWith('//')) {
                window.location.href = url;
            } else {
                router.visit(url);
            }
        }
        return data;
    } catch {
        return { status: 'pending' };
    }
}

async function copyBarcode() {
    const code = props.barcode || '';
    if (!code) return;
    try {
        await navigator.clipboard.writeText(code);
        copyButtonText.value = 'Copiado!';
        setTimeout(() => (copyButtonText.value = 'Copiar código'), 2000);
    } catch {
        copyButtonText.value = 'Copiar código';
    }
}

function printBoleto() {
    const url = props.pdf_url;
    if (url) {
        window.open(url, '_blank', 'noopener,noreferrer');
    }
}

function backToCheckout() {
    if (props.checkout_slug) {
        router.visit(`/c/${props.checkout_slug}`);
    } else {
        router.visit('/');
    }
}

onMounted(() => {
    confetti({ particleCount: 120, spread: 70, origin: { y: 0.6 } });
    pollInterval = setInterval(() => {
        if (status.value === 'completed') return;
        checkOrderStatus();
    }, 15000);
});

onUnmounted(() => {
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <ConversionPixels ref="conversionPixelsRef" :pixels="props.conversion_pixels" />
    <Head>
        <title>Boleto gerado</title>
    </Head>
    <div class="min-h-screen bg-gray-100 px-4 py-6 sm:py-8 pb-12">
        <div class="mx-auto w-full max-w-md">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div class="px-5 sm:px-6 pt-6 pb-6">
                    <h1 class="mb-3 text-center text-lg font-bold text-gray-900 sm:text-xl">Boleto gerado</h1>
                    <p class="mb-5 text-center text-xs text-gray-500 sm:text-sm">
                        O acesso ao produto digital será enviado via e-mail depois que o boleto for pago. A confirmação do pagamento pode levar até 48 horas.
                    </p>

                    <div class="mb-4 space-y-3 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 p-4">
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-600">Valor do boleto</span>
                            <span class="font-bold text-gray-900">{{ amount_formatted }}</span>
                        </div>
                        <div class="flex justify-between items-center text-sm">
                            <span class="text-gray-600">Data de vencimento</span>
                            <span class="font-medium text-gray-900">{{ expireAtFormatted }}</span>
                        </div>
                    </div>

                    <p class="mb-2 text-xs text-gray-500">Código do Boleto</p>
                    <input
                        type="text"
                        :value="barcode"
                        readonly
                        class="mb-3 w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-base text-gray-800 focus:outline-none font-mono"
                    />
                    <div class="flex flex-col sm:flex-row gap-3 mb-4">
                        <button
                            type="button"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                            @click="copyBarcode"
                        >
                            <Copy class="h-4 w-4" />
                            {{ copyButtonText }}
                        </button>
                        <button
                            v-if="pdf_url"
                            type="button"
                            class="flex flex-1 items-center justify-center gap-1.5 rounded-xl border-2 border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-900 transition-colors hover:bg-gray-50"
                            @click="printBoleto"
                        >
                            <FileText class="h-4 w-4" />
                            Imprimir boleto
                        </button>
                    </div>
                </div>
            </div>

            <div class="mt-4 w-full max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div class="p-5 sm:p-6">
                    <h3 class="mb-4 text-sm font-bold text-gray-900">Resumo da compra</h3>
                    <div class="mb-3 space-y-2">
                        <div v-if="product_name" class="flex items-start justify-between gap-2 text-xs">
                            <span class="text-gray-600 shrink-0">Produto</span>
                            <span class="font-medium text-gray-900 text-right">{{ product_name }}</span>
                        </div>
                        <div class="flex items-center justify-between text-xs">
                            <span class="text-gray-700">Pagamento Boleto</span>
                            <span class="font-medium text-gray-900">{{ amount_formatted }}</span>
                        </div>
                    </div>
                    <div class="mt-3 border-t border-dashed border-gray-200 pt-3">
                        <div class="flex items-center justify-between text-sm">
                            <span class="font-bold text-gray-900">Total</span>
                            <span class="font-bold text-gray-900">{{ amount_formatted }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="hasCustomerInfo" class="mt-4 w-full max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div class="p-5 sm:p-6">
                    <h3 class="mb-4 text-sm font-bold text-gray-900">Informações</h3>
                    <div class="space-y-3 text-sm">
                        <div v-if="customer_name" class="flex items-center justify-between">
                            <span class="text-gray-600">Cliente</span>
                            <span class="ml-2 break-all text-right font-medium text-gray-900">{{ customer_name }}</span>
                        </div>
                        <div v-if="customer_email" class="flex items-center justify-between">
                            <span class="text-gray-600">E-mail</span>
                            <span class="ml-2 break-all text-right font-medium text-gray-900">{{ customer_email }}</span>
                        </div>
                        <div v-if="customer_phone" class="flex items-center justify-between">
                            <span class="text-gray-600">Telefone</span>
                            <span class="font-medium text-gray-900">{{ customer_phone }}</span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 w-full max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div class="p-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100">
                                <FileText class="h-5 w-5 text-gray-700" />
                            </div>
                            <span class="text-sm font-medium text-gray-900">Boleto</span>
                        </div>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold"
                            :class="status === 'completed'
                                ? 'border-green-200 bg-green-100 text-green-800'
                                : 'border-amber-200 bg-amber-100 text-amber-800'"
                        >
                            <Check v-if="status === 'completed'" class="h-3.5 w-3.5" />
                            {{ status === 'completed' ? 'Pago' : 'Pendente' }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="mt-6 text-center">
                <button
                    type="button"
                    class="text-sm font-medium text-gray-600 underline underline-offset-2 hover:text-gray-900"
                    @click="backToCheckout"
                >
                    Voltar ao checkout
                </button>
            </div>
        </div>
    </div>
</template>
