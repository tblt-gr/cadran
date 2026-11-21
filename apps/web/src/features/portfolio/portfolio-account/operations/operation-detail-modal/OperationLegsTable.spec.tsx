import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import '@/i18n';
import { setAmountsHidden } from '@/lib/amountPrivacy';
import { OperationLegsTable } from './OperationLegsTable';

afterEach(() => {
  cleanup();
  setAmountsHidden(false);
});

it.each(['12', '-12', '0', '0.000000000000000000000001'])(
  'masks residual %s with the same accessible announcement',
  (value) => {
    setAmountsHidden(true);
    render(
      <OperationLegsTable
        legs={[
          {
            index: 1,
            direction: 'IN',
            instrument: null,
            quantity: '5',
            unitPrice: '10',
            amount: { value: '50', assetCode: 'EUR' },
            roundingResidual: { value, assetCode: 'EUR' },
          },
        ]}
      />,
    );
    const residual = screen.getAllByRole('cell').at(-1)!;
    expect(residual.textContent).toBe('••••••Montant masqué');
    expect(within(residual).getByText('Montant masqué')).toBeTruthy();
    expect(residual.querySelector('[aria-hidden="true"]')?.textContent).toBe('••••••');
  },
);

it('retains missing residual meaning while privacy is enabled', () => {
  setAmountsHidden(true);
  render(
    <OperationLegsTable
      legs={[
        {
          index: 1,
          direction: 'IN',
          instrument: null,
          quantity: '5',
          unitPrice: '10',
          amount: { value: '50', assetCode: 'EUR' },
          roundingResidual: null,
        },
      ]}
    />,
  );
  expect(within(screen.getAllByRole('cell').at(-1)!).getByText('Non renseigné')).toBeTruthy();
});
