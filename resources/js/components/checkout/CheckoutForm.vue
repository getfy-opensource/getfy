<script setup>
import { ref, computed, watch, onMounted, onBeforeUnmount, nextTick } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import axios from 'axios';
import { User, UserRound, Mail, ShoppingBag, Loader2, CreditCard, Tag, Check, Pencil, ScanQrCode, Shield, X, AlertCircle, FileText, MapPin } from 'lucide-vue-next';
import CheckoutDropdown from './CheckoutDropdown.vue';
import CheckoutOrderBumps from './CheckoutOrderBumps.vue';
import CheckoutPaymentMethods from './CheckoutPaymentMethods.vue';
import AsaasCard from './gateways/asaas/Card.vue';

const STORAGE_KEY = 'checkout_draft';

function getCsrfToken() {
    const match = typeof document !== 'undefined' && document.cookie ? document.cookie.match(/XSRF-TOKEN=([^;]+)/) : null;
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch (_) {}
    }
    return '';
}

const EMAIL_PROVIDERS = [
    '@gmail.com',
    '@hotmail.com',
    '@outlook.com',
    '@yahoo.com.br',
    '@icloud.com',
    '@live.com.br',
    '@bol.com.br',
    '@uol.com.br',
];

const props = defineProps({
    productId: { type: [Number, String], required: true },
    productOfferId: { type: Number, default: null },
    subscriptionPlanId: { type: Number, default: null },
    checkoutSessionToken: { type: String, default: '' },
    orderBumps: { type: Array, default: () => [] },
    orderBumpIds: { type: Array, default: () => [] },
    primaryColor: { type: String, default: '#7427F1' },
    formatPrice: { type: Function, default: (v, c) => `R$ ${Number(v).toFixed(2)}` },
    config: { type: Object, default: () => ({}) },
    /** Métodos disponíveis: [{ id: 'pix'|'card'|'boleto', label: string, gateway_name?: string }] */
    availablePaymentMethods: { type: Array, default: () => [] },
    /** Preenchido quando o usuário aceita o cupom no exit popup (mostra o campo e preenche) */
    prefillCoupon: { type: String, default: '' },
    t: { type: Function, default: (k) => k },
    displayCurrency: { type: String, default: 'BRL' },
    /** Código do país (ISO) detectado por geo para pré-selecionar o DDI do telefone */
    suggestedCountryCode: { type: String, default: null },
    /** Payee code Efí para tokenização de cartão (obrigatório quando payment_method === 'card' com gateway efi). */
    cardPayeeCode: { type: String, default: '' },
    /** Se o gateway Efí está em homologação: token deve ser gerado com setEnvironment('sandbox'). */
    cardEfiSandbox: { type: Boolean, default: false },
    /** Publishable Key Stripe para tokenização de cartão (quando gateway cartão é stripe). */
    cardStripePublishableKey: { type: String, default: '' },
    /** Se o gateway Stripe está em ambiente de teste. */
    cardStripeSandbox: { type: Boolean, default: false },
    /** Se o Stripe Link está habilitado no Card Element (disableLink = !value). */
    cardStripeLinkEnabled: { type: Boolean, default: true },
    cardInstallmentsEnabled: { type: Boolean, default: false },
    cardMaxInstallments: { type: Number, default: 1 },
    checkoutTotalBrl: { type: Number, default: 0 },
    /** Public Key Mercado Pago para Payment Brick (cartão). */
    cardMercadopagoPublicKey: { type: String, default: '' },
    /** Se o gateway Mercado Pago está em sandbox. */
    cardMercadopagoSandbox: { type: Boolean, default: false },
    /** Chaves por gateway slug para gateways de plugin (checkout_payload_keys). Ex.: { 'meu-gateway': { publishable_key: '...' } } */
    cardGatewayKeys: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['coupon-applied', 'coupon-cleared', 'update:orderBumpIds']);

const customerFields = computed(() => props.config?.customer_fields ?? { name: true, cpf: true, phone: true, coupon: false });
const showName = computed(() => customerFields.value.name !== false);
const showCpf = computed(() => customerFields.value.cpf === true && props.displayCurrency === 'BRL');
const showPhone = computed(() => customerFields.value.phone === true);
const showCouponByConfig = computed(() => customerFields.value.coupon === true);
const showCouponField = computed(() => showCouponByConfig.value || Boolean(props.prefillCoupon));
const orderBumpColor = computed(() => props.config?.appearance?.order_bump_color || '#F59E0B');
const footerConfig = computed(() => props.config?.footer ?? {});
const footerEnabled = computed(() => footerConfig.value?.enabled === true);
const footerLogoUrl = computed(() => String(footerConfig.value?.logo_url ?? '').trim());
const footerText = computed(() => String(footerConfig.value?.text ?? '').trim());
const footerSupportEmail = computed(() => String(footerConfig.value?.support_email ?? '').trim());
const showFooterCustom = computed(
    () => footerEnabled.value && (footerLogoUrl.value !== '' || footerText.value !== '' || footerSupportEmail.value !== '')
);

/** Gateway do método cartão (primeiro método com id === 'card' em available_payment_methods). */
const cardGatewaySlug = computed(() => {
    const methods = Array.isArray(props.availablePaymentMethods) ? props.availablePaymentMethods : [];
    const cardMethod = methods.find((m) => m.id === 'card');
    return (cardMethod?.gateway_slug || '').toLowerCase();
});
const isCardGatewayStripe = computed(() => cardGatewaySlug.value === 'stripe');
const isCardGatewayEfi = computed(() => cardGatewaySlug.value === 'efi');
const isCardGatewayMercadopago = computed(() => cardGatewaySlug.value === 'mercadopago');
const isCardGatewayAsaas = computed(() => cardGatewaySlug.value === 'asaas');

const countryCodes = [
    { code: '55', country: 'BR', label: 'Brasil', flag: '🇧🇷' },
    { code: '1', country: 'US', label: 'EUA', flag: '🇺🇸' },
    { code: '351', country: 'PT', label: 'Portugal', flag: '🇵🇹' },
    { code: '54', country: 'AR', label: 'Argentina', flag: '🇦🇷' },
    { code: '52', country: 'MX', label: 'México', flag: '🇲🇽' },
    { code: '57', country: 'CO', label: 'Colômbia', flag: '🇨🇴' },
    { code: '34', country: 'ES', label: 'Espanha', flag: '🇪🇸' },
    { code: '39', country: 'IT', label: 'Itália', flag: '🇮🇹' },
    { code: '33', country: 'FR', label: 'França', flag: '🇫🇷' },
    { code: '49', country: 'DE', label: 'Alemanha', flag: '🇩🇪' },
    { code: '44', country: 'GB', label: 'Reino Unido', flag: '🇬🇧' },
    { code: '81', country: 'JP', label: 'Japão', flag: '🇯🇵' },
];

function getDefaultCountryCode() {
    const suggested = (props.suggestedCountryCode || '').toUpperCase();
    if (!suggested) return '55';
    const found = countryCodes.find((c) => c.country === suggested);
    return found ? found.code : '55';
}

const form = useForm({
    product_id: props.productId,
    payment_method: 'manual',
    email: '',
    name: '',
    cpf: '',
    phone: '',
    country_code: getDefaultCountryCode(),
    coupon_code: '',
    address_zipcode: '',
    address_street: '',
    address_number: '',
    address_neighborhood: '',
    address_city: '',
    address_state: '',
});

watch(
    () => props.availablePaymentMethods,
    (list) => {
        const methods = Array.isArray(list) ? list : [];
        if (methods.length > 0 && (form.payment_method === 'manual' || !form.payment_method)) {
            form.payment_method = methods[0].id;
        }
    },
    { immediate: true }
);
const phoneDigits = ref('');
const phoneCountryOpen = ref(false);

const showEmailDropdown = ref(false);
const emailDropdownCloseTimer = ref(null);
const filteredEmailSuggestions = computed(() => {
    const email = (form.email || '').trim();
    const atIdx = email.indexOf('@');
    if (atIdx >= 0 && email.slice(atIdx + 1).length > 0) return [];
    return EMAIL_PROVIDERS;
});
const shouldShowEmailDropdown = computed(() => {
    const email = (form.email || '').trim();
    if (!showEmailDropdown.value || email.length === 0) return false;
    const atIdx = email.indexOf('@');
    const hasCharAfterAt = atIdx >= 0 && email.slice(atIdx + 1).length > 0;
    return !hasCharAfterAt;
});
function openEmailDropdown() {
    if (emailDropdownCloseTimer.value) {
        clearTimeout(emailDropdownCloseTimer.value);
        emailDropdownCloseTimer.value = null;
    }
    const email = (form.email || '').trim();
    const atIdx = email.indexOf('@');
    if (atIdx >= 0 && email.slice(atIdx + 1).length > 0) {
        showEmailDropdown.value = false;
        return;
    }
    showEmailDropdown.value = true;
}
function scheduleCloseEmailDropdown() {
    emailDropdownCloseTimer.value = setTimeout(() => {
        showEmailDropdown.value = false;
        emailDropdownCloseTimer.value = null;
    }, 200);
}
function selectEmailSuggestion(provider) {
    const current = form.email || '';
    const beforeAt = current.includes('@') ? current.split('@')[0] : current;
    form.email = beforeAt + provider;
    showEmailDropdown.value = false;
}

const cpfDisplay = ref('');
function formatCpf(value) {
    const digits = (value || '').replace(/\D/g, '').slice(0, 11);
    if (digits.length <= 3) return digits;
    if (digits.length <= 6) return `${digits.slice(0, 3)}.${digits.slice(3)}`;
    return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9)}`;
}
function onCpfInput(e) {
    const digits = (e.target.value || '').replace(/\D/g, '').slice(0, 11);
    cpfDisplay.value = formatCpf(digits);
    form.cpf = digits;
}

const phoneDisplay = ref('');
function formatPhone(value, countryCode) {
    const d = (value || '').replace(/\D/g, '').slice(0, 15);
    if (countryCode === '55') {
        if (d.length === 0) return '';
        if (d.length <= 2) return `(${d}${d.length < 2 ? '' : ') '}`;
        return `(${d.slice(0, 2)}) ${d.slice(2, 7)}${d.length > 7 ? '-' : ''}${d.slice(7, 11)}`;
    }
    return d.replace(/(\d{2})(?=\d)/g, '$1 ').trim();
}
function onPhoneInput(e) {
    const digits = (e.target.value || '').replace(/\D/g, '').slice(0, 15);
    phoneDigits.value = digits;
    phoneDisplay.value = formatPhone(digits, form.country_code);
}
watch(
    () => form.country_code,
    () => {
        phoneDisplay.value = formatPhone(phoneDigits.value, form.country_code);
    }
);
watch(
    () => props.prefillCoupon,
    (code) => {
        if (code) form.coupon_code = code;
    },
    { immediate: true }
);

// Persistência dos dados no localStorage e modo resumo (pronto para pagar / editar dados)
const showEditForm = ref(true);
let saveDraftTimeout = null;

function loadDraft() {
    try {
        const raw = typeof localStorage !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
        if (!raw) return;
        const draft = JSON.parse(raw);
        if (!draft || typeof draft !== 'object') return;
        if (draft.email) form.email = draft.email;
        if (draft.name != null) form.name = draft.name;
        if (draft.cpf != null) {
            form.cpf = String(draft.cpf).replace(/\D/g, '').slice(0, 11);
            cpfDisplay.value = formatCpf(form.cpf);
        }
        if (draft.phone_digits != null) {
            phoneDigits.value = String(draft.phone_digits).replace(/\D/g, '').slice(0, 15);
            phoneDisplay.value = formatPhone(phoneDigits.value, draft.country_code || form.country_code);
        }
        if (draft.country_code) form.country_code = draft.country_code;
        if (draft.email && draft.email.trim() !== '') showEditForm.value = false;
    } catch (_) {}
}

function saveDraft() {
    if (saveDraftTimeout) clearTimeout(saveDraftTimeout);
    saveDraftTimeout = setTimeout(() => {
        try {
            const email = (form.email || '').trim();
            if (!email) return;
            const draft = {
                email,
                name: (form.name || '').trim(),
                cpf: (form.cpf || '').replace(/\D/g, ''),
                phone_digits: phoneDigits.value || '',
                country_code: form.country_code || '55',
            };
            if (typeof localStorage !== 'undefined') localStorage.setItem(STORAGE_KEY, JSON.stringify(draft));
        } catch (_) {}
    }, 400);
}

onMounted(() => {
    loadDraft();
    // Não forçar showEditForm = true aqui: o watch em form.payment_method já abre o form quando o usuário escolhe PIX/Boleto.
    // Se forçássemos aqui, ao carregar com draft salvo + primeiro método = boleto/pix, os dados "fixos" e o botão Editar dados nunca apareceriam.
});

watch(
    () => [form.email, form.name, form.cpf, form.country_code, phoneDigits.value],
    () => saveDraft(),
    { deep: true }
);

let trackTimeout = null;
const trackStepSent = ref({ form_started: false, form_filled: false });
function callTrackApi(step, email, name) {
    if (!props.checkoutSessionToken) return;
    if (trackStepSent.value[step]) return;
    trackStepSent.value[step] = true;
    if (trackTimeout) clearTimeout(trackTimeout);
    trackTimeout = setTimeout(async () => {
        try {
            await axios.post('/api/checkout/track', {
                session_token: props.checkoutSessionToken,
                step,
                email: email || undefined,
                name: name || undefined,
            });
        } catch (_) {
            trackStepSent.value[step] = false;
        }
        trackTimeout = null;
    }, 500);
}
watch(
    () => [form.email, form.name],
    () => {
        const email = (form.email || '').trim();
        const name = (form.name || '').trim();
        const hasEmail = email.length > 0 && email.includes('@');
        const hasName = name.length > 0;
        if (hasEmail && !trackStepSent.value.form_started) {
            callTrackApi('form_started', email, name);
        }
        const needsName = (props.config?.customer_fields?.name ?? true) !== false;
        if (hasEmail && (!needsName || hasName) && !trackStepSent.value.form_filled) {
            callTrackApi('form_filled', email, name);
        }
    },
    { deep: true }
);

// PIX/Boleto: abrir formulário para preencher endereço/nome/CPF. PIX automático: se dados já preenchidos, manter resumo (não mostrar inputs). Cartão/outros: colapsar para resumo se dados já preenchidos.
watch(
    () => form.payment_method,
    (method) => {
        if (method === 'pix_auto') {
            const emailOk = (form.email || '').trim().length > 0 && (form.email || '').includes('@');
            const nameOk = (form.name || '').trim().length > 0;
            const cpfOk = !showCpf.value || ((form.cpf || '').replace(/\D/g, '').length >= 11);
            if (emailOk && nameOk && cpfOk) {
                showEditForm.value = false;
            } else {
                showEditForm.value = true;
            }
        } else if (method === 'pix' || method === 'boleto') {
            showEditForm.value = true;
        } else if ((form.email || '').trim().length > 0 && (form.email || '').includes('@')) {
            showEditForm.value = false;
        }
    }
);
watch(
    () => Object.keys(form.errors || {}).length,
    (count) => {
        if (count > 0 && (form.payment_method === 'pix' || form.payment_method === 'pix_auto' || form.payment_method === 'boleto')) {
            showEditForm.value = true;
        }
    }
);

const couponValidationError = ref('');
const couponValidating = ref(false);
let couponValidateTimeout = null;
watch(
    () => (form.coupon_code || '').trim(),
    (code) => {
        couponValidationError.value = '';
        if (couponValidateTimeout) clearTimeout(couponValidateTimeout);
        if (!code) {
            emit('coupon-cleared');
            return;
        }
        couponValidateTimeout = setTimeout(async () => {
            couponValidating.value = true;
            try {
                const body = {
                    product_id: props.productId,
                    coupon_code: code,
                };
                if (props.productOfferId) body.product_offer_id = props.productOfferId;
                if (props.subscriptionPlanId) body.subscription_plan_id = props.subscriptionPlanId;
                const { data } = await axios.post('/checkout/validate-coupon', body);
                if (data.valid) {
                    emit('coupon-applied', {
                        discount_amount: data.discount_amount,
                        final_price: data.final_price,
                    });
                } else {
                    couponValidationError.value = data.message || 'Cupom inválido.';
                    emit('coupon-cleared');
                }
            } catch (err) {
                const msg = err.response?.data?.message ?? err.response?.data?.errors?.coupon_code?.[0] ?? 'Não foi possível validar o cupom.';
                couponValidationError.value = msg;
                emit('coupon-cleared');
            } finally {
                couponValidating.value = false;
            }
        }, 400);
    }
);
const flagImgUrl = (code) => `https://flagcdn.com/24x18/${(code || 'br').toLowerCase()}.png`;
const currentCountry = computed(() => countryCodes.find((c) => c.code === form.country_code)?.country ?? 'BR');
const currentCountryOption = computed(() => countryCodes.find((c) => c.code === form.country_code) || countryCodes[0]);

function selectPhoneCountry(c) {
    form.country_code = c.code;
    phoneCountryOpen.value = false;
}

const addressCepLoading = ref(false);
const boletoAddressFetched = computed(() => !!(form.address_street || '').trim());
function onAddressCepInput(e) {
    const digits = (e.target.value || '').replace(/\D/g, '').slice(0, 8);
    form.address_zipcode = digits.length > 5 ? `${digits.slice(0, 5)}-${digits.slice(5)}` : digits;
}
async function fetchAddressByCep() {
    const cep = (form.address_zipcode || '').replace(/\D/g, '').slice(0, 8);
    if (cep.length < 8) return;
    addressCepLoading.value = true;
    try {
        const { data } = await axios.get(`https://viacep.com.br/ws/${cep}/json/`, { timeout: 8000 });
        if (data?.erro) return;
        if (data?.logradouro) form.address_street = data.logradouro;
        if (data?.bairro) form.address_neighborhood = data.bairro;
        if (data?.localidade) form.address_city = data.localidade;
        if (data?.uf) form.address_state = data.uf;
    } catch (_) {}
    addressCepLoading.value = false;
}

const inputClass =
    'block w-full rounded-xl border-2 border-gray-100 bg-gray-50/80 px-4 py-3.5 pl-12 text-sm font-medium text-gray-900 placeholder-gray-400 transition focus:border-gray-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-offset-0';
const inputClassWithIcon = inputClass;

// Desktop: nome e email só → email full. Telefone ativo → email | telefone. CPF ativo (sem telefone) → email | cpf. Telefone e CPF ativos → email full, depois telefone | cpf.
const emailColSpan = computed(() => {
    if (showPhone.value && showCpf.value) return 'sm:col-span-2';
    if (showPhone.value || showCpf.value) return 'sm:col-span-1';
    return 'sm:col-span-2';
});

const phoneInputClass =
    'w-full min-w-0 rounded-xl border-0 bg-transparent py-3.5 pr-4 pl-2 text-sm font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0';
const phoneWrapperClass =
    'flex items-stretch overflow-hidden rounded-xl border-2 border-gray-100 bg-gray-50/80 transition focus-within:border-gray-200 focus-within:bg-white focus-within:ring-2 focus-within:ring-offset-0';
const phoneSelectClass =
    'absolute left-0 top-0 h-full w-12 cursor-pointer border-0 bg-transparent py-0 pl-0 opacity-0 focus:outline-none focus:ring-0';
const phoneFlagWrapClass = 'relative flex h-10 w-12 shrink-0 items-center justify-center self-center';

// Bandeiras de cartão: prefixos BIN para detecção (ordem: prefixos mais longos primeiro)
const CARD_BRANDS = [
    { name: 'Elo', slug: 'elo', prefixes: ['636368', '636297', '636269', '438935', '504175', '451416', '627780', '5067', '4576', '4011', '506', '509', '636', '6500', '6504', '6505', '6507', '6509', '6516', '6550'] },
    { name: 'Hipercard', slug: 'hipercard', prefixes: ['606282', '3841', '60', '38'] },
    { name: 'Hiper', slug: 'hiper', prefixes: ['637095', '637599', '637609', '637612', '637600', '637568', '637'] },
    { name: 'American Express', slug: 'amex', prefixes: ['34', '37'] },
    { name: 'Diners Club', slug: 'diners', prefixes: ['300', '301', '302', '303', '304', '305', '36', '39'] },
    { name: 'Discover', slug: 'discover', prefixes: ['6011', '644', '645', '646', '647', '648', '649', '65'] },
    { name: 'JCB', slug: 'jcb', prefixes: ['3528', '3529', '3530', '3531', '3532', '3533', '3534', '3535', '3536', '3537', '3538', '3539', '3540', '3541', '3542', '3543', '3544', '3545', '3546', '3547', '3548', '3549', '3550', '3551', '3552', '3553', '3554', '3555', '3556', '3557', '3558', '3559', '3560', '3561', '3562', '3563', '3564', '3565', '3566', '3567', '3568', '3569', '3570', '3571', '3572', '3573', '3574', '3575', '3576', '3577', '3578', '3579', '3580', '3581', '3582', '3583', '3584', '3585', '3586', '3587', '3588', '3589'] },
    { name: 'Aura', slug: 'aura', prefixes: ['50'] },
    { name: 'MasterCard', slug: 'mastercard', prefixes: ['2221', '2222', '2223', '2224', '2225', '2226', '2227', '2228', '2229', '223', '224', '225', '226', '227', '228', '229', '23', '24', '25', '26', '27', '2720', '51', '52', '53', '54', '55'] },
    { name: 'Visa', slug: 'visa', prefixes: ['4'] },
];

function getCardBrandFromNumber(digits) {
    if (!digits || digits.length < 2) return null;
    const normalized = String(digits).replace(/\D/g, '');
    for (const brand of CARD_BRANDS) {
        for (const prefix of brand.prefixes) {
            if (normalized.startsWith(prefix)) return brand;
        }
    }
    return null;
}

// Dados de cartão: apenas refs locais, NUNCA em draft/localStorage
const cardHolderName = ref('');
const cardNumberDisplay = ref('');
const cardNumberDigits = ref('');
const cardExpMonth = ref('');
const cardExpYear = ref('');
const cardCvv = ref('');
const cardFormError = ref('');
const cardTokenizing = ref(false);
const cardApproved = ref(false);
const cardApprovedRedirectUrl = ref('');
const showCardRefusedModal = ref(false);
const cardRefusedMessage = ref('');
const cardNumberInput = ref(null);
const cardExpMonthInput = ref(null);
const cardExpYearInput = ref(null);
const cardCvvInput = ref(null);
const showFullCardNumber = ref(true);
const selectedInstallments = ref(1);
const asaasCardStep = ref(1);
const asaasCardData = ref({});
const asaasAddressData = ref({});

// Stripe Elements (quando gateway cartão é Stripe)
const stripeCardRef = ref(null);
const stripeInstance = ref(null);
const stripeCardElement = ref(null);
const stripeElements = ref(null);

// Mercado Pago Card Payment Brick (cartão)
const mercadopagoBrickContainer = ref(null);
const mercadopagoBrickController = ref(null);
const mercadopagoBrickReady = ref(false);
const mercadopagoBrickError = ref('');

const cardBrandFromNumber = computed(() => getCardBrandFromNumber(cardNumberDigits.value));
const cardBrandImage = computed(() => {
    const brand = cardBrandFromNumber.value;
    return brand ? `/images/gateways/cards/${brand.slug}.svg` : '/images/gateways/card.png';
});

const cardNumberComplete = computed(() => cardNumberDigits.value.length === 16);
const cardNumberMasked = computed(() => {
    const d = cardNumberDigits.value;
    if (d.length < 4) return '';
    return d.slice(-4);
});

function onCardNumberInput(e) {
    const v = (e.target.value || '').replace(/\D/g, '').slice(0, 16);
    cardNumberDigits.value = v;
    const parts = [];
    for (let i = 0; i < v.length; i += 4) parts.push(v.slice(i, i + 4));
    cardNumberDisplay.value = parts.join(' ');
    if (v.length === 16) {
        showFullCardNumber.value = false;
        nextTick(() => {
            if (cardExpMonthInput.value) cardExpMonthInput.value.focus();
        });
    }
}
function reopenCardNumberEdit() {
    showFullCardNumber.value = true;
    nextTick(() => {
        cardNumberInput.value?.focus();
    });
}
function onCardNumberBlur() {
    if (cardNumberDigits.value.length === 16) showFullCardNumber.value = false;
}
function onCardExpInput(e, part) {
    const v = (e.target.value || '').replace(/\D/g, '');
    if (part === 'month') {
        const m = v.slice(0, 2);
        if (m.length === 1 && parseInt(m, 10) > 1) {
            cardExpMonth.value = '0' + m;
        } else {
            cardExpMonth.value = m.slice(0, 2);
        }
        if (cardExpMonth.value.length === 2) {
            nextTick(() => {
                if (cardExpYearInput.value) cardExpYearInput.value.focus();
            });
        }
    } else {
        cardExpYear.value = v.slice(0, 4);
        if (cardExpYear.value.length >= 2) {
            nextTick(() => {
                if (cardCvvInput.value) cardCvvInput.value.focus();
            });
        }
    }
}
function onCardCvvInput(e) {
    cardCvv.value = (e.target.value || '').replace(/\D/g, '').slice(0, 3);
}

// Limpar erro de cartão ao trocar método ou editar campos
watch(
    () => form.payment_method,
    () => { cardFormError.value = ''; }
);
watch(
    () => [cardHolderName.value, cardNumberDigits.value, cardExpMonth.value, cardExpYear.value, cardCvv.value],
    () => { cardFormError.value = ''; },
    { deep: true }
);

watch(
    () => [form.payment_method, isCardGatewayStripe.value, props.cardStripePublishableKey, props.cardStripeLinkEnabled],
    async ([method, isStripe]) => {
        if (method !== 'card' || !isStripe) {
            destroyStripeCardElement();
            return;
        }
        await nextTick();
        initStripeCardElement();
    },
    { immediate: true }
);

watch(
    () => [form.payment_method, isCardGatewayMercadopago.value, props.cardMercadopagoPublicKey, props.checkoutTotalBrl],
    async ([method, isMP]) => {
        if (method !== 'card' || !isMP) {
            destroyMercadopagoBrick();
            return;
        }
        await nextTick();
        loadMercadopagoBrick().catch((err) => {
            mercadopagoBrickError.value = err?.message || 'Não foi possível carregar o formulário de pagamento. Verifique sua conexão e tente recarregar.';
        });
    },
    { immediate: true }
);

onBeforeUnmount(() => {
    destroyMercadopagoBrick();
});

function closeRefusedModal() {
    showCardRefusedModal.value = false;
    cardRefusedMessage.value = '';
}
function onRefusedTryOtherCard(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    showCardRefusedModal.value = false;
    cardRefusedMessage.value = '';
    cardFormError.value = '';
    showFullCardNumber.value = true;
}
function onRefusedOtherPaymentMethod(e) {
    if (e) {
        e.preventDefault();
        e.stopPropagation();
    }
    showCardRefusedModal.value = false;
    cardRefusedMessage.value = '';
    const other = props.availablePaymentMethods.find((m) => m.id !== 'card');
    if (other) form.payment_method = other.id;
}

async function initStripeCardElement() {
    if (!props.cardStripePublishableKey?.trim() || !stripeCardRef.value) return;
    try {
        const { loadStripe } = await import('@stripe/stripe-js');
        const stripe = await loadStripe(props.cardStripePublishableKey.trim());
        if (!stripe) return;
        stripeInstance.value = stripe;
        const elements = stripe.elements();
        stripeElements.value = elements;
        const cardElement = elements.create('card', {
            style: { base: { fontSize: '16px', color: '#1f2937' } },
            hidePostalCode: true,
            disableLink: !props.cardStripeLinkEnabled,
        });
        cardElement.mount(stripeCardRef.value);
        stripeCardElement.value = cardElement;
    } catch (e) {
        console.warn('Stripe init failed', e);
    }
}

function destroyStripeCardElement() {
    if (stripeCardElement.value && stripeCardRef.value) {
        try {
            stripeCardElement.value.unmount();
        } catch (_) {}
        stripeCardElement.value = null;
    }
    stripeElements.value = null;
    stripeInstance.value = null;
}

function destroyMercadopagoBrick() {
    try {
        const ctrl = mercadopagoBrickController.value ?? (typeof window !== 'undefined' ? window.cardPaymentBrickController : null);
        if (ctrl?.unmount) ctrl.unmount();
    } catch (_) {}
    mercadopagoBrickController.value = null;
    mercadopagoBrickReady.value = false;
    mercadopagoBrickError.value = '';
    if (typeof window !== 'undefined') window.cardPaymentBrickController = null;
}

function submitCardWithMercadopagoFormData(formData) {
    const payload = {
        product_id: form.product_id,
        payment_method: 'card',
        email: form.email,
        name: showName.value ? form.name : '',
        cpf: showCpf.value ? (form.cpf || '').replace(/\D/g, '') : '',
        phone: showPhone.value ? form.country_code + phoneDigits.value : '',
        coupon_code: (form.coupon_code || '').trim() || null,
        payment_token: JSON.stringify(formData),
        installments: 1,
    };
    if (props.productOfferId) payload.product_offer_id = props.productOfferId;
    if (props.subscriptionPlanId) payload.subscription_plan_id = props.subscriptionPlanId;
    if (props.checkoutSessionToken) payload.checkout_session_token = props.checkoutSessionToken;
    if (props.displayCurrency) payload.display_currency = props.displayCurrency;
    if (Array.isArray(props.orderBumpIds) && props.orderBumpIds.length > 0) {
        payload.order_bump_ids = props.orderBumpIds.map((id) => (typeof id === 'number' ? id : parseInt(id, 10))).filter((n) => !Number.isNaN(n));
    }
    return axios.post('/checkout', payload, {
        headers: {
            'Accept': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': getCsrfToken(),
        },
        withCredentials: true,
    });
}

async function loadMercadopagoBrick() {
    mercadopagoBrickError.value = '';
    if (!props.cardMercadopagoPublicKey?.trim() || !mercadopagoBrickContainer.value) return;
    destroyMercadopagoBrick();
    return new Promise((resolve, reject) => {
        if (typeof window !== 'undefined' && window.MercadoPago) {
            initMercadopagoBrick().then(resolve).catch(reject);
            return;
        }
        const script = document.createElement('script');
        script.src = 'https://sdk.mercadopago.com/js/v2';
        script.async = true;
        script.onload = () => {
            initMercadopagoBrick().then(resolve).catch(reject);
        };
        script.onerror = () => reject(new Error('Falha ao carregar Mercado Pago.'));
        document.head.appendChild(script);
    });
}

async function initMercadopagoBrick() {
    const mp = new window.MercadoPago(props.cardMercadopagoPublicKey.trim(), { locale: 'pt-BR' });
    const bricksBuilder = mp.bricks();
    const amount = Math.max(0.01, Number(props.checkoutTotalBrl) || 0);
    const email = (form.email || '').trim() || undefined;
    const nameParts = (form.name || '').trim().split(/\s+/);
    const settings = {
        initialization: {
            amount,
            payer: {
                email,
                firstName: nameParts[0] || undefined,
                lastName: nameParts.slice(1).join(' ') || undefined,
            },
        },
        callbacks: {
            onReady: () => {
                mercadopagoBrickReady.value = true;
            },
            onSubmit: (param) => {
                const formData = param?.formData ?? param;
                if (!formData || typeof formData !== 'object') {
                    cardFormError.value = 'Dados do cartão inválidos.';
                    return Promise.reject();
                }
                return new Promise((resolve, reject) => {
                    cardTokenizing.value = true;
                    cardFormError.value = '';
                    submitCardWithMercadopagoFormData(formData)
                        .then(async (res) => {
                            const data = res?.data;
                            const isJson = data && typeof data === 'object' && !Array.isArray(data);
                            if (isJson && data.success) {
                                const url = data.redirect_url;
                                if (url) {
                                    cardApproved.value = true;
                                    setTimeout(() => router.visit(url), 800);
                                }
                                resolve();
                            } else {
                                reject();
                            }
                        })
                        .catch((err) => {
                            const msg = err?.response?.data?.message || err?.message || 'Não foi possível processar o pagamento.';
                            cardFormError.value = typeof msg === 'string' ? msg : 'Não foi possível processar o pagamento.';
                            reject();
                        })
                        .finally(() => {
                            cardTokenizing.value = false;
                        });
                });
            },
            onError: (err) => {
                const msg = err?.message || 'Erro no formulário de pagamento.';
                cardFormError.value = msg;
                mercadopagoBrickError.value = msg;
            },
        },
    };
    const controller = await bricksBuilder.create('cardPayment', 'cardPaymentBrick_container', settings);
    mercadopagoBrickController.value = controller;
    if (typeof window !== 'undefined') window.cardPaymentBrickController = controller;
}

/** Carrega o SDK do Mercado Pago (para tokenização no checkout transparente). */
function loadMercadoPagoScript() {
    if (typeof window !== 'undefined' && window.MercadoPago) {
        return Promise.resolve();
    }
    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://sdk.mercadopago.com/js/v2';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Falha ao carregar Mercado Pago.'));
        document.head.appendChild(script);
    });
}

/** Mapeia slug da bandeira (do nosso getCardBrandFromNumber) para payment_method_id do MP. */
function mpPaymentMethodIdFromSlug(slug) {
    const map = { visa: 'visa', mastercard: 'master', amex: 'amex', elo: 'elo', hipercard: 'hipercard', aura: 'aura' };
    return map[slug] || slug || 'visa';
}

/** Checkout transparente MP: gera token do cartão e retorna payment_token no formato esperado pelo backend (JSON com token, payment_method_id, payer, installments). */
async function getMercadoPagoCardToken() {
    const publicKey = (props.cardMercadopagoPublicKey || '').trim();
    if (!publicKey) throw new Error('Mercado Pago não configurado (Public Key).');

    await loadMercadoPagoScript();
    const mp = new window.MercadoPago(publicKey, { locale: 'pt-BR' });

    const cardNumber = cardNumberDigits.value.replace(/\D/g, '');
    const month = cardExpMonth.value.padStart(2, '0');
    const year = cardExpYear.value.length === 2 ? '20' + cardExpYear.value : cardExpYear.value;
    const securityCode = cardCvv.value;
    const cardholderName = (cardHolderName.value || form.name || '').trim() || 'Titular';

    let tokenId = null;
    if (typeof mp.createCardToken === 'function') {
        try {
            const result = await mp.createCardToken({
                cardNumber,
                cardExpirationMonth: month,
                cardExpirationYear: year,
                securityCode,
                cardholderName,
            });
            tokenId = result?.id ?? result?.token ?? null;
        } catch (_) {}
    }
    if (!tokenId) {
        const res = await fetch(
            `https://api.mercadopago.com/v1/card_tokens?public_key=${encodeURIComponent(publicKey)}`,
            {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    card_number: cardNumber,
                    expiration_month: month,
                    expiration_year: year,
                    security_code: securityCode,
                    cardholder_name: cardholderName,
                }),
            }
        );
        const data = await res.json();
        if (!res.ok) {
            const msg = data?.message || data?.cause?.[0]?.description || 'Não foi possível validar o cartão.';
            throw new Error(msg);
        }
        tokenId = data.id;
    }
    if (!tokenId) throw new Error('Não foi possível gerar o token do cartão.');

    const brand = getCardBrandFromNumber(cardNumberDigits.value);
    const paymentMethodId = brand ? mpPaymentMethodIdFromSlug(brand.slug) : 'visa';

    const nameParts = ((form.name || '') || cardholderName).trim().split(/\s+/);
    const firstName = nameParts[0] || 'Nome';
    const lastName = nameParts.slice(1).join(' ') || ' ';
    const doc = (form.cpf || '').replace(/\D/g, '');
    const payer = {
        email: (form.email || '').trim(),
        first_name: firstName,
        last_name: lastName,
    };
    if (doc.length >= 11) {
        payer.identification = { type: doc.length === 11 ? 'CPF' : 'CNPJ', number: doc };
    }
    const formData = {
        token: tokenId,
        payment_method_id: paymentMethodId,
        installments: Math.min(props.cardMaxInstallments || 1, Math.max(1, selectedInstallments.value)),
        payer,
    };

    const last4 = cardNumber.slice(-4);
    return { payment_token: JSON.stringify(formData), card_mask: last4 ? `****${last4}` : '' };
}

async function getStripePaymentMethod() {
    if (!stripeInstance.value || !stripeCardElement.value) throw new Error('Stripe não está pronto. Recarregue a página.');
    const name = (cardHolderName.value || form.name || '').trim() || undefined;
    const { error, paymentMethod } = await stripeInstance.value.createPaymentMethod({
        type: 'card',
        card: stripeCardElement.value,
        billing_details: { name },
    });
    if (error) throw new Error(error.message || 'Não foi possível validar o cartão.');
    if (!paymentMethod?.id) throw new Error('Não foi possível obter o método de pagamento.');
    return { payment_token: paymentMethod.id, card_mask: paymentMethod.card?.last4 ? `****${paymentMethod.card.last4}` : '' };
}

async function getEfiPaymentToken() {
    const EfiPay = (await import('payment-token-efi')).default;
    const env = props.cardEfiSandbox ? 'sandbox' : 'production';
    const instance = EfiPay.CreditCard.setAccount(props.cardPayeeCode.trim()).setEnvironment(env);
    instance.setCardNumber(cardNumberDigits.value);
    const brand = await instance.verifyCardBrand();
    if (!brand || brand === 'unsupported') {
        throw new Error('Bandeira do cartão não suportada.');
    }
    instance.setCreditCardData({
        brand,
        number: cardNumberDigits.value,
        cvv: cardCvv.value,
        expirationMonth: cardExpMonth.value.padStart(2, '0'),
        expirationYear: cardExpYear.value.length === 2 ? '20' + cardExpYear.value : cardExpYear.value,
        reuse: false,
        holderName: (cardHolderName.value || form.name || '').trim() || undefined,
        holderDocument: (form.cpf || '').replace(/\D/g, '') || undefined,
    });
    const result = await instance.getPaymentToken();
    if (result && typeof result === 'object' && result.payment_token) {
        return { payment_token: result.payment_token, card_mask: result.card_mask || '' };
    }
    throw new Error('Não foi possível gerar o token do cartão.');
}

function submit() {
    const methods = Array.isArray(props.availablePaymentMethods) ? props.availablePaymentMethods : [];
    const paymentMethod = methods.some((m) => m.id === form.payment_method) ? form.payment_method : (methods[0]?.id ?? 'manual');

    if (paymentMethod === 'card') {
        cardFormError.value = '';
        if (isCardGatewayAsaas.value) {
            if (asaasCardStep.value === 1) {
                asaasCardStep.value = 2;
                return;
            }
            const card = asaasCardData.value;
            const addr = asaasAddressData.value;
            const nameOk = (card?.card_holder_name || '').trim().length >= 3;
            const numberOk = (card?.card_number || '').replace(/\D/g, '').length >= 13;
            const expOk = (card?.card_expiry_month || '').length === 2 && (card?.card_expiry_year || '').length >= 2;
            const cvvOk = (card?.card_ccv || '').length >= 3;
            const zipOk = (addr?.address_zipcode || '').replace(/\D/g, '').length >= 8;
            const streetOk = (addr?.address_street || '').trim().length >= 2;
            const numOk = (addr?.address_number || '').trim().length >= 1;
            const cityOk = (addr?.address_city || '').trim().length >= 2;
            const stateOk = (addr?.address_state || '').trim().length === 2;
            if (!nameOk || !numberOk || !expOk || !cvvOk) {
                cardFormError.value = props.t('checkout.card_fill_all') || 'Preencha todos os dados do cartão.';
                return;
            }
            if (!zipOk || !streetOk || !numOk || !cityOk || !stateOk) {
                cardFormError.value = 'Preencha o endereço completo (CEP, rua, número, cidade e UF).';
                return;
            }
            cardTokenizing.value = true;
            cardFormError.value = '';
            const payload = {
                product_id: form.product_id,
                payment_method: 'card',
                email: form.email,
                name: showName.value ? form.name : '',
                cpf: showCpf.value ? (form.cpf || '').replace(/\D/g, '') : '',
                phone: showPhone.value ? form.country_code + phoneDigits.value : '',
                coupon_code: (form.coupon_code || '').trim() || null,
                card_holder_name: (card?.card_holder_name || '').trim(),
                card_number: (card?.card_number || '').replace(/\D/g, ''),
                card_expiry_month: (card?.card_expiry_month || '').replace(/\D/g, '').slice(0, 2),
                card_expiry_year: (card?.card_expiry_year || '').replace(/\D/g, '').slice(-4),
                card_ccv: (card?.card_ccv || '').replace(/\D/g, ''),
                installments: Math.min(props.cardMaxInstallments || 1, Math.max(1, card?.installments || 1)),
                address_zipcode: (addr?.address_zipcode || '').replace(/\D/g, ''),
                address_street: (addr?.address_street || '').trim(),
                address_number: (addr?.address_number || '').trim(),
                address_neighborhood: (addr?.address_neighborhood || '').trim(),
                address_city: (addr?.address_city || '').trim(),
                address_state: (addr?.address_state || '').trim().slice(0, 2).toUpperCase(),
            };
            if (props.productOfferId) payload.product_offer_id = props.productOfferId;
            if (props.subscriptionPlanId) payload.subscription_plan_id = props.subscriptionPlanId;
            if (props.checkoutSessionToken) payload.checkout_session_token = props.checkoutSessionToken;
            if (props.displayCurrency) payload.display_currency = props.displayCurrency;
            if (Array.isArray(props.orderBumpIds) && props.orderBumpIds.length > 0) {
                payload.order_bump_ids = props.orderBumpIds.map((id) => (typeof id === 'number' ? id : parseInt(id, 10))).filter((n) => !Number.isNaN(n));
            }
            axios.post('/checkout', payload, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': getCsrfToken() },
                withCredentials: true,
            })
                .then(async (res) => {
                    const data = res?.data;
                    const isJson = data && typeof data === 'object' && !Array.isArray(data);
                    if (isJson && data.success) {
                        const url = data.redirect_url;
                        if (url) {
                            cardApproved.value = true;
                            cardApprovedRedirectUrl.value = url;
                            setTimeout(() => router.visit(url), 1800);
                        }
                    }
                })
                .catch((err) => {
                    const msg = err?.response?.data?.message || err?.message || 'Não foi possível processar o pagamento.';
                    cardFormError.value = typeof msg === 'string' ? msg : 'Não foi possível processar o pagamento.';
                    showCardRefusedModal.value = true;
                    cardRefusedMessage.value = cardFormError.value;
                })
                .finally(() => { cardTokenizing.value = false; });
            return;
        }
        if (isCardGatewayMercadopago.value) {
            cardFormError.value = props.t('checkout.use_mercadopago_below') || 'Use o botão de pagamento do Mercado Pago abaixo.';
            return;
        }
        if (isCardGatewayStripe.value) {
            if (!props.cardStripePublishableKey || !props.cardStripePublishableKey.trim()) {
                cardFormError.value = props.t('checkout.card_not_configured') || 'Pagamento por cartão não está configurado.';
                return;
            }
            if (!stripeCardElement.value) {
                cardFormError.value = 'Aguarde o formulário do cartão carregar.';
                return;
            }
        } else {
            if (!props.cardPayeeCode || !props.cardPayeeCode.trim()) {
                cardFormError.value = props.t('checkout.card_not_configured') || 'Pagamento por cartão não está configurado.';
                return;
            }
            const nameOk = (cardHolderName.value || form.name || '').trim().length >= 3;
            const numberOk = cardNumberDigits.value.length >= 13 && cardNumberDigits.value.length <= 16;
            const expOk = cardExpMonth.value.length === 2 && parseInt(cardExpMonth.value, 10) >= 1 && parseInt(cardExpMonth.value, 10) <= 12
                && (cardExpYear.value.length === 2 || cardExpYear.value.length === 4);
            const cvvOk = cardCvv.value.length === 3;
            if (!nameOk || !numberOk || !expOk || !cvvOk) {
                cardFormError.value = props.t('checkout.card_fill_all') || 'Preencha todos os dados do cartão corretamente.';
                return;
            }
        }
        cardTokenizing.value = true;
        cardFormError.value = '';
        const getTokenPromise = isCardGatewayStripe.value ? getStripePaymentMethod() : getEfiPaymentToken();
        getTokenPromise
            .then(({ payment_token, card_mask }) => {
                const payload = {
                    product_id: form.product_id,
                    payment_method: 'card',
                    email: form.email,
                    name: showName.value ? form.name : '',
                    cpf: showCpf.value ? (form.cpf || '').replace(/\D/g, '') : '',
                    phone: showPhone.value ? form.country_code + phoneDigits.value : '',
                    coupon_code: (form.coupon_code || '').trim() || null,
                    payment_token,
                    card_mask: card_mask || undefined,
                    installments: Math.min(props.cardMaxInstallments || 1, Math.max(1, selectedInstallments.value)),
                };
                if (props.productOfferId) payload.product_offer_id = props.productOfferId;
                if (props.subscriptionPlanId) payload.subscription_plan_id = props.subscriptionPlanId;
                if (props.checkoutSessionToken) payload.checkout_session_token = props.checkoutSessionToken;
                if (props.displayCurrency) payload.display_currency = props.displayCurrency;
                if (Array.isArray(props.orderBumpIds) && props.orderBumpIds.length > 0) {
                    payload.order_bump_ids = props.orderBumpIds.map((id) => (typeof id === 'number' ? id : parseInt(id, 10))).filter((n) => !Number.isNaN(n));
                }
                return axios.post('/checkout', payload, {
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'X-XSRF-TOKEN': getCsrfToken(),
                    },
                    withCredentials: true,
                });
            })
            .then(async (res) => {
                const data = res?.data;
                const isJson = data && typeof data === 'object' && !Array.isArray(data);
                if (isJson && data.success && data.requires_action && data.client_secret && stripeInstance.value) {
                    const { error } = await stripeInstance.value.confirmCardPayment(data.client_secret);
                    if (error) {
                        cardRefusedMessage.value = error.message || 'Falha na confirmação do pagamento.';
                        showCardRefusedModal.value = true;
                        cardTokenizing.value = false;
                        return;
                    }
                    const url = data.redirect_url || (data.order_id ? `/checkout/obrigado?order_id=${data.order_id}` : null);
                    if (url && !url.replace(/\/$/, '').endsWith(window.location.origin + '/checkout')) {
                        cardApproved.value = true;
                        cardApprovedRedirectUrl.value = url;
                        setTimeout(() => router.visit(url), 800);
                        return;
                    }
                }
                if (isJson && data.success) {
                    const url = data.redirect_url;
                    const isPostUrl = (u) => !u || u.replace(/\/$/, '') === window.location.origin + '/checkout';
                    if (url && !isPostUrl(url)) {
                        cardApproved.value = true;
                        cardApprovedRedirectUrl.value = url;
                        setTimeout(() => router.visit(url), 1800);
                        return;
                    }
                    if (url && isPostUrl(url)) {
                        cardTokenizing.value = false;
                        return;
                    }
                }
                if (res.request?.responseURL && res.request.responseURL !== window.location.href && typeof data === 'string') {
                    const finalUrl = res.request.responseURL;
                    if (!finalUrl.replace(/\/$/, '').endsWith('/checkout')) {
                        cardApproved.value = true;
                        setTimeout(() => { window.location.href = finalUrl; }, 1200);
                        return;
                    }
                }
                cardTokenizing.value = false;
            })
            .catch((err) => {
                const msg = err?.response?.data?.message || err?.message || 'Não foi possível processar o cartão. Tente novamente.';
                cardRefusedMessage.value = typeof msg === 'string' ? msg : 'Não foi possível processar o cartão. Tente novamente.';
                showCardRefusedModal.value = true;
            })
            .finally(() => {
                if (!cardApproved.value) cardTokenizing.value = false;
            });
        return;
    }

    const payload = {
        product_id: form.product_id,
        payment_method: paymentMethod,
        email: form.email,
        name: showName.value ? form.name : '',
        cpf: showCpf.value ? (form.cpf || '').replace(/\D/g, '') : '',
        phone: showPhone.value ? form.country_code + phoneDigits.value : '',
        coupon_code: (form.coupon_code || '').trim() || null,
    };
    if (props.productOfferId) payload.product_offer_id = props.productOfferId;
    if (props.subscriptionPlanId) payload.subscription_plan_id = props.subscriptionPlanId;
    if (props.checkoutSessionToken) payload.checkout_session_token = props.checkoutSessionToken;
    if (props.displayCurrency) payload.display_currency = props.displayCurrency;
    if (Array.isArray(props.orderBumpIds) && props.orderBumpIds.length > 0) {
        payload.order_bump_ids = props.orderBumpIds.map((id) => (typeof id === 'number' ? id : parseInt(id, 10))).filter((n) => !Number.isNaN(n));
    }
    if (paymentMethod === 'boleto') {
        payload.address_zipcode = (form.address_zipcode || '').replace(/\D/g, '').slice(0, 8);
        payload.address_street = (form.address_street || '').trim();
        payload.address_number = (form.address_number || '').trim();
        payload.address_neighborhood = (form.address_neighborhood || '').trim();
        payload.address_city = (form.address_city || '').trim();
        payload.address_state = (form.address_state || '').trim().slice(0, 2).toUpperCase();
    }
    form.transform(() => payload).post('/checkout');
}
</script>

<template>
    <section data-id="customer_info" class="relative">
        <!-- Modal Aprovado (cartão) -->
        <Teleport to="body">
            <div
                v-if="cardApproved"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                role="dialog"
                aria-modal="true"
                aria-labelledby="approved-title"
            >
                <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" aria-hidden="true" />
                <div
                    class="relative w-full max-w-sm rounded-2xl border border-gray-200/80 bg-white p-8 shadow-2xl"
                    role="document"
                >
                    <div class="flex flex-col items-center text-center">
                        <div class="flex h-16 w-16 items-center justify-center rounded-full bg-green-100">
                            <Check class="h-9 w-9 text-green-600" stroke-width="2.5" />
                        </div>
                        <h2 id="approved-title" class="mt-5 text-lg font-semibold text-gray-900">Pagamento aprovado!</h2>
                        <p class="mt-1.5 text-sm text-gray-600">Redirecionando...</p>
                    </div>
                </div>
            </div>
        </Teleport>

        <div class="mb-6 flex items-center gap-3">
            <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-gray-100 text-gray-600" aria-hidden="true">
                <UserRound class="h-5 w-5" />
            </span>
            <h2 class="text-lg font-semibold tracking-tight text-gray-900">{{ t('checkout.seus_dados') }}</h2>
        </div>
        <form class="space-y-5" @submit.prevent="submit">
            <input v-model="form.product_id" type="hidden" />
            <div
                v-if="Object.keys(form.errors).length > 0"
                class="rounded-xl border border-red-200 bg-red-50/90 px-4 py-3 text-sm font-medium text-red-800"
                role="alert"
            >
                {{ t('checkout.corrija_erros') || 'Corrija os erros abaixo antes de continuar.' }}
            </div>
            <div v-if="showEditForm" class="grid grid-cols-1 gap-5 sm:grid-cols-2 sm:gap-4">
                <div v-if="showName" class="relative sm:col-span-2">
                    <label for="checkout-name" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.name') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <UserRound class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <input
                            id="checkout-name"
                            v-model="form.name"
                            type="text"
                            :required="showName"
                            :class="inputClassWithIcon"
                            :placeholder="t('checkout.name_placeholder')"
                        />
                    </div>
                    <p v-if="form.errors.name" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.name }}</p>
                </div>
                <div class="relative" :class="emailColSpan">
                    <label for="checkout-email" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.email') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <Mail class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <input
                            id="checkout-email"
                            v-model="form.email"
                            type="email"
                            autocomplete="email"
                            required
                            :class="inputClassWithIcon"
                            :placeholder="t('checkout.email_placeholder')"
                            @focus="openEmailDropdown()"
                            @input="openEmailDropdown()"
                            @blur="scheduleCloseEmailDropdown()"
                        />
                        <ul
                            v-if="shouldShowEmailDropdown && filteredEmailSuggestions.length > 0"
                            class="absolute left-0 right-0 top-full z-10 mt-1 max-h-48 overflow-auto rounded-xl border border-gray-200 bg-white py-1 shadow-lg"
                            role="listbox"
                        >
                            <li
                                v-for="opt in filteredEmailSuggestions"
                                :key="opt"
                                role="option"
                                class="cursor-pointer px-4 py-2.5 text-sm text-gray-700 hover:bg-gray-50"
                                @mousedown.prevent="selectEmailSuggestion(opt)"
                            >
                                {{ opt }}
                            </li>
                        </ul>
                    </div>
                    <p v-if="form.errors.email" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.email }}</p>
                </div>
                <div v-if="showPhone && !showCpf" class="relative sm:col-span-1">
                    <label for="checkout-phone" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.phone') }}</label>
                    <div :class="phoneWrapperClass">
                        <div class="flex h-full w-[4.5rem] shrink-0 items-center">
                            <CheckoutDropdown
                                v-model:open="phoneCountryOpen"
                                :aria-label="t('checkout.country_code_label')"
                                align="left"
                                teleport
                                class="h-full w-full"
                            >
                                <template #trigger>
                                    <div
                                        class="flex min-w-0 items-center gap-2 self-stretch rounded-l-xl border-0 bg-transparent py-3.5 pl-3 pr-2 text-sm font-medium text-gray-700"
                                    >
                                    <img
                                        :src="flagImgUrl(currentCountry)"
                                        :alt="''"
                                        class="h-4 w-6 shrink-0 object-contain"
                                        width="24"
                                        height="18"
                                    />
                                    <span class="shrink-0">+{{ form.country_code }}</span>
                                </div>
                            </template>
                            <button
                                v-for="c in countryCodes"
                                :key="c.code"
                                type="button"
                                role="option"
                                class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-gray-50"
                                :class="form.country_code === c.code ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-700'"
                                @click="selectPhoneCountry(c)"
                            >
                                <img
                                    :src="flagImgUrl(c.country)"
                                    :alt="''"
                                    class="h-4 w-6 shrink-0 object-contain"
                                    width="24"
                                    height="18"
                                />
                                <span class="min-w-0 flex-1">{{ c.label }}</span>
                                <span class="shrink-0 text-gray-500">+{{ c.code }}</span>
                                <Check v-if="form.country_code === c.code" class="h-4 w-4 shrink-0 text-gray-500" />
                            </button>
                            </CheckoutDropdown>
                        </div>
                        <input
                            id="checkout-phone"
                            :value="phoneDisplay"
                            type="text"
                            inputmode="tel"
                            :class="phoneInputClass"
                            :placeholder="form.country_code === '55' ? t('checkout.phone_placeholder_br') : t('checkout.phone_placeholder_other')"
                            @input="onPhoneInput"
                        />
                    </div>
                    <p v-if="form.errors.phone" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.phone }}</p>
                </div>
                <div v-if="showCpf && !showPhone" class="relative sm:col-span-1">
                    <label for="checkout-cpf" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.cpf') }}</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <CreditCard class="h-5 w-5" aria-hidden="true" />
                        </span>
                        <input
                            id="checkout-cpf"
                            :value="cpfDisplay"
                            type="text"
                            inputmode="numeric"
                            autocomplete="off"
                            :required="showCpf"
                            :class="inputClassWithIcon"
                            :placeholder="t('checkout.cpf_placeholder')"
                            maxlength="14"
                            @input="onCpfInput"
                        />
                    </div>
                    <p v-if="form.errors.cpf" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.cpf }}</p>
                </div>
                <template v-if="showPhone && showCpf">
                    <div class="relative sm:col-span-1">
                        <label for="checkout-phone-both" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.phone') }}</label>
                        <div :class="phoneWrapperClass">
                            <div class="flex h-full w-[4.5rem] shrink-0 items-center">
                                <CheckoutDropdown
                                    v-model:open="phoneCountryOpen"
                                    :aria-label="t('checkout.country_code_label')"
                                    align="left"
                                    teleport
                                    class="h-full w-full"
                                >
                                    <template #trigger>
                                        <div
                                            class="flex min-w-0 items-center gap-2 self-stretch rounded-l-xl border-0 bg-transparent py-3.5 pl-3 pr-2 text-sm font-medium text-gray-700"
                                        >
                                            <img
                                                :src="flagImgUrl(currentCountry)"
                                                :alt="''"
                                                class="h-4 w-6 shrink-0 object-contain"
                                                width="24"
                                                height="18"
                                            />
                                            <span class="shrink-0">+{{ form.country_code }}</span>
                                        </div>
                                    </template>
                                    <button
                                        v-for="c in countryCodes"
                                        :key="c.code"
                                        type="button"
                                        role="option"
                                        class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm transition hover:bg-gray-50"
                                        :class="form.country_code === c.code ? 'bg-gray-50 font-medium text-gray-900' : 'text-gray-700'"
                                        @click="selectPhoneCountry(c)"
                                    >
                                        <img
                                            :src="flagImgUrl(c.country)"
                                            :alt="''"
                                            class="h-4 w-6 shrink-0 object-contain"
                                            width="24"
                                            height="18"
                                        />
                                        <span class="min-w-0 flex-1">{{ c.label }}</span>
                                        <span class="shrink-0 text-gray-500">+{{ c.code }}</span>
                                        <Check v-if="form.country_code === c.code" class="h-4 w-4 shrink-0 text-gray-500" />
                                    </button>
                                </CheckoutDropdown>
                            </div>
                            <input
                                id="checkout-phone-both"
                                :value="phoneDisplay"
                                type="text"
                                inputmode="tel"
                                :class="phoneInputClass"
                                :placeholder="form.country_code === '55' ? t('checkout.phone_placeholder_br') : t('checkout.phone_placeholder_other')"
                                @input="onPhoneInput"
                            />
                        </div>
                        <p v-if="form.errors.phone" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.phone }}</p>
                    </div>
                    <div class="relative sm:col-span-1">
                        <label for="checkout-cpf-both" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.cpf') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                                <CreditCard class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <input
                                id="checkout-cpf-both"
                                :value="cpfDisplay"
                                type="text"
                                inputmode="numeric"
                                autocomplete="off"
                                :required="showCpf"
                                :class="inputClassWithIcon"
                                :placeholder="t('checkout.cpf_placeholder')"
                                maxlength="14"
                                @input="onCpfInput"
                            />
                        </div>
                        <p v-if="form.errors.cpf" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.cpf }}</p>
                    </div>
                </template>
            </div>
            <div v-else class="space-y-4">
                <div class="rounded-xl border-2 border-gray-100 bg-gray-50/80 p-4 space-y-2.5">
                    <p v-if="showName" class="flex justify-between gap-2 text-sm">
                        <span class="text-gray-600">{{ t('checkout.name') }}</span>
                        <span class="font-medium text-gray-900 text-right">{{ form.name || '–' }}</span>
                    </p>
                    <p class="flex justify-between gap-2 text-sm">
                        <span class="text-gray-600">{{ t('checkout.email') }}</span>
                        <span class="font-medium text-gray-900 text-right break-all">{{ form.email }}</span>
                    </p>
                    <p v-if="showPhone" class="flex justify-between gap-2 text-sm">
                        <span class="text-gray-600">{{ t('checkout.phone') }}</span>
                        <span class="font-medium text-gray-900 text-right">{{ phoneDisplay || '–' }}</span>
                    </p>
                    <p v-if="showCpf" class="flex justify-between gap-2 text-sm">
                        <span class="text-gray-600">{{ t('checkout.cpf') }}</span>
                        <span class="font-medium text-gray-900 text-right">{{ cpfDisplay || '–' }}</span>
                    </p>
                </div>
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-3 py-2 text-xs font-medium text-gray-600 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2"
                    @click="showEditForm = true"
                >
                    <Pencil class="h-3.5 w-3.5" />
                    {{ t('checkout.editar_dados') }}
                </button>
            </div>
            <div v-if="showCouponField">
                <label for="checkout-coupon" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.coupon_label') }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                        <Tag class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <input
                        id="checkout-coupon"
                        v-model="form.coupon_code"
                        type="text"
                        :class="inputClassWithIcon"
                        :placeholder="t('checkout.coupon_placeholder')"
                        autocomplete="off"
                    />
                </div>
                <p v-if="couponValidationError || form.errors.coupon_code" class="mt-1.5 text-sm font-medium text-red-600">
                    {{ couponValidationError || form.errors.coupon_code }}
                </p>
                <p v-if="couponValidating" class="mt-1.5 text-sm text-gray-500">{{ t('checkout.validating_coupon') }}</p>
            </div>

            <!-- Você pode gostar (order bumps): abaixo dos dados, antes da forma de pagamento -->
            <CheckoutOrderBumps
                v-if="orderBumps && orderBumps.length"
                :order-bumps="orderBumps"
                :selected-ids="orderBumpIds"
                :primary-color="primaryColor"
                :order-bump-color="orderBumpColor"
                :t="t"
                :format-price="formatPrice"
                :display-currency="displayCurrency"
                class="mt-8"
                @update:selected-ids="emit('update:orderBumpIds', $event)"
            />

            <!-- Forma de pagamento (componentes por gateway em gateways/<slug>/) -->
            <CheckoutPaymentMethods
                v-model="form.payment_method"
                :available-payment-methods="availablePaymentMethods"
                :primary-color="primaryColor"
                :t="t"
            />
            <p v-if="form.errors.payment_method" class="text-sm font-medium text-red-600">{{ form.errors.payment_method }}</p>

            <!-- Endereço para boleto (Mercado Pago exige): primeiro CEP, depois só número -->
            <div v-if="form.payment_method === 'boleto'" class="space-y-4 rounded-xl border-2 border-gray-100 bg-gray-50/50 p-4">
                <div class="flex items-center gap-2 text-gray-700">
                    <MapPin class="h-5 w-5 shrink-0 text-gray-500" aria-hidden="true" />
                    <span class="text-sm font-medium">{{ t('checkout.endereco_boleto') }}</span>
                </div>
                <!-- 1) Só CEP + Buscar -->
                <div v-if="!boletoAddressFetched" class="flex flex-col gap-2 sm:flex-row sm:items-end sm:gap-2">
                    <div class="min-w-0 flex-1">
                        <label for="checkout-address-cep" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.endereco_boleto_cep') }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                                <MapPin class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <input
                                id="checkout-address-cep"
                                :value="form.address_zipcode"
                                type="text"
                                inputmode="numeric"
                                maxlength="9"
                                :class="inputClassWithIcon"
                                placeholder="00000-000"
                                @input="onAddressCepInput"
                                @blur="fetchAddressByCep"
                            />
                        </div>
                        <p v-if="form.errors.address_zipcode" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.address_zipcode }}</p>
                    </div>
                    <button
                        type="button"
                        :disabled="addressCepLoading || (form.address_zipcode || '').replace(/\D/g, '').length < 8"
                        class="shrink-0 self-start rounded-xl border-2 border-gray-200 bg-white px-4 py-3 text-sm font-medium text-gray-700 transition hover:bg-gray-50 disabled:opacity-50 sm:h-[3.25rem] sm:self-end"
                        @click="fetchAddressByCep"
                    >
                        <Loader2 v-if="addressCepLoading" class="h-5 w-5 animate-spin" />
                        <span v-else>{{ t('checkout.endereco_boleto_buscar') }}</span>
                    </button>
                </div>
                <!-- 2) Após buscar: endereço em texto + só campo Número -->
                <template v-else>
                    <p class="text-xs font-medium text-gray-500">
                        {{ [form.address_street, form.address_neighborhood, [form.address_city, form.address_state].filter(Boolean).join(' - ')].filter(Boolean).join(', ') }}
                    </p>
                    <div class="max-w-[12rem]">
                        <label for="checkout-address-number" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.endereco_boleto_numero') }}</label>
                        <input
                            id="checkout-address-number"
                            v-model="form.address_number"
                            type="text"
                            :class="inputClassWithIcon"
                            placeholder="Nº"
                        />
                        <p v-if="form.errors.address_number" class="mt-1.5 text-sm font-medium text-red-600">{{ form.errors.address_number }}</p>
                    </div>
                    <p v-if="form.errors.address_street || form.errors.address_neighborhood || form.errors.address_city || form.errors.address_state" class="text-sm font-medium text-red-600">
                        {{ form.errors.address_street || form.errors.address_neighborhood || form.errors.address_city || form.errors.address_state }}
                    </p>
                </template>
            </div>

            <!-- Formulário de cartão (Stripe Elements ou campos Efí) -->
            <div v-if="form.payment_method === 'card'" class="space-y-4 rounded-xl border-2 border-gray-100 bg-gray-50/50 p-4">
                <div class="flex items-center gap-2 text-gray-700">
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center">
                        <img src="/images/gateways/card.png" alt="" class="h-6 w-6 object-contain" />
                    </span>
                    <span class="text-sm font-medium">{{ t('checkout.dados_cartao') || 'Dados do cartão' }}</span>
                </div>
                <p v-if="cardFormError" class="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-sm font-medium text-red-700" role="alert">
                    {{ cardFormError }}
                </p>
                <!-- Mercado Pago: Card Payment Brick -->
                <div v-if="isCardGatewayMercadopago" class="min-h-[280px]">
                    <p v-if="!cardMercadopagoPublicKey?.trim()" class="rounded-xl border-2 border-amber-200 bg-amber-50/80 px-4 py-3 text-sm font-medium text-amber-800">
                        Configure a Public Key do Mercado Pago nas credenciais do gateway (cartão).
                    </p>
                    <p v-else-if="mercadopagoBrickError" class="rounded-xl border-2 border-red-200 bg-red-50/80 px-4 py-3 text-sm font-medium text-red-700" role="alert">
                        {{ mercadopagoBrickError }}
                    </p>
                    <div v-else ref="mercadopagoBrickContainer" class="min-h-[280px]">
                        <div id="cardPaymentBrick_container" class="min-h-[260px]" />
                    </div>
                </div>
                <!-- Asaas: 2 etapas (cartão + endereço com CEP) -->
                <div v-else-if="isCardGatewayAsaas" class="space-y-4">
                    <AsaasCard
                        :method="availablePaymentMethods?.find((m) => m.id === 'card') || { id: 'card', label: 'Cartão' }"
                        :selected="true"
                        :primary-color="primaryColor"
                        :card-data="asaasCardData"
                        :address-data="asaasAddressData"
                        :step="asaasCardStep"
                        :format-price="formatPrice"
                        :card-installments-enabled="cardInstallmentsEnabled"
                        :card-max-installments="cardMaxInstallments"
                        :checkout-total-brl="checkoutTotalBrl"
                        :t="t"
                        @update:cardData="asaasCardData = $event"
                        @update:addressData="asaasAddressData = $event"
                        @update:step="asaasCardStep = $event"
                    />
                </div>
                <!-- Stripe: Card Element (dados do cartão não passam pelo nosso servidor) -->
                <template v-else-if="isCardGatewayStripe">
                    <div class="relative sm:col-span-2">
                        <label for="card-holder-stripe" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.card_holder') || 'Nome no cartão' }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                                <User class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <input
                                id="card-holder-stripe"
                                v-model="cardHolderName"
                                type="text"
                                autocomplete="cc-name"
                                :class="inputClassWithIcon"
                                :placeholder="t('checkout.card_holder_placeholder') || 'Como está impresso no cartão'"
                            />
                        </div>
                    </div>
                    <div ref="stripeCardRef" class="rounded-xl border-2 border-gray-100 bg-white px-4 py-3 min-h-[3.25rem]" />
                </template>
                <!-- Efí: campos manuais para tokenização payment-token-efi -->
                <div v-else class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="relative sm:col-span-2">
                        <label for="card-holder" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.card_holder') || 'Nome no cartão' }}</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                                <User class="h-5 w-5" aria-hidden="true" />
                            </span>
                            <input
                                id="card-holder"
                                v-model="cardHolderName"
                                type="text"
                                autocomplete="cc-name"
                                :class="inputClassWithIcon"
                                :placeholder="t('checkout.card_holder_placeholder') || 'Como está impresso no cartão'"
                            />
                        </div>
                    </div>
                    <div class="relative sm:col-span-2">
                        <label for="card-number" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.card_number') || 'Número do cartão' }}</label>
                        <div class="flex flex-nowrap items-stretch overflow-hidden rounded-xl border-2 border-gray-100 bg-gray-50/80 transition focus-within:border-gray-200 focus-within:bg-white focus-within:ring-2 focus-within:ring-offset-0">
                            <span class="pointer-events-none flex h-full min-h-[3.25rem] w-10 shrink-0 items-center justify-center text-gray-400">
                                <img
                                    :src="cardBrandImage"
                                    alt=""
                                    class="block h-5 w-5 flex-shrink-0 object-contain self-center"
                                    aria-hidden="true"
                                    @error="(e) => { const el = e.target; if (!el.src || !el.src.endsWith('card.png')) { el.onerror = null; el.src = '/images/gateways/card.png'; } }"
                                />
                            </span>
                            <template v-if="!cardNumberComplete || showFullCardNumber">
                                <input
                                    id="card-number"
                                    ref="cardNumberInput"
                                    :value="cardNumberDisplay"
                                    type="text"
                                    inputmode="numeric"
                                    autocomplete="cc-number"
                                    maxlength="19"
                                    class="min-w-0 flex-1 border-0 bg-transparent py-3.5 pr-4 pl-2 text-sm font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                                    :placeholder="t('checkout.card_number_placeholder') || '0000 0000 0000 0000'"
                                    @input="onCardNumberInput"
                                    @blur="onCardNumberBlur"
                                />
                            </template>
                            <template v-else>
                                <button
                                    type="button"
                                    class="min-w-0 flex-1 cursor-pointer py-3.5 pl-2 text-left text-sm font-medium tabular-nums text-gray-700 hover:text-gray-900 focus:outline-none focus:ring-0"
                                    :title="t('checkout.click_to_edit') || 'Clique para editar o número'"
                                    @click="reopenCardNumberEdit"
                                >
                                    {{ cardNumberMasked }}
                                </button>
                                <div class="flex shrink-0 items-center gap-1.5 pr-3">
                                    <input
                                        id="card-exp-month"
                                        ref="cardExpMonthInput"
                                        type="text"
                                        inputmode="numeric"
                                        class="w-9 border-0 bg-transparent py-3.5 px-0 text-center text-sm font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                                        placeholder="MM"
                                        maxlength="2"
                                        :value="cardExpMonth"
                                        @input="(e) => onCardExpInput(e, 'month')"
                                    />
                                    <span class="text-gray-300 text-sm">/</span>
                                    <input
                                        id="card-exp-year"
                                        ref="cardExpYearInput"
                                        type="text"
                                        inputmode="numeric"
                                        class="w-9 border-0 bg-transparent py-3.5 px-0 text-center text-sm font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                                        placeholder="AA"
                                        maxlength="4"
                                        :value="cardExpYear"
                                        @input="(e) => onCardExpInput(e, 'year')"
                                    />
                                    <input
                                        id="card-cvv"
                                        ref="cardCvvInput"
                                        :value="cardCvv"
                                        type="text"
                                        inputmode="numeric"
                                        autocomplete="cc-csc"
                                        maxlength="3"
                                        class="w-11 border-0 bg-transparent py-3.5 px-0 text-center text-sm font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                                        placeholder="CVV"
                                        @input="onCardCvvInput"
                                    />
                                </div>
                            </template>
                        </div>
                    </div>
                </div>
                <!-- Parcelas (Efí e Asaas; Stripe e MP Brick têm seu próprio) -->
                <div
                    v-if="form.payment_method === 'card' && cardInstallmentsEnabled && !isCardGatewayStripe && !isCardGatewayMercadopago && !isCardGatewayAsaas"
                    class="mt-4"
                >
                    <label for="installments-select" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.installments') }}</label>
                    <select
                        id="installments-select"
                        v-model.number="selectedInstallments"
                        class="w-full rounded-xl border-2 border-gray-200 bg-white px-4 py-3 text-sm font-medium text-gray-900 shadow-sm transition focus:outline-none focus:ring-2 focus:ring-offset-1"
                        :style="{ '--tw-ring-color': primaryColor }"
                    >
                        <option
                            v-for="n in cardMaxInstallments"
                            :key="n"
                            :value="n"
                        >
                            {{ n }}x de {{ formatPrice(checkoutTotalBrl / n, displayCurrency) }}
                        </option>
                    </select>
                </div>
            </div>

            <p v-if="form.errors.product_id" class="text-sm font-medium text-red-600">{{ form.errors.product_id }}</p>
            <button
                v-if="form.payment_method !== 'card' || !isCardGatewayMercadopago"
                type="submit"
                class="flex w-full items-center justify-center gap-2 rounded-xl px-6 py-4 text-base font-semibold text-white shadow-lg shadow-black/10 transition hover:opacity-95 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-70"
                :style="{ backgroundColor: primaryColor }"
                :disabled="form.processing || cardTokenizing || cardApproved"
            >
                <Loader2 v-if="form.processing || cardTokenizing" class="h-5 w-5 animate-spin" />
                <Check v-else-if="cardApproved" class="h-5 w-5" />
                <ScanQrCode v-else-if="form.payment_method === 'pix' || form.payment_method === 'pix_auto'" class="h-5 w-5" />
                <CreditCard v-else-if="form.payment_method === 'card'" class="h-5 w-5" />
                <FileText v-else-if="form.payment_method === 'boleto'" class="h-5 w-5" />
                <ShoppingBag v-else class="h-5 w-5" />
                {{ cardApproved ? 'Aprovado!' : (form.processing || cardTokenizing) ? t('checkout.processing') : (form.payment_method === 'pix' ? t('checkout.gerar_pix') : form.payment_method === 'pix_auto' ? (t('checkout.gerar_pix_auto') || 'Gerar PIX (renovação automática)') : form.payment_method === 'card' ? (isCardGatewayAsaas && asaasCardStep === 1 ? 'Continuar' : (t('checkout.pagar_cartao') || 'Pagar com cartão')) : form.payment_method === 'boleto' ? (t('checkout.gerar_boleto') || 'Gerar boleto') : t('checkout.submit_button')) }}
            </button>
        </form>
        <footer class="mt-8 hidden border-t border-gray-100 pt-6 sm:block">
            <div v-if="showFooterCustom" class="mb-6 text-center">
                <img
                    v-if="footerLogoUrl"
                    :src="footerLogoUrl"
                    alt=""
                    class="mx-auto h-8 w-auto object-contain"
                    loading="lazy"
                />
                <p v-if="footerText" class="mt-2 text-sm font-medium text-gray-700">
                    {{ footerText }}
                </p>
                <a
                    v-if="footerSupportEmail"
                    :href="`mailto:${footerSupportEmail}`"
                    class="mt-1 inline-block text-sm font-medium text-gray-600 underline decoration-gray-300 underline-offset-2 hover:text-gray-800"
                >
                    {{ footerSupportEmail }}
                </a>
            </div>
            <p class="flex items-center justify-center gap-2 text-sm text-gray-500">
                <Shield class="h-4 w-4 shrink-0" aria-hidden="true" />
                Compra 100% segura
            </p>
            <p class="mt-2 text-center text-xs text-gray-400">
                Este site é protegido pelo reCAPTCHA do Google
            </p>
            <p class="mt-2 text-center text-xs text-gray-400">
                Copyright © {{ new Date().getFullYear() }}. Todos os direitos reservados.
            </p>
        </footer>

        <!-- Modal pagamento recusado (cartão) -->
        <Teleport to="body">
            <div
                v-show="showCardRefusedModal"
                class="fixed inset-0 z-[10000] flex items-center justify-center p-4"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="refused-title"
                >
                    <div
                        class="absolute inset-0 bg-black/50"
                        aria-hidden="true"
                        @click="closeRefusedModal"
                    />
                    <div
                        class="relative z-10 w-full max-w-md rounded-2xl border border-gray-200 bg-white p-6 shadow-xl"
                        @click.stop
                    >
                        <button
                            type="button"
                            class="absolute right-4 top-4 rounded-lg p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600"
                            aria-label="Fechar"
                            @click.prevent.stop="closeRefusedModal"
                        >
                            <X class="h-5 w-5" />
                        </button>
                        <div class="flex h-14 w-14 items-center justify-center rounded-full bg-red-100">
                            <AlertCircle class="h-8 w-8 text-red-600" />
                        </div>
                        <h2 id="refused-title" class="mt-4 text-lg font-semibold text-gray-900">Pagamento recusado</h2>
                        <p class="mt-2 text-sm text-gray-600">{{ cardRefusedMessage }}</p>
                        <div class="mt-6 flex flex-col gap-3 sm:flex-row-reverse">
                            <button
                                type="button"
                                class="flex-1 rounded-xl px-4 py-3 text-sm font-semibold text-white transition hover:opacity-90 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                :style="{ backgroundColor: primaryColor }"
                                @click.prevent.stop="onRefusedTryOtherCard($event)"
                            >
                                Tentar com outro cartão
                            </button>
                            <button
                                v-if="availablePaymentMethods.some(m => m.id !== 'card')"
                                type="button"
                                class="flex-1 rounded-xl border-2 border-gray-200 bg-white px-4 py-3 text-sm font-semibold text-gray-700 transition hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2"
                                @click.prevent.stop="onRefusedOtherPaymentMethod($event)"
                            >
                                Outra forma de pagamento
                            </button>
                        </div>
                    </div>
                </div>
        </Teleport>
    </section>
</template>
