import { describe, expect, it } from 'vitest';
import { ruleDirection, toSignedBounds, toTypedBounds, withDirection } from './ruleAmountBounds';

describe('ruleAmountBounds', () => {
  it('turns outflows typed from 50 to 200 into the signed range -200 to -50', () => {
    expect(toSignedBounds({ max: '200.00', min: '50.00' }, 'OUTFLOW')).toEqual({
      max: '-50.00',
      min: '-200.00',
    });
  });

  it('gives the same range when the minus is typed', () => {
    expect(toSignedBounds({ max: '-200.00', min: '-50.00' }, 'OUTFLOW')).toEqual({
      max: '-50.00',
      min: '-200.00',
    });
  });

  it('keeps an open bound open', () => {
    expect(toSignedBounds({ max: null, min: '200' }, 'OUTFLOW')).toEqual({
      max: '-200',
      min: null,
    });
  });

  it('leaves inflow and undirected bounds as typed', () => {
    expect(toSignedBounds({ max: '200', min: '50' }, 'INFLOW')).toEqual({ max: '200', min: '50' });
    expect(toSignedBounds({ max: '-50', min: '-200' }, null)).toEqual({ max: '-50', min: '-200' });
  });

  it('shows a stored outflow range the way it is typed, and round-trips', () => {
    const stored = { max: '-50.00', min: '-200.00' };
    expect(toTypedBounds(stored, 'OUTFLOW')).toEqual({ max: '200.00', min: '50.00' });
    expect(toSignedBounds(toTypedBounds(stored, 'OUTFLOW'), 'OUTFLOW')).toEqual(stored);
  });

  it('keeps half-typed entries as they are', () => {
    expect(toSignedBounds({ max: null, min: '5.' }, 'OUTFLOW')).toEqual({ max: '5.', min: null });
  });

  it('maps the rule direction', () => {
    expect(ruleDirection('OUT')).toBe('OUTFLOW');
    expect(ruleDirection('IN')).toBe('INFLOW');
    expect(ruleDirection(null)).toBeNull();
    expect(ruleDirection(undefined)).toBeNull();
  });
});

describe('withDirection', () => {
  const base = { amount: { assetCode: 'EUR', max: '200', min: '50' }, direction: null } as const;

  it('re-signs the typed range when the rule becomes an outflow rule', () => {
    expect(withDirection(base, 'OUT')).toEqual({
      amount: { assetCode: 'EUR', max: '-50', min: '-200' },
      direction: 'OUT',
    });
  });

  it('keeps the typed range when the direction leaves OUT', () => {
    const out = withDirection(base, 'OUT');
    expect(withDirection(out, 'IN')).toEqual({ ...base, direction: 'IN' });
  });

  it('changes only the direction when there is no amount condition', () => {
    expect(withDirection({ amount: null, direction: null }, 'OUT')).toEqual({
      amount: null,
      direction: 'OUT',
    });
  });
});
