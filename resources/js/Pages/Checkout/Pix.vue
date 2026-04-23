<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import axios from 'axios';
import QrcodeVue from 'qrcode.vue';
import { Clock, Copy, Check, Building2, QrCode, CircleDollarSign } from 'lucide-vue-next';
import confetti from 'canvas-confetti';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';

defineOptions({ layout: null });

const conversionPixelsRef = ref(null);
const pixelsReady = ref(false);
const pendingPurchase = ref(false);

const props = defineProps({
    token: { type: String, required: true },
    order_id: { type: Number, required: true },
    qrcode: { type: String, default: null },
    copy_paste: { type: String, default: '' },
    amount_formatted: { type: String, default: 'R$ 0,00' },
    product_name: { type: String, default: '' },
    checkout_slug: { type: String, default: '' },
    redirect_after_purchase: { type: String, default: null },
    customer_name: { type: String, default: null },
    customer_email: { type: String, default: null },
    customer_phone: { type: String, default: null },
    created_at: { type: Number, required: true },
    expiry_seconds: { type: Number, default: 900 },
    amount: { type: Number, default: 0 },
    conversion_pixels: { type: Object, default: () => ({}) },
});

const qrcodeSrc = computed(() => {
    const q = props.qrcode || '';
    if (!q) return '';
    return q.startsWith('data:') ? q : `data:image/png;base64,${q}`;
});

const showQrFromCopyPaste = computed(
    () => !qrcodeSrc.value && (props.copy_paste || '').trim().length > 0
);

const copyButtonText = ref('Copiar');
const status = ref('pending');
const confirmFeedback = ref('');
const confirmChecking = ref(false);
let pollInterval = null;
let timerInterval = null;

const endTime = computed(() => (props.created_at + props.expiry_seconds) * 1000);
const timeLeft = ref(props.expiry_seconds);

function updateTimer() {
    const now = Date.now();
    const left = Math.max(0, Math.floor((endTime.value - now) / 1000));
    timeLeft.value = left;
    if (left <= 0 && pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
}

const timerDisplay = computed(() => {
    const left = timeLeft.value;
    const m = Math.floor(left / 60);
    const s = left % 60;
    return `${m < 10 ? '0' : ''}${m}:${s < 10 ? '0' : ''}${s}`;
});

const timerExpired = computed(() => timeLeft.value <= 0);
const hasCustomerInfo = computed(
    () => (props.customer_name || '') !== '' || (props.customer_email || '') !== '' || (props.customer_phone || '') !== ''
);

async function checkOrderStatus() {
    try {
        const { data } = await axios.get('/checkout/order-status', { params: { token: props.token } });
        if (data.status === 'completed') {
            status.value = 'completed';
            confirmFeedback.value = 'Pagamento aprovado! Redirecionando...';
            if (pollInterval) {
                clearInterval(pollInterval);
                pollInterval = null;
            }
            pendingPurchase.value = true;
            if (pixelsReady.value && conversionPixelsRef.value?.firePurchase) {
                conversionPixelsRef.value.firePurchase(props.amount, 'BRL', String(props.order_id), false, 'pix');
                pendingPurchase.value = false;
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

function onConversionPixelsReady() {
    pixelsReady.value = true;
    if (pendingPurchase.value && conversionPixelsRef.value?.firePurchase) {
        conversionPixelsRef.value.firePurchase(props.amount, 'BRL', String(props.order_id), false, 'pix');
        pendingPurchase.value = false;
    }
}

function onConfirmPayment() {
    confirmChecking.value = true;
    confirmFeedback.value = 'Aguardando...';
    checkOrderStatus().then((data) => {
        confirmChecking.value = false;
        if (data.status !== 'completed') {
            confirmFeedback.value = 'Ainda aguardando. O pagamento é confirmado automaticamente.';
        }
    });
}

async function copyPixCode() {
    const code = props.copy_paste || '';
    if (!code) return;
    try {
        await navigator.clipboard.writeText(code);
        copyButtonText.value = 'Copiado!';
        setTimeout(() => (copyButtonText.value = 'Copiar'), 2000);
    } catch {
        copyButtonText.value = 'Copiar';
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
    updateTimer();
    timerInterval = setInterval(updateTimer, 1000);
    pollInterval = setInterval(() => {
        if (status.value === 'completed') return;
        checkOrderStatus();
    }, 5000);
});

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval);
    if (pollInterval) clearInterval(pollInterval);
});
</script>

<template>
    <ConversionPixels ref="conversionPixelsRef" :pixels="props.conversion_pixels" @ready="onConversionPixelsReady" />
    <Head>
        <title>Pagamento PIX</title>
    </Head>
    <div class="min-h-screen bg-gray-100 px-4 py-6 sm:py-8 pb-12">
        <div class="mx-auto w-full max-w-md">
            <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div v-if="qrcodeSrc || showQrFromCopyPaste" class="flex justify-center pt-6 pb-2">
                    <div class="w-40 h-40 sm:w-44 sm:h-44 rounded-2xl border-2 border-dashed border-gray-300 bg-white p-2.5 shadow-sm">
                        <img
                            v-if="qrcodeSrc"
                            :src="qrcodeSrc"
                            alt="QR Code PIX"
                            class="h-full w-full object-contain"
                            style="image-rendering: pixelated"
                        />
                        <QrcodeVue
                            v-else
                            :value="copy_paste"
                            :size="160"
                            level="M"
                            class="h-full w-full"
                        />
                    </div>
                </div>
                <div class="px-5 sm:px-6 pb-6">
                    <h1 class="mb-1 text-center text-lg font-bold text-gray-900 sm:text-xl">Pague {{ amount_formatted }} via Pix</h1>
                    <p class="mb-5 text-center text-xs text-gray-500 sm:text-sm">Copie o código ou use a câmera para ler o QR Code e realize o pagamento no app do seu banco.</p>

                    <p class="mb-2 text-xs text-gray-500">Pix Copia e Cola</p>
                    <input
                        type="text"
                        :value="copy_paste"
                        readonly
                        class="mb-3 w-full rounded-xl border border-gray-200 bg-gray-50 px-3 py-2.5 text-base text-gray-800 focus:outline-none"
                    />
                    <button
                        type="button"
                        class="btn-copy mb-4 flex w-full items-center justify-center gap-1.5 rounded-xl bg-gray-900 px-4 py-3 text-sm font-semibold text-white transition-opacity hover:opacity-90"
                        @click="copyPixCode"
                    >
                        <Copy class="h-4 w-4" />
                        {{ copyButtonText }}
                    </button>

                    <div class="mb-4 space-y-3">
                        <button
                            type="button"
                            class="flex w-full items-center justify-center gap-2 rounded-xl border-2 border-gray-200 bg-white py-3 font-semibold text-gray-900 transition-colors hover:bg-gray-50 disabled:opacity-70"
                            :disabled="confirmChecking || status === 'completed'"
                            @click="onConfirmPayment"
                        >
                            <Check class="h-4 w-4 text-green-600" />
                            {{ confirmChecking ? 'Verificando...' : 'Confirmar pagamento' }}
                        </button>
                        <p v-if="confirmFeedback" class="text-center text-xs text-gray-500" :class="status === 'completed' ? 'text-green-600' : ''">
                            {{ confirmFeedback }}
                        </p>
                    </div>

                    <div class="timer-card mb-4 rounded-xl border-2 border-dashed border-gray-200 bg-gray-50 p-4">
                        <div class="flex items-center justify-center gap-2">
                            <Clock class="h-4 w-4 shrink-0 text-gray-600" />
                            <span class="text-sm font-medium text-gray-700">Código expira em</span>
                            <span
                                class="ml-auto font-mono text-lg font-bold tabular-nums text-gray-900"
                                :class="timerExpired ? 'text-red-600' : ''"
                            >
                                {{ timerDisplay }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <div class="mt-4 w-full max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div class="space-y-4 p-5 sm:p-6">
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100">
                            <Building2 class="h-5 w-5 text-gray-700" />
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Acesse seu banco</h3>
                            <p class="text-xs text-gray-600">Abra o app do seu banco, é rapidinho.</p>
                        </div>
                    </div>
                    <div class="border-t border-dashed border-gray-200"></div>
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100">
                            <QrCode class="h-5 w-5 text-gray-700" />
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Escolha a opção Pix</h3>
                            <p class="text-xs text-gray-600">Selecione "Pix Copia e Cola" ou "Ler QR code".</p>
                        </div>
                    </div>
                    <div class="border-t border-dashed border-gray-200"></div>
                    <div class="flex items-start gap-3">
                        <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100">
                            <CircleDollarSign class="h-5 w-5 text-gray-700" />
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900">Conclua o pagamento</h3>
                            <p class="text-xs text-gray-600">Cole o código ou leia o QR code, confirme os dados e pronto!</p>
                        </div>
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
                            <span class="text-gray-700">Pagamento Pix</span>
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

            <!-- Status + Informações do cliente -->
            <div class="mt-4 w-full max-w-md overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-lg">
                <div class="p-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-gray-200 bg-gray-100">
                                <QrCode class="h-5 w-5 text-gray-700" />
                            </div>
                            <span class="text-sm font-medium text-gray-900">Pix</span>
                        </div>
                        <span
                            class="inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 text-xs font-semibold"
                            :class="status === 'completed'
                                ? 'border-green-200 bg-green-100 text-green-800'
                                : 'border-amber-200 bg-amber-100 text-amber-800'"
                        >
                            <Clock v-if="status === 'pending'" class="h-3.5 w-3.5" />
                            <Check v-else class="h-3.5 w-3.5" />
                            {{ status === 'completed' ? 'Aprovado' : 'Pendente' }}
                        </span>
                    </div>
                    <div v-if="hasCustomerInfo" class="mt-5 border-t border-dashed border-gray-300 pt-5">
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
