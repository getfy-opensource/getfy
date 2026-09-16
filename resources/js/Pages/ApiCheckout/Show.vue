<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { Head, useForm, usePage, router } from '@inertiajs/vue3';
import { QrCode, Barcode, CreditCard, Receipt, ShieldCheck, AlertCircle, ArrowLeft } from 'lucide-vue-next';
import ConversionPixels from '@/components/checkout/ConversionPixels.vue';
import CajuPaySdkMount from '@/components/checkout/CajuPaySdkMount.vue';
import { getMethodCardComponent } from '@/components/checkout/gateways/registry.js';
import { useApiCajuPayCheckout } from '@/composables/useApiCajuPayCheckout.js';
import {
    API_CHECKOUT_PAGARME_TOKENIZE_FORM_ID,
    PAGARME_TOKENIZE_FORM_ACTION,
    loadPagarmeTokenizeScript,
    ensurePagarmeCheckoutInit,
    requestPagarmeTokenFromForm,
    resetPagarmeTokenizeScriptState,
} from '@/composables/usePagarmeTokenizecard.js';
import { registerPluginCheckoutComponents } from '@/composables/usePluginCheckoutRegistry';
import { usePluginComponentResolver } from '@/composables/usePluginComponentResolver';
import PluginRenderZone from '@/components/plugins/PluginRenderZone.vue';

defineOptions({ layout: null });

const props = defineProps({
    session_token: { type: String, required: true },
    app_name: { type: String, default: '' },
    app_logo_url: { type: String, default: null },
    app_sidebar_bg_color: { type: String, default: '#18181b' },
    conversion_pixels: { type: Object, default: () => ({}) },
    customer_email: { type: String, default: null },
    customer_name: { type: String, default: null },
    customer_cpf: { type: String, default: null },
    customer_phone: { type: String, default: null },
    amount: { type: Number, required: true },
    currency: { type: String, default: 'BRL' },
    currencies: { type: Array, default: () => [] },
    product_name: { type: String, default: null },
    product_image_url: { type: String, default: null },
    available_methods: { type: Array, default: () => [] },
    checkout_payment_methods: { type: Array, default: () => [] },
    return_url: { type: String, default: null },
    card_gateway_slug: { type: String, default: null },
    card_payee_code: { type: String, default: '' },
    card_efi_sandbox: { type: Boolean, default: false },
    card_stripe_publishable_key: { type: String, default: '' },
    card_stripe_sandbox: { type: Boolean, default: false },
    card_stripe_link_enabled: { type: Boolean, default: true },
    card_mercadopago_public_key: { type: String, default: '' },
    card_mercadopago_sandbox: { type: Boolean, default: false },
    card_pagarme_public_key: { type: String, default: '' },
    /** Base da API v5 para tokenização (igual config/services.pagarme.base_url). */
    card_pagarme_api_base_url: { type: String, default: 'https://api.pagar.me/core/v5' },
    card_installments_enabled: { type: Boolean, default: false },
    card_max_installments: { type: Number, default: 1 },
    commerce_checkout: { type: Boolean, default: false },
    commerce_line_items: { type: Array, default: () => [] },
    payment_summary: { type: Array, default: () => [] },
    checkout_extra: { type: Object, default: () => ({}) },
    plugin_checkout_extensions: { type: Array, default: () => [] },
});

const payUrl = computed(() => (props.commerce_checkout ? '/commerce/checkout/pay' : '/api-checkout/pay'));

const page = usePage();
const pluginPagesGlob = import.meta.glob('@/PluginPages/**/*.vue');
const { resolve: resolvePluginComponent } = usePluginComponentResolver(
    computed(() => page.props.plugin_ui),
    pluginPagesGlob,
);
const pluginCheckoutData = ref({});

function onPluginCheckoutUpdate(slug, data) {
    pluginCheckoutData.value = {
        ...pluginCheckoutData.value,
        [slug]: data,
    };
}

function pluginCheckoutPayload(extra = {}) {
    const payload = { ...extra };
    if (pluginCheckoutData.value && Object.keys(pluginCheckoutData.value).length > 0) {
        payload.plugin_checkout_data = JSON.stringify(pluginCheckoutData.value);
    }

    return payload;
}

function postPay(extra = {}, options = {}) {
    router.post(payUrl.value, pluginCheckoutPayload(extra), {
        preserveScroll: true,
        onError,
        ...options,
    });
}

function postFormWithPluginData(form, options = {}) {
    form.transform((data) => pluginCheckoutPayload(data)).post(payUrl.value, {
        preserveScroll: true,
        onError,
        ...options,
    });
}
const flashError = computed(() => page.props.flash?.error ?? null);
const flashSuccess = computed(() => page.props.flash?.success ?? null);

function formatPrice(value, code) {
    const n = Number(value);
    if (Number.isNaN(n)) return '';
    const locale = code === 'BRL' ? 'pt-BR' : code === 'EUR' ? 'de-DE' : 'en-US';
    return new Intl.NumberFormat(locale, { style: 'currency', currency: code }).format(n);
}

/** Pagar.me: titular só com letras e espaços (API rejeita números no nome). */
function getCsrfToken() {
    const match = typeof document !== 'undefined' && document.cookie ? document.cookie.match(/XSRF-TOKEN=([^;]+)/) : null;
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch (_) {}
    }
    return '';
}

function sanitizePagarmeHolderName(raw) {
    const s = String(raw || '')
        .normalize('NFD')
        .replace(/\p{M}/gu, '')
        .replace(/[^a-zA-Z\s]/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();

    return s.length >= 2 ? s : 'Cliente';
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

const hasPaymentSummary = computed(() =>
    Array.isArray(props.payment_summary) && props.payment_summary.length > 0
);

function summaryLineAmount(line) {
    const amount = Number(line?.amount ?? 0);
    return formatPrice(amount, props.currency);
}

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
    cpf: props.customer_cpf || '',
});
const boletoForm = useForm({
    session_token: props.session_token,
    payment_method: 'boleto',
});

const error = ref(null);
function onError(errors) {
    error.value = errors.payment_method?.[0] || errors.payment_token?.[0] || errors.cpf?.[0] || errors.session_token?.[0] || 'Erro ao processar.';
}

const canPayWithStripe = computed(() => props.card_gateway_slug === 'stripe' && (props.card_stripe_publishable_key || '').trim() !== '');
const canPayWithEfi = computed(() => props.card_gateway_slug === 'efi' && (props.card_payee_code || '').trim() !== '');
const canPayWithPagarme = computed(() => props.card_gateway_slug === 'pagarme' && (props.card_pagarme_public_key || '').trim() !== '');
const canPayWithMercadopago = computed(() => props.card_gateway_slug === 'mercadopago' && (props.card_mercadopago_public_key || '').trim() !== '');

const visiblePaymentMethods = computed(() => {
    const list = Array.isArray(props.checkout_payment_methods) && props.checkout_payment_methods.length > 0
        ? props.checkout_payment_methods
        : (props.available_methods || []).map((id) => ({ id, label: id, gateway_slug: props.card_gateway_slug }));
    // Doc Caju 07/09: não filtrar Apple/Google Pay por dispositivo — o botão nativo decide.
    return list;
});

const currentMethodEntry = computed(() =>
    visiblePaymentMethods.value.find((m) => m.id === selectedMethod.value) ?? null
);

const isCajuPaySdkMethod = computed(() => {
    const slug = (currentMethodEntry.value?.gateway_slug || '').toLowerCase();
    return slug === 'cajupay' && ['card', 'apple_pay', 'google_pay'].includes(selectedMethod.value);
});

const canPayWithTokenCard = computed(() =>
    selectedMethod.value === 'card'
    && !isCajuPaySdkMethod.value
    && (canPayWithStripe.value || canPayWithEfi.value || canPayWithPagarme.value)
);

const canPayWithCajuPaySdk = computed(() => isCajuPaySdkMethod.value);

const canPayWithCard = computed(() =>
    props.available_methods?.includes('card')
    && (canPayWithCajuPaySdk.value || canPayWithTokenCard.value || canPayWithMercadopago.value)
);

const cajupay = useApiCajuPayCheckout({
    sessionToken: computed(() => props.session_token),
    paymentMethod: computed(() => selectedMethod.value || 'card'),
    displayCurrency,
    customerEmail: computed(() => props.customer_email),
    customerName: computed(() => props.customer_name),
    customerCpf: computed(() => props.customer_cpf),
    customerPhone: computed(() => props.customer_phone),
    onError,
});

const {
    cajupayMountRef,
    cajupaySessionToken,
    cajupayError,
    cajupaySessionLoading,
    cardSubmitting: cajupaySubmitting,
    isWalletMethod,
    cajupayPayerReadyForPrime,
    cajupayInitialPayer,
    cajupaySyncPayer,
    resetCajuPaySession,
    scheduleEnsureCajuPaySession,
    submitCajuPaySdkFlow,
    beforeCajuPayWalletPrime,
    onCajuPayWalletPaymentCompleted,
    onCajuPayPaymentFailed,
} = cajupay;

/** Método selecionado para exibir o bloco de ação (pix, boleto, card ou null). */
const selectedMethod = ref(null);

const showCardForm = ref(false);
const stripeCardRef = ref(null);
const stripeInstance = ref(null);
const stripeCardElement = ref(null);
const cardHolderName = ref('');
const efiCardNumber = ref('');
const efiCardExp = ref('');
const efiCardCvv = ref('');
const selectedInstallments = ref(1);

const cardMaxInstallments = computed(() => Math.min(12, Math.max(1, props.card_max_installments || 1)));
const showCardInstallments = computed(() =>
    props.card_installments_enabled
    && cardMaxInstallments.value > 1
    && (canPayWithPagarme.value || canPayWithEfi.value)
);
const cardSubmitting = ref(false);

/** tokenizecard.js exige exp_month/exp_year no DOM; derivamos do mesmo campo MM/AAAA que o Efí. */
const pagarmeTokenizeExpMonthHidden = computed(() => {
    const d = (efiCardExp.value || '').replace(/\D/g, '');
    return d.slice(0, 2);
});
const pagarmeTokenizeExpYearHidden = computed(() => {
    const d = (efiCardExp.value || '').replace(/\D/g, '');
    return d.slice(2, 6);
});

const pagarmeTokenizeFormId = API_CHECKOUT_PAGARME_TOKENIZE_FORM_ID;

const efiCardNumberDigits = computed(() => (efiCardNumber.value || '').replace(/\D/g, '').slice(0, 19));

watch(
    () => (props.card_pagarme_public_key || '').trim(),
    (next, prev) => {
        if (prev && next !== prev) {
            resetPagarmeTokenizeScriptState();
        }
    }
);

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
    if (visible && canPayWithStripe.value) {
        setTimeout(() => initStripeCard(), 100);
    } else {
        destroyStripeCard();
    }
});

function selectMethod(method) {
    selectedMethod.value = method;
    const entry = visiblePaymentMethods.value.find((m) => m.id === method);
    const isCajupay = (entry?.gateway_slug || '').toLowerCase() === 'cajupay'
        && ['card', 'apple_pay', 'google_pay'].includes(method);
    showCardForm.value = method === 'card' && !isCajupay && (canPayWithStripe.value || canPayWithEfi.value || canPayWithPagarme.value || canPayWithMercadopago.value);
    if (isCajupay) {
        resetCajuPaySession();
        scheduleEnsureCajuPaySession();
    } else {
        resetCajuPaySession();
        destroyMercadopagoBrick();
        if (method === 'card' && canPayWithMercadopago.value) {
            nextTick(() => loadMercadopagoBrick().catch((e) => {
                mercadopagoBrickError.value = e?.message || 'Falha ao carregar Mercado Pago.';
            }));
        }
    }
}

function clearSelectedMethod() {
    selectedMethod.value = null;
    showCardForm.value = false;
    resetCajuPaySession();
    destroyMercadopagoBrick();
    destroyStripeCard();
}

function methodButtonLabel(method) {
    const labels = {
        pix: 'Pagar com PIX',
        pix_auto: 'Pagar com PIX Automático',
        boleto: 'Pagar com Boleto',
        card: 'Pagar com Cartão',
        apple_pay: 'Apple Pay',
        google_pay: 'Google Pay',
    };
    return labels[method.id] || method.label || method.id;
}

function submitPixAuto() {
    error.value = null;
    const cpfDigits = (pixAutoForm.cpf || '').replace(/\D/g, '');
    if (cpfDigits.length !== 11) {
        error.value = 'Informe um CPF válido.';
        return;
    }
    pixAutoForm.cpf = cpfDigits;
    postFormWithPluginData(pixAutoForm);
}

onMounted(() => {
    if (showCardForm.value && canPayWithStripe.value) setTimeout(() => initStripeCard(), 100);
    const title = props.app_name ? `${props.app_name}` : 'Pagamento';
    document.title = title;
    const pluginUi = page.props.plugin_ui;
    if (pluginUi) {
        registerPluginCheckoutComponents(pluginUi, props.checkout_payment_methods || []);
    }
});
onBeforeUnmount(() => destroyStripeCard());

const mercadopagoBrickContainer = ref(null);
const mercadopagoBrickController = ref(null);
const mercadopagoBrickReady = ref(false);
const mercadopagoBrickError = ref('');

function destroyMercadopagoBrick() {
    try {
        mercadopagoBrickController.value?.unmount?.();
    } catch (_) {}
    mercadopagoBrickController.value = null;
    mercadopagoBrickReady.value = false;
    mercadopagoBrickError.value = '';
}

async function initMercadopagoBrick() {
    const mp = new window.MercadoPago(props.card_mercadopago_public_key.trim(), { locale: 'pt-BR' });
    const bricksBuilder = mp.bricks();
    const amount = Math.max(0.01, Number(displayAmount.value) || 0);
    const settings = {
        initialization: {
            amount,
            payer: {
                email: props.customer_email || undefined,
                firstName: (props.customer_name || '').trim().split(/\s+/)[0] || undefined,
            },
        },
        callbacks: {
            onReady: () => {
                mercadopagoBrickReady.value = true;
            },
            onSubmit: (param) => {
                const formData = param?.formData ?? param;
                if (!formData || typeof formData !== 'object') {
                    error.value = 'Dados do cartão inválidos.';
                    return Promise.reject();
                }
                return new Promise((resolve, reject) => {
                    cardSubmitting.value = true;
                    error.value = '';
                    postPay({
                        session_token: props.session_token,
                        payment_method: 'card',
                        payment_token: JSON.stringify(formData),
                    }, {
                        onError: (err) => {
                            onError(err);
                            reject();
                        },
                        onFinish: () => {
                            cardSubmitting.value = false;
                            resolve();
                        },
                    });
                });
            },
            onError: (err) => {
                mercadopagoBrickError.value = err?.message || 'Erro no formulário de pagamento.';
            },
        },
    };
    mercadopagoBrickController.value = await bricksBuilder.create('cardPayment', 'api_cardPaymentBrick_container', settings);
}

async function loadMercadopagoBrick() {
    mercadopagoBrickError.value = '';
    if (!props.card_mercadopago_public_key?.trim() || !mercadopagoBrickContainer.value) {
        return;
    }
    destroyMercadopagoBrick();
    if (typeof window !== 'undefined' && window.MercadoPago) {
        await initMercadopagoBrick();
        return;
    }
    await new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://sdk.mercadopago.com/js/v2';
        script.async = true;
        script.onload = () => initMercadopagoBrick().then(resolve).catch(reject);
        script.onerror = () => reject(new Error('Falha ao carregar Mercado Pago.'));
        document.head.appendChild(script);
    });
}

onBeforeUnmount(() => {
    destroyStripeCard();
    destroyMercadopagoBrick();
});

async function submitCard(ev) {
    ev.preventDefault();
    if (cardSubmitting.value) return;
    error.value = null;
    const name = (cardHolderName.value || '').trim();
    if (!name) {
        error.value = 'Informe o nome impresso no cartão.';
        return;
    }
    cardSubmitting.value = true;
    try {
        if (canPayWithStripe.value) {
            if (!stripeInstance.value || !stripeCardElement.value) {
                error.value = 'Aguarde o formulário do cartão carregar.';
                cardSubmitting.value = false;
                return;
            }
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
            postPay({
                session_token: props.session_token,
                payment_method: 'card',
                payment_token: paymentMethod.id,
                card_mask: paymentMethod.card?.last4 ? `**** ${paymentMethod.card.last4}` : '',
            }, {
                onError: (err) => {
                    onError(err);
                    cardSubmitting.value = false;
                },
                onFinish: () => { cardSubmitting.value = false; },
            });
            return;
        }

        if (canPayWithPagarme.value) {
            const numberDigits = efiCardNumberDigits.value;
            const expDigits = (efiCardExp.value || '').replace(/\D/g, '');
            const month = expDigits.slice(0, 2);
            let yearRaw = expDigits.slice(2);
            if (yearRaw.length === 2) {
                yearRaw = `20${yearRaw}`;
            }
            const cvv = (efiCardCvv.value || '').replace(/\D/g, '').slice(0, 4);
            const monthNum = parseInt(month, 10);
            const expYearNum = parseInt(yearRaw, 10);
            if (month.length !== 2 || yearRaw.length !== 4 || !Number.isFinite(monthNum) || monthNum < 1 || monthNum > 12
                || !Number.isFinite(expYearNum)) {
                error.value = 'Informe a validade no formato MM/AAAA (como no cartão Efí).';
                cardSubmitting.value = false;
                return;
            }
            if (numberDigits.length < 13 || numberDigits.length > 19 || cvv.length < 3) {
                error.value = 'Preencha todos os dados do cartão corretamente.';
                cardSubmitting.value = false;
                return;
            }
            const pk = (props.card_pagarme_public_key || '').trim();
            const holderName = sanitizePagarmeHolderName(name);
            let tokenId;
            await nextTick();
            try {
                await loadPagarmeTokenizeScript(pk);
                ensurePagarmeCheckoutInit();
                const { token } = await requestPagarmeTokenFromForm(pagarmeTokenizeFormId);
                tokenId = token;
            } catch {
                const base = String(props.card_pagarme_api_base_url || 'https://api.pagar.me/core/v5').replace(/\/$/, '');
                const url = `${base}/tokens?appId=${encodeURIComponent(pk)}`;
                const res = await fetch(url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                    body: JSON.stringify({
                        type: 'card',
                        card: {
                            number: numberDigits,
                            holder_name: holderName,
                            exp_month: monthNum,
                            exp_year: expYearNum,
                            cvv: String(cvv),
                        },
                    }),
                });
                let data = {};
                try {
                    data = await res.json();
                } catch {
                    data = {};
                }
                if (!res.ok) {
                    let m = typeof data?.message === 'string' ? data.message : 'Não foi possível tokenizar o cartão.';
                    if (/n[uú]mero do cart[aã]o|cart[aã]o inv[aá]lido|invalid card/i.test(m)) {
                        m += ' Sandbox: Visa 4000000000000010, validade futura, CVV 123, chaves pk_test_/sk_test_ na mesma conta.';
                    }
                    error.value = m;
                    cardSubmitting.value = false;
                    return;
                }
                tokenId = data?.id;
                if (!tokenId || typeof tokenId !== 'string') {
                    error.value = 'Resposta inválida da Pagar.me.';
                    cardSubmitting.value = false;
                    return;
                }
            }
            const last4 = numberDigits.slice(-4);
            const installments = props.card_installments_enabled
                ? Math.min(cardMaxInstallments.value, Math.max(1, selectedInstallments.value))
                : 1;
            postPay({
                session_token: props.session_token,
                payment_method: 'card',
                payment_token: JSON.stringify({ card_token: tokenId, installments }),
                card_mask: last4 ? `**** ${last4}` : '',
            }, {
                onError: (err) => {
                    onError(err);
                    cardSubmitting.value = false;
                },
                onFinish: () => { cardSubmitting.value = false; },
            });
            return;
        }

        if (canPayWithEfi.value) {
            const numberDigits = (efiCardNumber.value || '').replace(/\D/g, '');
            const expDigits = (efiCardExp.value || '').replace(/\D/g, '');
            const month = expDigits.slice(0, 2);
            const yearRaw = expDigits.slice(2);
            const year = yearRaw.length === 2 ? `20${yearRaw}` : yearRaw;
            const cvv = (efiCardCvv.value || '').replace(/\D/g, '').slice(0, 4);
            if (numberDigits.length < 13 || numberDigits.length > 16 || month.length !== 2 || year.length !== 4 || cvv.length < 3) {
                error.value = 'Preencha todos os dados do cartão corretamente.';
                cardSubmitting.value = false;
                return;
            }
            const EfiPay = (await import('payment-token-efi')).default;
            const env = props.card_efi_sandbox ? 'sandbox' : 'production';
            const instance = EfiPay.CreditCard.setAccount((props.card_payee_code || '').trim()).setEnvironment(env);
            instance.setCardNumber(numberDigits);
            const brand = await instance.verifyCardBrand();
            if (!brand || brand === 'unsupported') {
                error.value = 'Bandeira do cartão não suportada.';
                cardSubmitting.value = false;
                return;
            }
            instance.setCreditCardData({
                brand,
                number: numberDigits,
                cvv,
                expirationMonth: month,
                expirationYear: year,
                reuse: false,
                holderName: name || undefined,
                holderDocument: (props.customer_cpf || '').replace(/\D/g, '') || undefined,
            });
            const result = await instance.getPaymentToken();
            const paymentToken = result?.payment_token;
            if (!paymentToken) {
                error.value = 'Não foi possível gerar o token do cartão.';
                cardSubmitting.value = false;
                return;
            }
            const last4 = numberDigits.slice(-4);
            postPay({
                session_token: props.session_token,
                payment_method: 'card',
                payment_token: paymentToken,
                card_mask: result?.card_mask || (last4 ? `**** ${last4}` : ''),
            }, {
                onError: (err) => {
                    onError(err);
                    cardSubmitting.value = false;
                },
                onFinish: () => { cardSubmitting.value = false; },
            });
            return;
        }
    } catch (e) {
        error.value = e?.message || 'Erro ao processar o cartão.';
        cardSubmitting.value = false;
    }
}
</script>

<template>
    <ConversionPixels :pixels="props.conversion_pixels" />
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
                    <template v-if="hasPaymentSummary">
                        <div
                            v-for="(line, idx) in payment_summary"
                            :key="idx"
                            class="flex justify-end gap-4 text-sm"
                            :class="line.highlight ? 'border-t border-zinc-700/50 pt-4 text-base font-bold' : ''"
                        >
                            <span :class="line.highlight ? 'text-white' : 'text-zinc-400'">{{ line.label }}</span>
                            <span :class="line.highlight ? 'text-white' : 'font-medium text-white'" class="min-w-[6rem]">{{ summaryLineAmount(line) }}</span>
                        </div>
                    </template>
                    <template v-else>
                        <div class="flex justify-end gap-4 text-sm">
                            <span class="text-zinc-400">Subtotal</span>
                            <span class="font-medium text-white min-w-[6rem]">{{ amountFormatted }}</span>
                        </div>
                        <div class="flex justify-end gap-4 border-t border-zinc-700/50 pt-4 text-base font-bold">
                            <span class="text-white">Total devido hoje</span>
                            <span class="text-white min-w-[6rem]">{{ amountFormatted }}</span>
                        </div>
                    </template>
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

                <div
                    v-if="plugin_checkout_extensions?.length"
                    class="mt-6 space-y-4"
                    data-checkout="plugin-extensions"
                >
                    <template v-for="(ext, idx) in plugin_checkout_extensions" :key="ext.id || idx">
                        <component
                            v-if="resolvePluginComponent(ext)"
                            :is="resolvePluginComponent(ext)"
                            :format-price="formatPrice"
                            :display-currency="displayCurrency"
                            @update:model-value="onPluginCheckoutUpdate(ext.plugin_slug, $event)"
                        />
                    </template>
                </div>
                <PluginRenderZone zone="checkout.commerce.after_extensions" class="mt-6" />

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
                                v-for="m in visiblePaymentMethods"
                                :key="m.id"
                                type="button"
                                class="flex w-full items-center justify-center gap-3 rounded-xl border-2 border-zinc-200 bg-white px-4 py-3.5 font-medium text-zinc-900 transition hover:border-emerald-500 hover:bg-emerald-50/50"
                                @click="selectMethod(m.id)"
                            >
                                <component :is="getMethodCardComponent(m)" class="h-5 w-5 shrink-0" />
                                <span>{{ methodButtonLabel(m) }}</span>
                            </button>
                        </template>

                        <!-- PIX: bloco com botão Gerar PIX -->
                        <template v-else-if="selectedMethod === 'pix'">
                            <div class="rounded-xl border-2 border-zinc-200 bg-zinc-50/30 p-4 space-y-4">
                                <p class="text-sm text-zinc-600">Clique abaixo para gerar o QR Code PIX. Você será redirecionado para a página de pagamento.</p>
                                <form @submit.prevent="postFormWithPluginData(pixForm)">
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
                                <div>
                                    <label for="cpf-pix-auto" class="mb-2 block text-sm font-medium text-zinc-700">CPF</label>
                                    <input
                                        id="cpf-pix-auto"
                                        v-model="pixAutoForm.cpf"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="off"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                        placeholder="000.000.000-00"
                                    />
                                </div>
                                <form @submit.prevent="submitPixAuto">
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
                                <form @submit.prevent="postFormWithPluginData(boletoForm)">
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

                        <!-- CajuPay SDK: cartão embed, Apple Pay, Google Pay -->
                        <template v-else-if="canPayWithCajuPaySdk">
                            <div class="space-y-3 rounded-xl border border-zinc-200 bg-white p-3 sm:space-y-4 sm:border-2 sm:bg-zinc-50/30 sm:p-4">
                                <p v-if="cajupayError" class="text-sm text-red-600" role="alert">{{ cajupayError }}</p>
                                <div
                                    v-if="!cajupaySessionToken && cajupaySessionLoading"
                                    class="h-32 animate-pulse rounded-lg bg-zinc-100"
                                    aria-hidden="true"
                                />
                                <CajuPaySdkMount
                                    ref="cajupayMountRef"
                                    :payment-method="selectedMethod"
                                    :session-token="cajupaySessionToken"
                                    :initial-payer="cajupayInitialPayer"
                                    :sync-payer="cajupaySyncPayer"
                                    container-id="api-cajupay-method"
                                    :before-wallet-prime="beforeCajuPayWalletPrime"
                                    :payer-ready-for-prime="cajupayPayerReadyForPrime"
                                    @wallet-payment-completed="onCajuPayWalletPaymentCompleted"
                                    @payment-failed="onCajuPayPaymentFailed"
                                />
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        class="rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                        @click="clearSelectedMethod"
                                    >
                                        Voltar
                                    </button>
                                    <button
                                        v-if="!isWalletMethod"
                                        type="button"
                                        class="flex-1 rounded-lg bg-emerald-600 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:opacity-50"
                                        :disabled="cajupaySubmitting || cajupaySessionLoading"
                                        @click="submitCajuPaySdkFlow"
                                    >
                                        {{ cajupaySubmitting ? 'Processando...' : 'Pagar' }}
                                    </button>
                                </div>
                                <p v-if="isWalletMethod" class="text-xs text-zinc-500">
                                    Use o botão da carteira digital acima para concluir o pagamento.
                                </p>
                            </div>
                        </template>

                        <!-- Cartão: tokenização Stripe / Efí / Pagar.me / Mercado Pago -->
                        <template v-else-if="selectedMethod === 'card'">
                            <div v-if="canPayWithMercadopago" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/30 p-4">
                                <p v-if="mercadopagoBrickError" class="text-sm text-red-600">{{ mercadopagoBrickError }}</p>
                                <div ref="mercadopagoBrickContainer" id="api_cardPaymentBrick_container" class="min-h-[280px]" />
                                <button
                                    type="button"
                                    class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:bg-zinc-50"
                                    @click="clearSelectedMethod"
                                >
                                    Voltar
                                </button>
                            </div>
                            <form v-else-if="canPayWithTokenCard" class="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/30 p-4" @submit.prevent="submitCard">
                                <div>
                                    <label for="card-holder-api" class="mb-2 block text-sm font-medium text-zinc-700">Nome no cartão</label>
                                    <input
                                        id="card-holder-api"
                                        v-model="cardHolderName"
                                        type="text"
                                        autocomplete="cc-name"
                                        :form="canPayWithPagarme ? pagarmeTokenizeFormId : undefined"
                                        :data-pagarmecheckout-element="canPayWithPagarme ? 'holder_name' : undefined"
                                        :name="canPayWithPagarme ? 'api_pagarme_holder_name' : undefined"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                        placeholder="Como está no cartão"
                                    />
                                </div>
                                <template v-if="canPayWithPagarme">
                                    <div class="relative">
                                        <label for="card-number-api-pagarme" class="mb-2 block text-sm font-medium text-zinc-700">Número do cartão</label>
                                        <input
                                            id="card-number-api-pagarme"
                                            v-model="efiCardNumber"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="cc-number"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                            placeholder="0000 0000 0000 0000"
                                        />
                                        <input
                                            type="text"
                                            readonly
                                            tabindex="-1"
                                            autocomplete="off"
                                            :value="efiCardNumberDigits"
                                            :form="pagarmeTokenizeFormId"
                                            data-pagarmecheckout-element="number"
                                            name="api_pagarme_number"
                                            class="absolute h-px w-px overflow-hidden border-0 p-0 opacity-0"
                                            aria-hidden="true"
                                        />
                                        <input
                                            type="text"
                                            readonly
                                            tabindex="-1"
                                            autocomplete="off"
                                            :value="pagarmeTokenizeExpMonthHidden"
                                            :form="pagarmeTokenizeFormId"
                                            data-pagarmecheckout-element="exp_month"
                                            name="api_pagarme_exp_month"
                                            class="absolute left-0 top-0 h-px w-px overflow-hidden border-0 p-0 opacity-0"
                                            aria-hidden="true"
                                        />
                                        <input
                                            type="text"
                                            readonly
                                            tabindex="-1"
                                            autocomplete="off"
                                            :value="pagarmeTokenizeExpYearHidden"
                                            :form="pagarmeTokenizeFormId"
                                            data-pagarmecheckout-element="exp_year"
                                            name="api_pagarme_exp_year"
                                            class="absolute left-0 top-0 h-px w-px overflow-hidden border-0 p-0 opacity-0"
                                            aria-hidden="true"
                                        />
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label for="card-exp-pagarme-api" class="mb-2 block text-sm font-medium text-zinc-700">Validade (MM/AAAA)</label>
                                            <input
                                                id="card-exp-pagarme-api"
                                                v-model="efiCardExp"
                                                type="text"
                                                inputmode="numeric"
                                                autocomplete="cc-exp"
                                                class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                                placeholder="MM/AAAA"
                                            />
                                        </div>
                                        <div>
                                            <label for="card-cvv-pagarme-api" class="mb-2 block text-sm font-medium text-zinc-700">CVV</label>
                                            <input
                                                id="card-cvv-pagarme-api"
                                                v-model="efiCardCvv"
                                                type="text"
                                                inputmode="numeric"
                                                autocomplete="cc-csc"
                                                maxlength="4"
                                                :form="pagarmeTokenizeFormId"
                                                data-pagarmecheckout-element="cvv"
                                                name="api_pagarme_cvv"
                                                class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                                placeholder="123"
                                            />
                                        </div>
                                    </div>
                                </template>
                                <template v-else-if="canPayWithEfi">
                                    <div>
                                        <label for="card-number-efi" class="mb-2 block text-sm font-medium text-zinc-700">Número do cartão</label>
                                        <input
                                            id="card-number-efi"
                                            v-model="efiCardNumber"
                                            type="text"
                                            inputmode="numeric"
                                            autocomplete="cc-number"
                                            class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                            placeholder="0000 0000 0000 0000"
                                        />
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label for="card-exp-efi" class="mb-2 block text-sm font-medium text-zinc-700">Validade (MM/AAAA)</label>
                                            <input
                                                id="card-exp-efi"
                                                v-model="efiCardExp"
                                                type="text"
                                                inputmode="numeric"
                                                autocomplete="cc-exp"
                                                class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                                placeholder="MM/AAAA"
                                            />
                                        </div>
                                        <div>
                                            <label for="card-cvv-efi" class="mb-2 block text-sm font-medium text-zinc-700">CVV</label>
                                            <input
                                                id="card-cvv-efi"
                                                v-model="efiCardCvv"
                                                type="password"
                                                inputmode="numeric"
                                                autocomplete="cc-csc"
                                                class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                                placeholder="123"
                                            />
                                        </div>
                                    </div>
                                </template>
                                <template v-else-if="canPayWithStripe">
                                    <div class="space-y-2">
                                        <label class="block text-sm font-medium text-zinc-700">Dados do cartão</label>
                                        <div ref="stripeCardRef" class="rounded-lg border-2 border-zinc-200 bg-white px-4 py-3 min-h-[3.25rem]" />
                                    </div>
                                </template>
                                <div
                                    v-if="showCardInstallments"
                                    class="space-y-2"
                                >
                                    <label for="api-installments-select" class="block text-sm font-medium text-zinc-700">Parcelas</label>
                                    <select
                                        id="api-installments-select"
                                        v-model.number="selectedInstallments"
                                        class="w-full rounded-lg border border-zinc-300 bg-white px-4 py-3 text-base text-zinc-900 shadow-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500"
                                    >
                                        <option
                                            v-for="n in cardMaxInstallments"
                                            :key="n"
                                            :value="n"
                                        >
                                            {{ n }}x de {{ formatPrice(amount / n, currency) }}
                                        </option>
                                    </select>
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
                                <p class="text-sm text-zinc-600">Este método de cartão não está disponível no momento.</p>
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
        <form
            v-if="canPayWithPagarme && (card_pagarme_public_key || '').trim()"
            :id="pagarmeTokenizeFormId"
            method="post"
            :action="PAGARME_TOKENIZE_FORM_ACTION"
            data-pagarmecheckout-form
            class="sr-only"
            aria-hidden="true"
            @submit.prevent
        >
            <input type="hidden" name="_token" :value="getCsrfToken()" />
            <span data-pagarmecheckout-element="brand" class="hidden" aria-hidden="true" />
        </form>
    </div>
</template>
