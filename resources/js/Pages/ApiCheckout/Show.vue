<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { QrCode, Barcode, CreditCard, Receipt, ShieldCheck, AlertCircle, ArrowLeft } from 'lucide-vue-next';

defineOptions({ layout: null });

const props = defineProps({
    session_token: { type: String, required: true },
    app_name: { type: String, default: '' },
    app_logo_url: { type: String, default: null },
    app_sidebar_bg_color: { type: String, default: '#18181b' },
    customer_email: { type: String, default: null },
    customer_name: { type: String, default: null },
    amount: { type: Number, required: true },
    currency: { type: String, default: 'BRL' },
    currencies: { type: Array, default: () => [] },
    product_name: { type: String, default: null },
    product_image_url: { type: String, default: null },
    available_methods: { type: Array, default: () => [] },
    return_url: { type: String, default: null },
    card_gateway_slug: { type: String, default: null },
    card_stripe_publishable_key: { type: String, default: '' },
    card_stripe_sandbox: { type: Boolean, default: false },
    card_stripe_link_enabled: { type: Boolean, default: true },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error ?? null);
const flashSuccess = computed(() => page.props.flash?.success ?? null);

function formatPrice(value, code) {
    const n = Number(value);
    if (Number.isNaN(n)) return '';
    const locale = code === 'BRL' ? 'pt-BR' : code === 'EUR' ? 'de-DE' : 'en-US';
    return new Intl.NumberFormat(locale, { style: 'currency', currency: code }).format(n);
}

const displayTitle = computed(() => props.product_name || 'Pagamento');

const currencyList = computed(() => (Array.isArray(props.currencies) ? props.currencies : []));

/** Moeda selecionada para exibição (inicia com a moeda da sessão). */
const displayCurrency = ref(props.currency);

/** Atualiza displayCurrency quando a sessão mudar (ex.: nova navegação). */
watch(() => props.currency, (c) => {
    displayCurrency.value = c;
}, { immediate: true });

/** Converte valor da moeda da sessão para a moeda de exibição usando rate_to_brl. */
const displayAmount = computed(() => {
    const list = currencyList.value;
    const amount = Number(props.amount);
    if (Number.isNaN(amount) || list.length === 0) return amount;
    const sessionCur = list.find((c) => c.code === props.currency);
    const displayCur = list.find((c) => c.code === displayCurrency.value);
    const rateSession = sessionCur ? Number(sessionCur.rate_to_brl) || 1 : 1;
    const rateDisplay = displayCur ? Number(displayCur.rate_to_brl) || 1 : 1;
    const amountBrl = amount / rateSession;
    return Math.round(amountBrl * rateDisplay * 100) / 100;
});

const amountFormatted = computed(() => formatPrice(displayAmount.value, displayCurrency.value));

function setDisplayCurrency(code) {
    if (currencyList.value.some((c) => c.code === code)) {
        displayCurrency.value = code;
    }
}

const exchangeRateText = computed(() => {
    const list = currencyList.value;
    if (list.length < 2) return null;
    const usd = list.find((c) => c.code === 'USD');
    if (usd && usd.rate_to_brl && Number(usd.rate_to_brl) > 0) {
        const oneUsdInBrl = (1 / Number(usd.rate_to_brl)).toFixed(4);
        return `1 USD = ${oneUsdInBrl} BRL. As cobranças podem variar com base nas taxas de câmbio.`;
    }
    return null;
});

const pixForm = useForm({
    session_token: props.session_token,
    payment_method: 'pix',
});
const pixAutoForm = useForm({
    session_token: props.session_token,
    payment_method: 'pix_auto',
});
const boletoForm = useForm({
    session_token: props.session_token,
    payment_method: 'boleto',
});

const error = ref(null);
function onError(errors) {
    error.value = errors.payment_method?.[0] || errors.payment_token?.[0] || errors.session_token?.[0] || 'Erro ao processar.';
}

const canPayWithCard = computed(() =>
    props.available_methods?.includes('card') && props.card_gateway_slug === 'stripe' && (props.card_stripe_publishable_key || '').trim() !== ''
);

/** Método selecionado para exibir o bloco de ação (pix, boleto, card ou null). */
const selectedMethod = ref(null);

const showCardForm = ref(false);
const stripeCardRef = ref(null);
const stripeInstance = ref(null);
const stripeCardElement = ref(null);
const cardHolderName = ref('');
const cardSubmitting = ref(false);

async function initStripeCard() {
    if (!props.card_stripe_publishable_key?.trim() || !stripeCardRef.value) return;
    try {
        const { loadStripe } = await import('@stripe/stripe-js');
        const stripe = await loadStripe(props.card_stripe_publishable_key.trim());
        if (!stripe) return;
        stripeInstance.value = stripe;
        const elements = stripe.elements();
        const cardElement = elements.create('card', {
            style: { base: { fontSize: '16px', color: '#1f2937' } },
            hidePostalCode: true,
            disableLink: !props.card_stripe_link_enabled,
        });
        cardElement.mount(stripeCardRef.value);
        stripeCardElement.value = cardElement;
    } catch (e) {
        console.warn('Stripe init failed', e);
    }
}

function destroyStripeCard() {
    if (stripeCardElement.value && stripeCardRef.value) {
        try { stripeCardElement.value.unmount(); } catch (_) {}
        stripeCardElement.value = null;
    }
    stripeInstance.value = null;
}

watch(showCardForm, (visible) => {
    if (visible && canPayWithCard.value) {
        setTimeout(() => initStripeCard(), 100);
    } else {
        destroyStripeCard();
    }
});

function selectMethod(method) {
    if (method === 'card') {
        selectedMethod.value = 'card';
        showCardForm.value = canPayWithCard.value;
    } else {
        showCardForm.value = false;
        selectedMethod.value = method;
    }
}

function clearSelectedMethod() {
    selectedMethod.value = null;
    showCardForm.value = false;
}

onMounted(() => {
    if (showCardForm.value && canPayWithCard.value) setTimeout(() => initStripeCard(), 100);
    const title = props.app_name ? `${props.app_name}` : 'Pagamento';
    document.title = title;
});
onBeforeUnmount(() => destroyStripeCard());

async function submitCard(ev) {
    ev.preventDefault();
    if (!stripeInstance.value || !stripeCardElement.value || cardSubmitting.value) return;
    error.value = null;
    const name = (cardHolderName.value || '').trim();
    if (!name) {
        error.value = 'Informe o nome impresso no cartão.';
        return;
    }
    cardSubmitting.value = true;
    try {
        const { error: stripeError, paymentMethod } = await stripeInstance.value.createPaymentMethod({
            type: 'card',
            card: stripeCardElement.value,
            billing_details: { name },
        });
        if (stripeError) {
            error.value = stripeError.message || 'Erro ao processar o cartão.';
            cardSubmitting.value = false;
            return;
        }
        router.post('/api-checkout/pay', {
            session_token: props.session_token,
            payment_method: 'card',
            payment_token: paymentMethod.id,
            card_mask: paymentMethod.card?.last4 ? `**** ${paymentMethod.card.last4}` : '',
        }, {
            preserveScroll: true,
            onError: (err) => {
                onError(err);
                cardSubmitting.value = false;
            },
            onFinish: () => { cardSubmitting.value = false; },
        });
    } catch (e) {
        error.value = e?.message || 'Erro ao processar o cartão.';
        cardSubmitting.value = false;
    }
}
</script>

<template>
    <Head>
        <title>{{ app_name ? `${app_name} – Pagamento` : 'Pagamento' }}</title>
    </Head>
    <div class="min-h-screen flex flex-col lg:flex-row lg:justify-center">
        <!-- Coluna esquerda: tema escuro (resumo + logo) – 50% e conteúdo alinhado à direita -->
        <aside
            class="w-full px-6 py-8 lg:w-1/2 lg:min-h-screen lg:px-10 lg:py-12 lg:flex lg:flex-col lg:items-end"
            :style="{ backgroundColor: app_sidebar_bg_color || '#18181b' }"
        >
            <div class="w-full max-w-md lg:ml-auto lg:mr-10 xl:mr-16 text-right">
                <a
                    v-if="return_url"
                    :href="return_url"
                    class="inline-flex items-center gap-2 text-sm font-medium text-zinc-400 transition hover:text-white ml-auto lg:ml-0"
                >
                    <ArrowLeft class="h-4 w-4" />
                    Voltar
                </a>
                <div class="mt-6 flex justify-end items-center gap-3">
                    <img
                        v-if="app_logo_url"
                        :src="app_logo_url"
                        :alt="app_name"
                        class="h-10 w-auto max-w-[160px] object-contain object-right"
                    />
                    <span v-else class="text-lg font-semibold text-white">{{ app_name || 'Checkout' }}</span>
                </div>
                <div class="mt-10">
                    <p class="text-sm font-medium uppercase tracking-wider text-zinc-500">{{ displayTitle }}</p>
                    <p class="mt-1 text-2xl font-bold text-white sm:text-3xl">{{ amountFormatted }}</p>
                    <!-- Seletor de moeda (troca a exibição do valor; cobrança permanece na moeda da sessão) -->
                    <div v-if="currencyList.length > 0" class="mt-4 flex justify-end gap-2">
                        <button
                            v-for="c in currencyList"
                            :key="c.code"
                            type="button"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-sm font-medium transition focus:outline-none focus:ring-2 focus:ring-white/40"
                            :class="displayCurrency === c.code
                                ? 'border-white/30 bg-white/10 text-emerald-400'
                                : 'border-zinc-600 text-zinc-400 hover:border-zinc-500 hover:text-zinc-300'"
                            @click="setDisplayCurrency(c.code)"
                        >
                            <span v-if="c.code === 'BRL'" class="text-base leading-none" aria-hidden="true">🇧🇷</span>
                            <span v-else-if="c.code === 'USD'" class="text-base leading-none" aria-hidden="true">🇺🇸</span>
                            <span v-else-if="c.code === 'EUR'" class="text-base leading-none" aria-hidden="true">🇪🇺</span>
                            {{ c.code }}
                        </button>
                    </div>
                    <p v-if="exchangeRateText" class="mt-2 text-xs text-zinc-500">{{ exchangeRateText }}</p>
                </div>
                <div class="mt-8 space-y-3 border-t border-zinc-700/80 pt-6">
                    <div class="flex justify-end gap-4 text-sm">
                        <span class="text-zinc-400">Subtotal</span>
                        <span class="font-medium text-white min-w-[6rem]">{{ amountFormatted }}</span>
                    </div>
                    <div class="flex justify-end gap-4 border-t border-zinc-700/50 pt-4 text-base font-bold">
                        <span class="text-white">Total devido hoje</span>
                        <span class="text-white min-w-[6rem]">{{ amountFormatted }}</span>
                    </div>
                </div>
                <div class="mt-8 flex justify-end">
                    <div class="flex items-center gap-2 rounded-lg bg-zinc-800/60 px-4 py-3 text-sm text-zinc-300 max-w-max">
                        <ShieldCheck class="h-5 w-5 shrink-0 text-emerald-500" aria-hidden="true" />
                        Pagamento processado de forma segura.
                    </div>
                </div>
            </div>
        </aside>

        <!-- Coluna direita: tema claro (formulário) – 50% -->
        <main class="w-full bg-white px-6 py-8 lg:w-1/2 lg:px-12 lg:py-12">
            <div class="mx-auto max-w-md">
                <!-- Flash messages -->
                <div
                    v-if="flashError || error"
                    class="mb-6 flex items-center gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800"
                    role="alert"
                >
                    <AlertCircle class="h-5 w-5 shrink-0 text-red-600" />
                    {{ flashError || error }}
                </div>
                <div
                    v-if="flashSuccess && !error && !flashError"
                    class="mb-6 flex items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"
                    role="status"
                >
                    <ShieldCheck class="h-5 w-5 shrink-0 text-emerald-600" />
                    {{ flashSuccess }}
                </div>

                <h1 class="text-xl font-bold tracking-tight text-zinc-900">Concluir pagamento</h1>

                <div v-if="customer_email" class="mt-6">
                    <label class="block text-sm font-medium text-zinc-700">E-mail</label>
                    <p class="mt-1 rounded-lg border border-zinc-200 bg-zinc-50/50 px-4 py-3 text-zinc-900">{{ customer_email }}</p>
                </div>

                <div class="mt-6">
                    <label class="block text-sm font-medium text-zinc-700">Pagamento</label>
                    <div class="mt-3 space-y-3">
                        <!-- Opções de método (quando nenhum expandido) -->
                        <template v-if="selectedMethod === null">
                            <button
                                v-if="available_methods.includes('pix')"
                                type="button"
                                class="flex w-full items-center justify-center gap-3 rounded-xl border-2 border-zinc-200 bg-white px-4 py-3.5 font-medium text-zinc-900 transition hover:border-emerald-500 hover:bg-emerald-50/50"
                                @click="selectMethod('pix')"
                            >
                                <QrCode class="h-5 w-5 shrink-0" />
                                <span>Pagar com PIX</span>
                            </button>
                            <button
                                v-if="available_methods.includes('pix_auto')"
                                type="button"
                                class="flex w-full items-center justify-center gap-3 rounded-xl border-2 border-zinc-200 bg-white px-4 py-3.5 font-medium text-zinc-900 transition hover:border-emerald-500 hover:bg-emerald-50/50"
                                @click="selectMethod('pix_auto')"
                            >
                                <QrCode class="h-5 w-5 shrink-0" />
                                <span>Pagar com PIX Automático</span>
                            </button>
                            <button
                                v-if="available_methods.includes('boleto')"
                                type="button"
                                class="flex w-full items-center justify-center gap-3 rounded-xl border-2 border-zinc-200 bg-white px-4 py-3.5 font-medium text-zinc-900 transition hover:border-emerald-500 hover:bg-emerald-50/50"
                                @click="selectMethod('boleto')"
                            >
                                <Barcode class="h-5 w-5 shrink-0" />
                                <span>Pagar com Boleto</span>
                            </button>
                            <button
                                v-if="available_methods.includes('card')"
                                type="button"
                                class="flex w-full items-center justify-center gap-3 rounded-xl border-2 border-zinc-200 bg-white px-4 py-3.5 font-medium text-zinc-900 transition hover:border-emerald-500 hover:bg-emerald-50/50"
                                @click="selectMethod('card')"
                            >
                                <CreditCard class="h-5 w-5 shrink-0" />
                                <span>Pagar com Cartão</span>
                            </button>
                        </template>

                        <!-- PIX: bloco com botão Gerar PIX -->
                        <template v-else-if="selectedMethod === 'pix'">
                            <div class="rounded-xl border-2 border-zinc-200 bg-zinc-50/30 p-4 space-y-4">
                                <p class="text-sm text-zinc-600">Clique abaixo para gerar o QR Code PIX. Você será redirecionado para a página de pagamento.</p>
                                <form @submit.prevent="pixForm.post('/api-checkout/pay', { preserveScroll: true, onError })">
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                            @click="clearSelectedMethod"
                                        >
                                            Voltar
                                        </button>
                                        <button
                                            type="submit"
                                            class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50 flex items-center justify-center gap-2"
                                            :disabled="pixForm.processing"
                                        >
                                            <QrCode class="h-5 w-5 shrink-0" />
                                            {{ pixForm.processing ? 'Gerando...' : 'Gerar PIX' }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </template>

                        <template v-else-if="selectedMethod === 'pix_auto'">
                            <div class="rounded-xl border-2 border-zinc-200 bg-zinc-50/30 p-4 space-y-4">
                                <p class="text-sm text-zinc-600">Clique abaixo para gerar o QR Code PIX. Você será redirecionado para a página de pagamento.</p>
                                <form @submit.prevent="pixAutoForm.post('/api-checkout/pay', { preserveScroll: true, onError })">
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                            @click="clearSelectedMethod"
                                        >
                                            Voltar
                                        </button>
                                        <button
                                            type="submit"
                                            class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50 flex items-center justify-center gap-2"
                                            :disabled="pixAutoForm.processing"
                                        >
                                            <QrCode class="h-5 w-5 shrink-0" />
                                            {{ pixAutoForm.processing ? 'Gerando...' : 'Gerar PIX' }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </template>

                        <!-- Boleto: bloco com botão Gerar boleto -->
                        <template v-else-if="selectedMethod === 'boleto'">
                            <div class="rounded-xl border-2 border-zinc-200 bg-zinc-50/30 p-4 space-y-4">
                                <p class="text-sm text-zinc-600">Clique abaixo para gerar o boleto. Você será redirecionado para a página com o código de barras e o link para download.</p>
                                <form @submit.prevent="boletoForm.post('/api-checkout/pay', { preserveScroll: true, onError })">
                                    <div class="flex gap-2">
                                        <button
                                            type="button"
                                            class="rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                            @click="clearSelectedMethod"
                                        >
                                            Voltar
                                        </button>
                                        <button
                                            type="submit"
                                            class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50 flex items-center justify-center gap-2"
                                            :disabled="boletoForm.processing"
                                        >
                                            <Barcode class="h-5 w-5 shrink-0" />
                                            {{ boletoForm.processing ? 'Gerando...' : 'Gerar boleto' }}
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </template>

                        <!-- Cartão: formulário (mantido como já estava) -->
                        <template v-else-if="selectedMethod === 'card'">
                            <form v-if="canPayWithCard" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/30 p-4" @submit.prevent="submitCard">
                                <div>
                                    <label for="card-holder-api" class="mb-2 block text-sm font-medium text-zinc-700">Nome no cartão</label>
                                    <input
                                        id="card-holder-api"
                                        v-model="cardHolderName"
                                        type="text"
                                        autocomplete="cc-name"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                        placeholder="Como está no cartão"
                                    />
                                </div>
                                <div>
                                    <label class="mb-2 block text-sm font-medium text-zinc-700">Dados do cartão</label>
                                    <div ref="stripeCardRef" class="rounded-lg border-2 border-zinc-200 bg-white px-4 py-3 min-h-[3.25rem]" />
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                        @click="clearSelectedMethod"
                                    >
                                        Voltar
                                    </button>
                                    <button
                                        type="submit"
                                        class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                        :disabled="cardSubmitting"
                                    >
                                        {{ cardSubmitting ? 'Processando...' : 'Pagar com cartão' }}
                                    </button>
                                </div>
                            </form>
                            <div v-else class="rounded-xl border-2 border-zinc-200 bg-zinc-50/30 p-4 space-y-4">
                                <p class="text-sm text-zinc-600">Cartão está indisponível no momento para este checkout.</p>
                                <button
                                    type="button"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                    @click="clearSelectedMethod"
                                >
                                    Voltar
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <p class="mt-8 text-center text-xs text-zinc-500">
                    Powered by Getfy
                </p>
            </div>
        </main>
    </div>
</template>
