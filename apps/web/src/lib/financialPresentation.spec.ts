import { shownAmount } from './shownAmount';
import { afterEach, expect, it } from 'vitest';
import { AMOUNT_MASK, setAmountsHidden } from './amountPrivacy';
import { formatExactAmount, signedPresentation } from './decimal';

afterEach(() => setAmountsHidden(false));

it('keeps all exact digits and a non-breaking asset separator', () => {
  expect(formatExactAmount('0.000000000000000000000001', 'EUR', 'fr-FR')).toBe(
    '0,000000000000000000000001\u00a0EUR',
  );
});

it.each(['12', '-12', '0', '0.000000000000000000000001'])('masks %s with a neutral sign', (raw) => {
  setAmountsHidden(true);
  expect(formatExactAmount(raw, 'EUR', 'fr-FR')).toBe(AMOUNT_MASK);
  expect(signedPresentation(raw, raw)).toEqual({ text: AMOUNT_MASK, sign: 0 });
});

it('reads the sign from exact raw digits', () => {
  expect(signedPresentation('0,00 €', '0.0001')).toEqual({ text: '+0,00 €', sign: 1 });
  expect(signedPresentation('0,00 €', '-0.0001')).toEqual({ text: '-0,00 €', sign: -1 });
  expect(signedPresentation('-', '0')).toEqual({ text: '-', sign: 0 });
});

it('prefers server display and falls back to the exact recorded asset', () => {
  const amount = {
    value: '999999999999999999999999.123456789012345678901234',
    assetCode: 'BTC',
    display: { value: '12.34', assetCode: 'EUR' },
    belowDisplayStep: false,
  };
  expect(shownAmount(amount)).toEqual({ value: '12.34', assetCode: 'EUR' });
  expect(shownAmount({ ...amount, display: null })).toEqual({
    value: amount.value,
    assetCode: 'BTC',
  });
  expect(formatExactAmount(amount.value, 'BTC', 'en-US')).toBe(
    '999,999,999,999,999,999,999,999.123456789012345678901234\u00a0BTC',
  );
});
