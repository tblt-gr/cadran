import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AmountRangeFilter } from './AmountRangeFilter';

const EMPTY_RANGE = { assetCode: 'EUR', maxAmount: '', minAmount: '' };

describe('AmountRangeFilter', () => {
  afterEach(() => {
    cleanup();
  });

  it('keeps a half-typed bound in the field with an inline error instead of committing it', () => {
    const onChange = vi.fn();
    render(
      <AmountRangeFilter {...EMPTY_RANGE} assetCodes={['EUR']} onChange={onChange} revision={0} />,
    );

    const lower = screen.getByRole('textbox', { name: 'Borne basse' });
    for (const partial of ['-', '12.', '1.2.3']) {
      fireEvent.change(lower, { target: { value: partial } });
      expect(lower).toHaveProperty('value', partial);
      expect(lower.getAttribute('aria-invalid')).toBe('true');
    }
    expect(onChange).not.toHaveBeenCalled();
    expect(
      screen.getByText('Saisissez un montant décimal signé, par exemple -200.00.'),
    ).toBeTruthy();

    fireEvent.change(lower, { target: { value: '12,5' } });
    expect(onChange).toHaveBeenLastCalledWith({ ...EMPTY_RANGE, minAmount: '12.5' });
    expect(lower.getAttribute('aria-invalid')).toBeNull();
  });

  it('refuses a bound beyond the 24 decimal places the API stores', () => {
    const onChange = vi.fn();
    render(
      <AmountRangeFilter {...EMPTY_RANGE} assetCodes={['EUR']} onChange={onChange} revision={0} />,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Borne haute' }), {
      target: { value: `0.${'1'.repeat(25)}` },
    });

    expect(onChange).not.toHaveBeenCalled();
  });

  it('discards a pending draft when the filters are replaced from outside', () => {
    const props = { ...EMPTY_RANGE, assetCodes: ['EUR'], onChange: vi.fn() };
    const { rerender } = render(<AmountRangeFilter {...props} revision={0} />);

    const lower = screen.getByRole('textbox', { name: 'Borne basse' });
    fireEvent.change(lower, { target: { value: '-' } });
    rerender(<AmountRangeFilter {...props} revision={1} />);

    expect(lower).toHaveProperty('value', '');
    expect(lower.getAttribute('aria-invalid')).toBeNull();
  });

  it('asks for an asset code when a bound is set without one', () => {
    render(
      <AmountRangeFilter
        assetCode=""
        assetCodes={['EUR']}
        maxAmount=""
        minAmount="-200.00"
        onChange={vi.fn()}
        revision={0}
      />,
    );

    const select = screen.getByRole('combobox', { name: 'Actif des bornes' });
    expect(select.getAttribute('aria-invalid')).toBe('true');
    expect(
      screen.getByText('Choisissez l’actif des bornes pour appliquer le filtre de montant.'),
    ).toBeTruthy();
  });
});
