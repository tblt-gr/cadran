import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AmountRangeFilter } from './AmountRangeFilter';

const EMPTY_RANGE = { assetCode: 'EUR', maxAmount: '', minAmount: '' };

describe('AmountRangeFilter', () => {
  afterEach(() => {
    cleanup();
  });

  it('explains that the amount is compared without its sign', () => {
    render(
      <AmountRangeFilter {...EMPTY_RANGE} assetCodes={['EUR']} onChange={vi.fn()} revision={0} />,
    );

    expect(
      screen.getByText('Le montant est comparé sans son signe : 10 trouve +10,00 € et -10,00 €.'),
    ).toBeTruthy();
  });

  it('keeps a half-typed bound in the field with an inline error instead of committing it', () => {
    const onChange = vi.fn();
    render(
      <AmountRangeFilter {...EMPTY_RANGE} assetCodes={['EUR']} onChange={onChange} revision={0} />,
    );

    const lower = screen.getByRole('textbox', { name: 'Montant min' });
    for (const partial of ['12.', '1.2.3', 'abc']) {
      fireEvent.change(lower, { target: { value: partial } });
      expect(lower).toHaveProperty('value', partial);
      expect(lower.getAttribute('aria-invalid')).toBe('true');
    }
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByText('Saisissez un montant positif, par exemple 200.00.')).toBeTruthy();

    fireEvent.change(lower, { target: { value: '12,5' } });
    expect(onChange).toHaveBeenLastCalledWith({ ...EMPTY_RANGE, minAmount: '12.5' });
    expect(lower.getAttribute('aria-invalid')).toBeNull();
  });

  it('drops a typed minus sign: the bound is unsigned', () => {
    const onChange = vi.fn();
    render(
      <AmountRangeFilter {...EMPTY_RANGE} assetCodes={['EUR']} onChange={onChange} revision={0} />,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Montant max' }), {
      target: { value: '-40' },
    });

    expect(onChange).toHaveBeenLastCalledWith({ ...EMPTY_RANGE, maxAmount: '40' });
  });

  it('refuses a bound beyond the 24 decimal places the API stores', () => {
    const onChange = vi.fn();
    render(
      <AmountRangeFilter {...EMPTY_RANGE} assetCodes={['EUR']} onChange={onChange} revision={0} />,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Montant max' }), {
      target: { value: `0.${'1'.repeat(25)}` },
    });

    expect(onChange).not.toHaveBeenCalled();
  });

  it('discards a pending draft when the filters are replaced from outside', () => {
    const props = { ...EMPTY_RANGE, assetCodes: ['EUR'], onChange: vi.fn() };
    const { rerender } = render(<AmountRangeFilter {...props} revision={0} />);

    const lower = screen.getByRole('textbox', { name: 'Montant min' });
    fireEvent.change(lower, { target: { value: '1.' } });
    rerender(<AmountRangeFilter {...props} revision={1} />);

    expect(lower).toHaveProperty('value', '');
    expect(lower.getAttribute('aria-invalid')).toBeNull();
  });

  it('hides the asset selector and fills the asset in when the workspace has a single one', () => {
    const onChange = vi.fn();
    render(
      <AmountRangeFilter
        assetCode=""
        assetCodes={['EUR']}
        maxAmount=""
        minAmount=""
        onChange={onChange}
        revision={0}
      />,
    );

    expect(screen.queryByRole('combobox', { name: 'Actif des bornes' })).toBeNull();
    fireEvent.change(screen.getByRole('textbox', { name: 'Montant min' }), {
      target: { value: '10' },
    });

    expect(onChange).toHaveBeenLastCalledWith({ assetCode: 'EUR', maxAmount: '', minAmount: '10' });
  });

  it('clears the implicit asset along with the last bound', () => {
    const onChange = vi.fn();
    render(
      <AmountRangeFilter
        assetCode="EUR"
        assetCodes={['EUR']}
        maxAmount=""
        minAmount="10"
        onChange={onChange}
        revision={0}
      />,
    );

    fireEvent.change(screen.getByRole('textbox', { name: 'Montant min' }), {
      target: { value: '' },
    });

    expect(onChange).toHaveBeenLastCalledWith({ assetCode: '', maxAmount: '', minAmount: '' });
  });

  it('asks for an asset when several exist and a bound is set without one', () => {
    render(
      <AmountRangeFilter
        assetCode=""
        assetCodes={['EUR', 'USD']}
        maxAmount=""
        minAmount="200.00"
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
