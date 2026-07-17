import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const settingsPage = readFileSync(
    new URL('../../resources/js/Pages/Settings/Index.vue', import.meta.url),
    'utf8',
);

test('currency rate inputs accept imported rates with arbitrary decimal precision', () => {
    const rateInputStart = settingsPage.indexOf('v-model.number="curr.rate_to_brl"');
    assert.notEqual(rateInputStart, -1);

    const rateInput = settingsPage.slice(rateInputStart, rateInputStart + 220);
    assert.match(rateInput, /step="any"/);
    assert.doesNotMatch(rateInput, /step="0\.0001"/);
});

test('inverse currency rate input does not block form submission due to step mismatch', () => {
    const inverseInputStart = settingsPage.indexOf('v-else\n                                                    type="number"');
    assert.notEqual(inverseInputStart, -1);

    const inverseInput = settingsPage.slice(inverseInputStart, inverseInputStart + 180);
    assert.match(inverseInput, /step="any"/);
    assert.doesNotMatch(inverseInput, /step="0\.01"/);
});
