// Formatação do financeiro Pagar.me (valores sempre em centavos vindos do servidor).

const BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

export function formatCents(cents) {
    const value = Number(cents ?? 0);
    return BRL.format((Number.isFinite(value) ? value : 0) / 100);
}

const METHOD_LABELS = {
    credit_card: 'Cartão',
    debit_card: 'Débito',
    pix: 'Pix',
    boleto: 'Boleto',
};

export function methodLabel(method) {
    return METHOD_LABELS[method] ?? method ?? '—';
}

const STATUS_LABELS = {
    waiting_funds: 'A receber',
    paid: 'Recebido',
    pending_transfer: 'Pendente',
    processing: 'Processando',
    transferred: 'Transferido',
    failed: 'Falhou',
    canceled: 'Cancelado',
    cancelled: 'Cancelado',
    requested: 'Solicitado',
    pending: 'Pendente',
    approved: 'Aprovada',
    refused: 'Recusada',
    success: 'Pago',
};

export function statusLabel(status) {
    return STATUS_LABELS[status] ?? status ?? '—';
}

const MONTHS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

function periodLabel(item, mode) {
    if (mode === 'month') {
        const [y, m] = String(item.month).split('-');
        return `${MONTHS[Number(m) - 1]}/${String(y).slice(2)}`;
    }
    const [, m, d] = String(item.start).split('-');
    return `${d}/${m}`;
}

// Barras da agenda de recebíveis: altura proporcional ao maior período (0–100).
export function scheduleBars(items, mode) {
    const list = Array.isArray(items) ? items : [];
    const max = Math.max(0, ...list.map((i) => Number(i.net_cents) || 0));
    return list.map((item) => {
        const cents = Number(item.net_cents) || 0;
        return {
            key: mode === 'month' ? item.month : item.start,
            label: periodLabel(item, mode),
            cents,
            pct: max > 0 ? Math.round((Math.max(0, cents) / max) * 100) : 0,
        };
    });
}

export function bankAccountLabel(bank) {
    if (!bank || typeof bank !== 'object') {
        return 'Conta bancária não informada';
    }
    const parts = [];
    if (bank.bank) parts.push(`Banco ${bank.bank}`);
    if (bank.branch) parts.push(`ag. ${bank.branch}`);
    if (bank.account_last4) parts.push(`conta ••••${bank.account_last4}`);
    return parts.length ? parts.join(' · ') : 'Conta bancária não informada';
}

const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];

export function transferSettingsLabel(settings) {
    if (!settings || typeof settings !== 'object') {
        return 'Configuração de transferência indisponível';
    }
    if (!settings.transfer_enabled) {
        return 'Transferência automática desligada';
    }
    const day = Number(settings.transfer_day) || 0;
    switch (settings.transfer_interval) {
        case 'daily':
            return 'Automática: diária';
        case 'weekly':
            return `Automática: semanal (${WEEKDAYS[day] ?? `dia ${day}`})`;
        case 'monthly':
            return `Automática: mensal (dia ${day})`;
        default:
            return 'Transferência automática ligada';
    }
}

// "1.234,56" | "10,5" | "10.50" | "10" → centavos inteiros; inválido ou negativo → null.
export function centsFromInput(raw) {
    let s = String(raw ?? '').trim().replace(/^R\$\s*/, '');
    if (s === '' || !/^[\d.,]+$/.test(s)) {
        return null;
    }
    if (s.includes(',')) {
        s = s.replace(/\./g, '').replace(',', '.');
    } else if (!/\.\d{1,2}$/.test(s)) {
        s = s.replace(/\./g, '');
    }
    const value = Number(s);
    if (!Number.isFinite(value) || value < 0) {
        return null;
    }
    return Math.round(value * 100);
}
