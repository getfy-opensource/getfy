<script setup>
import { ref, computed, watch, nextTick } from 'vue';
import { User, MapPin, Loader2, ChevronRight } from 'lucide-vue-next';

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

const props = defineProps({
    method: { type: Object, required: true },
    selected: { type: Boolean, default: false },
    primaryColor: { type: String, default: '#0ea5e9' },
    /** Dados do cartão (v-model cardData) */
    cardData: { type: Object, default: () => ({}) },
    /** Dados do endereço (v-model addressData) */
    addressData: { type: Object, default: () => ({}) },
    /** Etapa atual 1 ou 2 (v-model step) */
    step: { type: Number, default: 1 },
    formatPrice: { type: Function, default: (v) => `R$ ${Number(v).toFixed(2)}` },
    /** Parcelamento habilitado nas configurações do produto */
    cardInstallmentsEnabled: { type: Boolean, default: false },
    cardMaxInstallments: { type: Number, default: 1 },
    checkoutTotalBrl: { type: Number, default: 0 },
    t: { type: Function, default: (k) => k },
});

const emit = defineEmits(['update:cardData', 'update:addressData', 'update:step']);

const cardHolderName = ref(props.cardData?.card_holder_name ?? '');
const cardNumberDigits = ref((props.cardData?.card_number ?? '').replace(/\D/g, ''));
const cardExpMonth = ref((props.cardData?.card_expiry_month ?? '').replace(/\D/g, '').slice(0, 2));
const cardExpYear = ref((props.cardData?.card_expiry_year ?? '').replace(/\D/g, '').slice(-4));
const cardCvv = ref((props.cardData?.card_ccv ?? '').replace(/\D/g, '').slice(0, 4));
const selectedInstallments = ref(props.cardData?.installments ?? 1);
const showFullCardNumber = ref(true);
const cardNumberInput = ref(null);
const cardExpMonthInput = ref(null);
const cardExpYearInput = ref(null);
const cardCvvInput = ref(null);

const addressZipcode = ref(props.addressData?.address_zipcode ?? '');
const addressStreet = ref(props.addressData?.address_street ?? '');
const addressNumber = ref(props.addressData?.address_number ?? '');
const addressComplement = ref(props.addressData?.address_complement ?? '');
const addressNeighborhood = ref(props.addressData?.address_neighborhood ?? '');
const addressCity = ref(props.addressData?.address_city ?? '');
const addressState = ref(props.addressData?.address_state ?? '');
const cepLoading = ref(false);
const cepError = ref('');

watch([cardHolderName, cardNumberDigits, cardExpMonth, cardExpYear, cardCvv, selectedInstallments], () => {
    emit('update:cardData', {
        card_holder_name: cardHolderName.value,
        card_number: cardNumberDigits.value,
        card_expiry_month: cardExpMonth.value.replace(/\D/g, '').slice(0, 2),
        card_expiry_year: cardExpYear.value.replace(/\D/g, '').slice(-4),
        card_ccv: cardCvv.value.replace(/\D/g, '').slice(0, 4),
        installments: props.cardInstallmentsEnabled ? selectedInstallments.value : 1,
    });
}, { deep: true });

watch([addressZipcode, addressStreet, addressNumber, addressComplement, addressNeighborhood, addressCity, addressState], () => {
    emit('update:addressData', {
        address_zipcode: addressZipcode.value,
        address_street: addressStreet.value,
        address_number: addressNumber.value,
        address_complement: addressComplement.value,
        address_neighborhood: addressNeighborhood.value,
        address_city: addressCity.value,
        address_state: addressState.value.slice(0, 2).toUpperCase(),
    });
}, { deep: true });

async function fetchCep() {
    const zip = addressZipcode.value.replace(/\D/g, '');
    if (zip.length !== 8) return;
    cepError.value = '';
    cepLoading.value = true;
    try {
        const res = await fetch(`https://viacep.com.br/ws/${zip}/json/`);
        const data = await res.json();
        if (data.erro) {
            cepError.value = 'CEP não encontrado.';
            return;
        }
        addressStreet.value = data.logradouro || '';
        addressNeighborhood.value = data.bairro || '';
        addressCity.value = data.localidade || '';
        addressState.value = (data.uf || '').toUpperCase();
    } catch (_) {
        cepError.value = 'Não foi possível buscar o CEP.';
    } finally {
        cepLoading.value = false;
    }
}

watch(addressZipcode, (val) => {
    if (val.replace(/\D/g, '').length === 8) fetchCep();
});

const cardNumberDisplay = computed(() => {
    const d = cardNumberDigits.value;
    const parts = d.match(/.{1,4}/g) || [];
    return parts.join(' ').slice(0, 19);
});

const cardBrandFromNumber = computed(() => getCardBrandFromNumber(cardNumberDigits.value));
const cardBrandImage = computed(() => {
    const brand = cardBrandFromNumber.value;
    return brand ? `/images/gateways/cards/${brand.slug}.svg` : '/images/gateways/card.png';
});

const cardNumberComplete = computed(() => cardNumberDigits.value.length === 16);
const cardNumberMasked = computed(() => {
    const d = cardNumberDigits.value;
    if (d.length < 4) return '';
    return '•••• •••• •••• ' + d.slice(-4);
});

function onCardNumberInput(e) {
    const v = (e.target?.value || '').replace(/\D/g, '').slice(0, 16);
    cardNumberDigits.value = v;
    if (v.length === 16) {
        showFullCardNumber.value = false;
        nextTick(() => cardExpMonthInput.value?.focus());
    }
}

function reopenCardNumberEdit() {
    showFullCardNumber.value = true;
    nextTick(() => cardNumberInput.value?.focus());
}

function onCardNumberBlur() {
    if (cardNumberDigits.value.length === 16) showFullCardNumber.value = false;
}

function onCardExpInput(e, part) {
    const v = (e.target?.value || '').replace(/\D/g, '');
    if (part === 'month') {
        const m = v.slice(0, 2);
        cardExpMonth.value = m.length === 1 && parseInt(m, 10) > 1 ? '0' + m : m;
        if (cardExpMonth.value.length === 2) nextTick(() => cardExpYearInput.value?.focus());
    } else {
        cardExpYear.value = v.slice(0, 4);
        if (cardExpYear.value.length >= 2) nextTick(() => cardCvvInput.value?.focus());
    }
}

function onCardCvvInput(e) {
    cardCvv.value = (e.target?.value || '').replace(/\D/g, '').slice(0, 4);
}

const inputClass = 'block w-full rounded-xl border-2 border-gray-100 bg-gray-50/80 px-4 py-3.5 pl-12 text-base font-medium text-gray-900 placeholder-gray-400 transition focus:border-gray-200 focus:bg-white focus:outline-none focus:ring-2 focus:ring-offset-0';
const inputClassWithIcon = inputClass;

function goToStep2() {
    emit('update:step', 2);
}
</script>

<template>
    <div class="min-w-0 flex-1 space-y-4">
        <span v-if="selected" class="block font-medium text-gray-900">{{ method?.label || 'Cartão de crédito' }}</span>

        <!-- Etapa 1: Dados do cartão (layout igual à Efí: número + validade + CVV no mesmo bloco) -->
        <div v-show="step === 1" class="space-y-4">
            <div>
                <label for="asaas-card-holder" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.card_holder') || 'Nome no cartão' }}</label>
                <div class="relative">
                    <span class="pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                        <User class="h-5 w-5" aria-hidden="true" />
                    </span>
                    <input
                        id="asaas-card-holder"
                        v-model="cardHolderName"
                        type="text"
                        autocomplete="cc-name"
                        :class="inputClassWithIcon"
                        :placeholder="t('checkout.card_holder_placeholder') || 'Como está impresso no cartão'"
                    />
                </div>
            </div>
            <div>
                <label for="asaas-card-number" class="mb-2 block text-sm font-medium text-gray-700">{{ t('checkout.card_number') || 'Número do cartão' }}</label>
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
                            id="asaas-card-number"
                            ref="cardNumberInput"
                            :value="cardNumberDisplay"
                            type="text"
                            inputmode="numeric"
                            autocomplete="cc-number"
                            maxlength="19"
                            class="min-w-0 flex-1 border-0 bg-transparent py-3.5 pr-4 pl-2 text-base font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                            :placeholder="t('checkout.card_number_placeholder') || '0000 0000 0000 0000'"
                            @input="onCardNumberInput"
                            @blur="onCardNumberBlur"
                        />
                    </template>
                    <template v-else>
                        <button
                            type="button"
                            class="min-w-0 flex-1 cursor-pointer py-3.5 pl-2 text-left text-base font-medium tabular-nums text-gray-700 hover:text-gray-900 focus:outline-none focus:ring-0"
                            :title="t('checkout.click_to_edit') || 'Clique para editar o número'"
                            @click="reopenCardNumberEdit"
                        >
                            {{ cardNumberMasked }}
                        </button>
                        <div class="flex shrink-0 items-center gap-1.5 pr-3">
                            <input
                                id="asaas-card-exp-month"
                                ref="cardExpMonthInput"
                                type="text"
                                inputmode="numeric"
                                class="w-9 border-0 bg-transparent py-3.5 px-0 text-center text-base font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                                placeholder="MM"
                                maxlength="2"
                                :value="cardExpMonth"
                                @input="(e) => onCardExpInput(e, 'month')"
                            />
                            <span class="text-gray-300 text-sm">/</span>
                            <input
                                id="asaas-card-exp-year"
                                ref="cardExpYearInput"
                                type="text"
                                inputmode="numeric"
                                class="w-9 border-0 bg-transparent py-3.5 px-0 text-center text-base font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                                placeholder="AA"
                                maxlength="4"
                                :value="cardExpYear"
                                @input="(e) => onCardExpInput(e, 'year')"
                            />
                            <input
                                id="asaas-card-cvv"
                                ref="cardCvvInput"
                                :value="cardCvv"
                                type="text"
                                inputmode="numeric"
                                autocomplete="cc-csc"
                                maxlength="4"
                                class="w-11 border-0 bg-transparent py-3.5 px-0 text-center text-base font-medium text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-0"
                                placeholder="CVV"
                                @input="onCardCvvInput"
                            />
                        </div>
                    </template>
                </div>
            </div>
            <div v-if="cardInstallmentsEnabled && cardMaxInstallments > 1">
                <label class="mb-1 block text-sm font-medium text-gray-700">{{ t('checkout.installments') || 'Parcelas' }}</label>
                <select v-model.number="selectedInstallments" :class="inputClass" class="cursor-pointer">
                    <option v-for="n in cardMaxInstallments" :key="n" :value="n">
                        {{ n }}x de {{ formatPrice(checkoutTotalBrl / n) }}
                    </option>
                </select>
            </div>
            <button
                type="button"
                class="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 text-sm font-semibold text-white"
                :style="{ backgroundColor: primaryColor }"
                @click="goToStep2"
            >
                Continuar
                <ChevronRight class="h-4 w-4" />
            </button>
        </div>

        <!-- Etapa 2: Endereço (CEP automático) -->
        <div v-show="step === 2" class="space-y-4">
            <div class="flex items-center gap-2 text-sm font-medium text-gray-700">
                <MapPin class="h-4 w-4" />
                Endereço de cobrança
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">CEP</label>
                <div class="flex gap-2">
                    <input
                        v-model="addressZipcode"
                        type="text"
                        inputmode="numeric"
                        maxlength="9"
                        placeholder="00000-000"
                        :class="inputClass"
                        class="flex-1"
                        @input="(e) => { addressZipcode = (e.target?.value || '').replace(/\D/g, '').replace(/(\d{5})(\d)/, '$1-$2'); }"
                    />
                    <span v-if="cepLoading" class="flex items-center px-3 text-gray-500">
                        <Loader2 class="h-5 w-5 animate-spin" />
                    </span>
                </div>
                <p v-if="cepError" class="mt-1 text-sm text-red-600">{{ cepError }}</p>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Rua</label>
                <input v-model="addressStreet" type="text" :class="inputClass" placeholder="Logradouro" />
            </div>
            <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Número</label>
                    <input v-model="addressNumber" type="text" :class="inputClass" placeholder="Nº" />
                </div>
                <div class="sm:col-span-2">
                    <label class="mb-1 block text-sm font-medium text-gray-700">Complemento</label>
                    <input v-model="addressComplement" type="text" :class="inputClass" placeholder="Opcional" />
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Bairro</label>
                <input v-model="addressNeighborhood" type="text" :class="inputClass" placeholder="Bairro" />
            </div>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Cidade</label>
                    <input v-model="addressCity" type="text" :class="inputClass" placeholder="Cidade" />
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">UF</label>
                    <input
                        v-model="addressState"
                        type="text"
                        maxlength="2"
                        :class="inputClass"
                        placeholder="UF"
                        @input="(e) => { addressState = (e.target?.value || '').toUpperCase().slice(0, 2); }"
                    />
                </div>
            </div>
        </div>
    </div>
</template>

