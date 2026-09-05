import type { AccountValuation } from '@cadran/api-client';
import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import '@/i18n';
import { AccountValuationCell } from './AccountValuationCell';

function valuation(overrides: Partial<AccountValuation> = {}): AccountValuation {
  return {
    accountId: '00000000-0000-7000-8000-0000000000d1',
    requestedOn: '2026-09-05',
    asOf: '2026-09-03',
    amount: { value: '231.10', assetCode: 'EUR' },
    display: { value: '231.10', assetCode: 'EUR' },
    belowDisplayStep: false,
    source: 'MANUAL',
    ageDays: 2,
    quality: 'STALE',
    reconciliationStatus: 'UNRECONCILED',
    snapshotId: '00000000-0000-7000-8000-0000000000b1',
    version: 1,
    ...overrides,
  };
}

describe('AccountValuationCell', () => {
  it('names a missing valuation instead of inventing zero', () => {
    render(
      <AccountValuationCell
        valuation={valuation({
          asOf: null,
          amount: null,
          display: null,
          source: null,
          ageDays: null,
          quality: 'MISSING',
          reconciliationStatus: null,
          snapshotId: null,
          version: null,
        })}
      />,
    );

    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Aucune valorisation')).toBeTruthy();
    expect(screen.queryByText(/0/)).toBeNull();
  });

  it('keeps a stale figure and says how old it is', () => {
    render(<AccountValuationCell valuation={valuation()} />);

    expect(screen.getByText(/231,10/)).toBeTruthy();
    expect(screen.getByText(/Ancienne/)).toBeTruthy();
    expect(screen.getByText(/2 jours/)).toBeTruthy();
    expect(screen.getByText(/Saisie/)).toBeTruthy();
  });

  it('does not display a rounded zero for a figure below the step', () => {
    render(
      <AccountValuationCell
        valuation={valuation({
          amount: { value: '0.0000000012', assetCode: 'ETH' },
          display: null,
          belowDisplayStep: true,
          quality: 'CURRENT',
          ageDays: 0,
          source: 'MANUAL',
        })}
      />,
    );

    expect(screen.getByText('Inférieur à l’échelon d’affichage')).toBeTruthy();
    expect(screen.queryByText(/0,00/)).toBeNull();
  });
});
