import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import {
    formatCents,
    methodLabel,
    statusLabel,
    scheduleBars,
    bankAccountLabel,
    transferSettingsLabel,
    centsFromInput,
} from '../../resources/js/lib/pagarmeFinance.js';

const nbsp = (s) => s.replace(/ /g, ' ');

describe('formatCents', () => {
    it('formats cents as BRL', () => {
        assert.equal(nbsp(formatCents(123456)), 'R$ 1.234,56');
        assert.equal(nbsp(formatCents(0)), 'R$ 0,00');
        assert.equal(nbsp(formatCents(null)), 'R$ 0,00');
        assert.equal(nbsp(formatCents(-500)), '-R$ 5,00');
    });
});

describe('labels', () => {
    it('translates payment methods and statuses', () => {
        assert.equal(methodLabel('credit_card'), 'Cartão');
        assert.equal(methodLabel('pix'), 'Pix');
        assert.equal(methodLabel('boleto'), 'Boleto');
        assert.equal(methodLabel('weird'), 'weird');
        assert.equal(statusLabel('waiting_funds'), 'A receber');
        assert.equal(statusLabel('paid'), 'Recebido');
        assert.equal(statusLabel('pending_transfer'), 'Pendente');
        assert.equal(statusLabel('transferred'), 'Transferido');
    });
});

describe('scheduleBars', () => {
    it('scales bars against the biggest value and keeps empty periods', () => {
        const bars = scheduleBars([
            { month: '2026-10', net_cents: 1000 },
            { month: '2026-11', net_cents: 0 },
            { month: '2026-12', net_cents: 4000 },
        ], 'month');
        assert.deepEqual(bars.map((b) => b.pct), [25, 0, 100]);
        assert.equal(bars[0].label, 'out/26');
        assert.equal(bars[2].label, 'dez/26');
    });
    it('labels weeks by start day', () => {
        const bars = scheduleBars([{ start: '2026-10-12', net_cents: 100 }], 'week');
        assert.equal(bars[0].label, '12/10');
        assert.equal(bars[0].pct, 100);
    });
    it('returns zero heights when there is nothing to receive', () => {
        const bars = scheduleBars([{ start: '2026-10-12', net_cents: 0 }], 'week');
        assert.equal(bars[0].pct, 0);
    });
});

describe('bankAccountLabel', () => {
    it('shows bank, branch and only the last digits', () => {
        assert.equal(bankAccountLabel({ bank: '341', branch: '1234', account_last4: '6543' }), 'Banco 341 · ag. 1234 · conta ••••6543');
        assert.equal(bankAccountLabel(null), 'Conta bancária não informada');
    });
});

describe('transferSettingsLabel', () => {
    it('describes the automatic transfer schedule', () => {
        assert.equal(transferSettingsLabel({ transfer_enabled: false }), 'Transferência automática desligada');
        assert.equal(transferSettingsLabel({ transfer_enabled: true, transfer_interval: 'daily', transfer_day: 0 }), 'Automática: diária');
        assert.equal(transferSettingsLabel({ transfer_enabled: true, transfer_interval: 'weekly', transfer_day: 1 }), 'Automática: semanal (segunda-feira)');
        assert.equal(transferSettingsLabel({ transfer_enabled: true, transfer_interval: 'monthly', transfer_day: 15 }), 'Automática: mensal (dia 15)');
        assert.equal(transferSettingsLabel(null), 'Configuração de transferência indisponível');
    });
});

describe('centsFromInput', () => {
    it('parses BRL input into integer cents', () => {
        assert.equal(centsFromInput('1.234,56'), 123456);
        assert.equal(centsFromInput('10'), 1000);
        assert.equal(centsFromInput('10,5'), 1050);
        assert.equal(centsFromInput('10.50'), 1050);
        assert.equal(centsFromInput(''), null);
        assert.equal(centsFromInput('abc'), null);
        assert.equal(centsFromInput('-5'), null);
    });
});
