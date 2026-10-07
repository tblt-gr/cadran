import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { OperationPreviewLine } from './OperationPreviewLine';
const amount = (value: string) => ({ value, assetCode: 'EUR' });
afterEach(cleanup);
describe('insurance preview figures', () => {
  it('retains switch labels during loading and leaves unavailable figures unknown', () => {
    const { container, rerender } = render(
      <OperationPreviewLine type="SWITCH" pending showResidual={false} />,
    );
    for (const label of ['À réinvestir', 'Écart', 'Barème (informatif)'])
      expect(screen.getByText(label)).toBeTruthy();
    expect(container.querySelectorAll('[aria-hidden="true"]').length).toBe(4);
    rerender(<OperationPreviewLine type="SWITCH" pending={false} showResidual={false} />);
    expect(screen.queryByText(/0,00/)).toBeNull();
    expect(screen.getByText('Non renseigné')).toBeTruthy();
  });

  it('uses the server cash remainder for a premium', () => {
    render(
      <OperationPreviewLine
        type="PREMIUM"
        pending={false}
        showResidual={false}
        preview={{
          cashAmount: amount('0'),
          legs: [],
          netPaidAmount: null,
          expectedGrossAmount: null,
          expectedIncomingAmount: null,
          difference: null,
          applicableSwitchFeeRate: null,
          applicableSwitchFeeFixed: null,
          roundingResidual: null,
        }}
      />,
    );
    expect(screen.getByText('Reste en liquidités')).toBeTruthy();
    expect(screen.getByText(/0,00/)).toBeTruthy();
  });
  it('shows unknown figures without substituting zero and skeletons while pending', () => {
    const { container, rerender } = render(
      <OperationPreviewLine type="FULL_SURRENDER" pending={false} showResidual={false} />,
    );
    expect(screen.getByText('Net versé')).toBeTruthy();
    expect(screen.getByText('Montant brut attendu')).toBeTruthy();
    expect(screen.queryByText(/0,00/)).toBeNull();
    rerender(<OperationPreviewLine type="FULL_SURRENDER" pending showResidual={false} />);
    expect(container.querySelectorAll('[aria-hidden="true"]').length).toBe(3);
  });
  it('hides a zero support residual instead of listing a rounding gap of zero', () => {
    render(
      <OperationPreviewLine
        type="PREMIUM"
        pending={false}
        showResidual={false}
        preview={{
          cashAmount: amount('0'),
          legs: [
            {
              index: 1,
              direction: 'IN',
              instrument: null,
              quantity: '6000.00',
              unitPrice: '1',
              amount: amount('6000.00'),
              roundingResidual: amount('0'),
            },
          ],
          netPaidAmount: null,
          expectedGrossAmount: null,
          expectedIncomingAmount: null,
          difference: null,
          applicableSwitchFeeRate: null,
          applicableSwitchFeeFixed: null,
          roundingResidual: null,
        }}
      />,
    );
    expect(screen.queryByText(/Écart d’arrondi du support/)).toBeNull();
  });
});
