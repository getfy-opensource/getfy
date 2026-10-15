import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { Buffer } from 'node:buffer';
import {
    urlBase64ToUint8Array,
    subscriptionMatchesVapid,
    pushErrorMessage,
    pushTestResult,
} from '../../resources/js/lib/pushSubscription.js';

globalThis.atob = (value) => Buffer.from(value, 'base64').toString('binary');

describe('pushSubscription', () => {
    it('urlBase64ToUint8Array decodes padded base64url', () => {
        const key = urlBase64ToUint8Array('AQID');
        assert.ok(key instanceof Uint8Array);
        assert.ok(key.length > 0);
    });

    it('subscriptionMatchesVapid returns false without subscription', () => {
        assert.equal(subscriptionMatchesVapid(null, 'AQID'), false);
    });

    it('pushErrorMessage maps known reasons', () => {
        assert.match(pushErrorMessage('subscription_sync_failed'), /sincronizar/i);
        assert.match(pushErrorMessage('unknown_code'), /não foi possível/i);
    });

    it('pushTestResult reads success from the server response', () => {
        const r = pushTestResult({ data: { success: true, sent: 2, message: 'Notificação de teste enviada para 2 dispositivos.' } });
        assert.equal(r.ok, true);
        assert.match(r.message, /2 dispositivos/);
    });

    it('pushTestResult surfaces the 422 message when no device is subscribed', () => {
        const r = pushTestResult(null, { response: { status: 422, data: { success: false, message: 'Nenhum dispositivo com notificações ativas.' } } });
        assert.equal(r.ok, false);
        assert.match(r.message, /nenhum dispositivo/i);
    });

    it('pushTestResult explains the rate limit and generic failures', () => {
        assert.match(pushTestResult(null, { response: { status: 429 } }).message, /aguarde/i);
        assert.match(pushTestResult(null, new Error('Network Error')).message, /não foi possível/i);
        assert.equal(pushTestResult({ data: { success: false } }).ok, false);
    });
});
