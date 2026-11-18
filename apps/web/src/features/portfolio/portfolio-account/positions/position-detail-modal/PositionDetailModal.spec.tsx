import type { PortfolioPosition } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PositionDetailModal } from './PositionDetailModal';

const base = {
  instrument: {
    id: 'instrument-1',
    name: 'ETF Monde',
    isin: 'FR0014000MR3',
    ticker: 'CW8',
    status: 'ACTIVE',
    quantityPrecision: 6,
  },
  quantity: '3',
  fifoCostBasis: { value: '360.60', assetCode: 'EUR' },
  averageCostBasis: { value: '320.60', assetCode: 'EUR' },
  averageUnitCost: { value: '106.866666666666666666666667', assetCode: 'EUR' },
  costReason: null,
  marketValue: { value: '350.00', assetCode: 'EUR' },
  unrealizedGain: { value: '29.40', assetCode: 'EUR' },
  valueReason: null,
  firstAcquiredOn: '2026-01-10',
  lastOperationOn: '2026-03-04',
  openLotCount: 2,
  flags: [],
} as PortfolioPosition;

afterEach(cleanup);

function figure(label: string) {
  return screen.getByText(label).nextElementSibling?.textContent ?? '';
}

describe('PositionDetailModal', () => {
  it('carries the figures the row leaves out and opens the lots', () => {
    const onLots = vi.fn();
    render(<PositionDetailModal onClose={vi.fn()} onLots={onLots} position={base} />);

    expect(screen.getByRole('dialog', { name: 'ETF Monde' })).toBeTruthy();
    expect(figure('Quantité')).toBe('3');
    expect(figure('Prix de revient moyen')).toContain('106,87');
    expect(figure('Coût total (moyen)')).toContain('320,60');
    expect(figure('Valeur')).toContain('350,00');
    expect(figure('Résultat')).toContain('+29,40');
    expect(figure('Lots ouverts')).toBe('2');
    expect(
      screen.getByRole('link', { name: "Voir l'instrument ETF Monde" }).getAttribute('href'),
    ).toBe('/investments/instruments/instrument-1');
    fireEvent.click(screen.getByRole('button', { name: 'Voir les lots' }));
    expect(onLots).toHaveBeenCalledWith(base);
  });

  it('labels unknown cost and missing value without presenting zero', () => {
    render(
      <PositionDetailModal
        onClose={vi.fn()}
        onLots={vi.fn()}
        position={{
          ...base,
          averageCostBasis: null,
          averageUnitCost: null,
          costReason: 'UNKNOWN_LOT_COST',
          marketValue: null,
          unrealizedGain: null,
          valueReason: 'NOT_DETAILED',
        }}
      />,
    );

    expect(screen.getAllByText('Coût inconnu').length).toBeGreaterThanOrEqual(3);
    expect(screen.getByText('Valeur non détaillée')).toBeTruthy();
    expect(screen.getByText('Plus-value non calculable')).toBeTruthy();
    expect(screen.queryByText(/0,00/)).toBeNull();
  });
});
