import type { CategorizationRuleConditions } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AmountConditionFields } from './AmountConditionFields';

afterEach(cleanup);

function renderFields(conditions: CategorizationRuleConditions) {
  const onChange = vi.fn();
  render(<AmountConditionFields conditions={conditions} onChange={onChange} />);

  return onChange;
}

describe('AmountConditionFields', () => {
  it('stores unsigned outflow bounds as the signed range', () => {
    const onChange = renderFields({
      amount: { assetCode: 'EUR', max: null, min: null },
      direction: 'OUT',
    });

    fireEvent.change(screen.getByLabelText('Minimum'), { target: { value: '50.00' } });
    expect(onChange).toHaveBeenLastCalledWith({
      amount: { assetCode: 'EUR', max: '-50.00', min: null },
      direction: 'OUT',
    });
  });

  it('shows a stored outflow range unsigned, smallest first, with its signed bounds', () => {
    renderFields({
      amount: { assetCode: 'EUR', max: '-50.00', min: '-200.00' },
      direction: 'OUT',
    });

    expect((screen.getByLabelText('Minimum') as HTMLInputElement).value).toBe('50.00');
    expect((screen.getByLabelText('Maximum') as HTMLInputElement).value).toBe('200.00');
    expect(screen.getByText(/Bornes enregistrées : ≥ -200.00 EUR · ≤ -50.00 EUR/)).toBeTruthy();
  });

  it('keeps the signed entry when the rule has no direction', () => {
    const onChange = renderFields({
      amount: { assetCode: 'EUR', max: null, min: null },
      direction: null,
    });

    fireEvent.change(screen.getByLabelText('Maximum'), { target: { value: '-200.00' } });
    expect(onChange).toHaveBeenLastCalledWith({
      amount: { assetCode: 'EUR', max: '-200.00', min: null },
      direction: null,
    });
  });

  it('keeps an inflow range positive', () => {
    const onChange = renderFields({
      amount: { assetCode: 'EUR', max: null, min: null },
      direction: 'IN',
    });

    fireEvent.change(screen.getByLabelText('Minimum'), { target: { value: '50.00' } });
    expect(onChange).toHaveBeenLastCalledWith({
      amount: { assetCode: 'EUR', max: null, min: '50.00' },
      direction: 'IN',
    });
  });
});
