/** Chaves em config/checkout_translations.php por id do método. */
const METHOD_TRANSLATION_KEYS = {
    pix: 'checkout.method_pix',
    card: 'checkout.method_card',
    boleto: 'checkout.method_boleto',
    pix_auto: 'checkout.method_pix_auto',
    apple_pay: 'checkout.method_apple_pay',
    google_pay: 'checkout.method_google_pay',
    paypal: 'checkout.method_paypal',
    pix_parcelado: 'checkout.method_pix_parcelado',
};

const DEFAULT_LABELS_PT = {
    pix: 'PIX',
    card: 'Cartão',
    boleto: 'Boleto',
    pix_auto: 'PIX automático',
    apple_pay: 'Apple Pay',
    google_pay: 'Google Pay',
    paypal: 'PayPal',
    pix_parcelado: 'PIX Parcelado',
};

/**
 * @param {string} methodId
 * @param {(key: string) => string} t
 * @param {string} [serverLabel] label vindo do backend (fallback)
 */
export function paymentMethodLabel(methodId, t, serverLabel) {
    const key = METHOD_TRANSLATION_KEYS[methodId];
    if (key && typeof t === 'function') {
        const translated = t(key);
        if (translated && translated !== key) {
            return translated;
        }
    }
    if (serverLabel && String(serverLabel).trim() !== '') {
        return serverLabel;
    }
    return DEFAULT_LABELS_PT[methodId] ?? methodId;
}

/**
 * @param {Array<{ id: string, label?: string, [key: string]: unknown }>} methods
 * @param {(key: string) => string} t
 */
export function localizePaymentMethods(methods, t) {
    if (!Array.isArray(methods)) {
        return [];
    }
    return methods.map((m) => {
        // PayPal exibido como Cartão: mantém o label do builder (Cartão / Cartão PayPal)
        if (m?.id === 'paypal' && m?.display_as === 'card' && m?.label) {
            return { ...m, label: String(m.label) };
        }
        return {
            ...m,
            label: paymentMethodLabel(m.id, t, m.label),
        };
    });
}
