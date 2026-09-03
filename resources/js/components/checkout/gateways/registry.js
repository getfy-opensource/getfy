/**
 * Registry: um componente por gateway e método de pagamento.
 * Facilita manutenção: cada gateway tem sua pasta (ex: gateways/cajupay/) com Pix.vue, Card.vue, Boleto.vue.
 * Novos gateways: criar pasta gateways/<slug>/ e registrar abaixo.
 */
import DefaultMethodCard from './DefaultMethodCard.vue';

import StripeCard from './stripe/Card.vue';

import MercadopagoPix from './mercadopago/Pix.vue';
import MercadopagoCard from './mercadopago/Card.vue';
import MercadopagoBoleto from './mercadopago/Boleto.vue';

import CajupayCard from './cajupay/Card.vue';
import CajupayApplePay from './cajupay/ApplePay.vue';
import CajupayGooglePay from './cajupay/GooglePay.vue';
import CajupayPixParcelado from './cajupay/PixParcelado.vue';

import PaypalCard from './paypal/Card.vue';
import PaypalMethod from './paypal/Paypal.vue';

/** @type {Record<string, Record<string, import('vue').Component>>} */
export const gatewayMethodComponents = {
    stripe: {
        card: StripeCard,
        pix: DefaultMethodCard,
        boleto: DefaultMethodCard,
    },
    paypal: {
        card: PaypalCard,
        paypal: PaypalMethod,
        pix: DefaultMethodCard,
        boleto: DefaultMethodCard,
    },
    mercadopago: {
        pix: MercadopagoPix,
        card: MercadopagoCard,
        boleto: MercadopagoBoleto,
    },
    pushinpay: {
        pix: DefaultMethodCard,
        card: DefaultMethodCard,
        boleto: DefaultMethodCard,
        pix_auto: DefaultMethodCard,
    },
    asaas: {
        pix: DefaultMethodCard,
        card: DefaultMethodCard,
        boleto: DefaultMethodCard,
    },
    pagarme: {
        pix: DefaultMethodCard,
        card: DefaultMethodCard,
        boleto: DefaultMethodCard,
    },
    cajupay: {
        pix: DefaultMethodCard,
        card: CajupayCard,
        apple_pay: CajupayApplePay,
        google_pay: CajupayGooglePay,
        pix_parcelado: CajupayPixParcelado,
    },
};

/**
 * Retorna o componente para exibir o card do método no checkout.
 * @param {{ id: string, gateway_slug?: string }} method
 * @returns {import('vue').Component}
 */
/** @type {Record<string, Record<string, import('vue').Component>>} */
const pluginGatewayOverrides = {};

/**
 * Registra componente de checkout de um plugin (bundle com frontend.exports.checkout).
 *
 * @param {string} gatewaySlug
 * @param {string} methodId
 * @param {import('vue').Component} component
 */
export function registerGatewayMethod(gatewaySlug, methodId, component) {
    const slug = (gatewaySlug || '').toLowerCase();
    const method = methodId || 'pix';
    if (!slug || !component) {
        return;
    }
    if (!pluginGatewayOverrides[slug]) {
        pluginGatewayOverrides[slug] = {};
    }
    pluginGatewayOverrides[slug][method] = component;
}

export function getMethodCardComponent(method) {
    const slug = (method?.gateway_slug || '').toLowerCase();
    const methodId = method?.id || 'pix';
    const pluginComponent = pluginGatewayOverrides[slug]?.[methodId];
    if (pluginComponent) {
        return pluginComponent;
    }
    const gateway = gatewayMethodComponents[slug];
    // PayPal exibido como Cartão: usa o tile de cartão PayPal
    if (methodId === 'paypal' && method?.display_as === 'card' && gateway?.card) {
        return gateway.card;
    }
    const component = gateway?.[methodId];
    return component || DefaultMethodCard;
}
