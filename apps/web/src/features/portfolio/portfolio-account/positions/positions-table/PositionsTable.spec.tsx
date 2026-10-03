import type { PortfolioPosition } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PositionsTable } from './PositionsTable';

function position(overrides: Partial<PortfolioPosition> = {}): PortfolioPosition {
  return {
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
    firstAcquiredOn: '2026-01-10',
    lastOperationOn: '2026-03-04',
    openLotCount: 1,
    flags: [],
    ...overrides,
  };
}

afterEach(cleanup);

describe('PositionsTable', () => {
  it('shows exact position values and opens the lots action', () => {
    const onLots = vi.fn();
    const item = position();
    render(<PositionsTable items={[item]} onLots={onLots} onReorder={vi.fn()} />);

    const row = within(screen.getAllByRole('row')[1]!);
    expect(row.getByText('ETF Monde')).toBeTruthy();
    expect(row.getByText('FR0014000MR3')).toBeTruthy();
    expect(row.getByText(/106,87/)).toBeTruthy();
    expect(row.getByText(/320,60/)).toBeTruthy();
    fireEvent.click(row.getByRole('button', { name: 'Voir les lots' }));
    expect(onLots).toHaveBeenCalledWith(item);
  });

  it('labels unknown cost without presenting zero', () => {
    render(
      <PositionsTable
        items={[
          position({
            fifoCostBasis: null,
            averageCostBasis: null,
            averageUnitCost: null,
            costReason: 'UNKNOWN_LOT_COST',
          }),
        ]}
        onLots={vi.fn()}
        onReorder={vi.fn()}
      />,
    );

    expect(screen.getAllByText('Coût inconnu').length).toBeGreaterThanOrEqual(2);
    expect(screen.queryByText(/0,00/)).toBeNull();
  });

  it('opens the flagged day reorder and keeps the table scrollable at 360 px', () => {
    const originalWidth = window.innerWidth;
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 360 });
    const item = position({
      flags: [{ code: 'ORDER_DEPENDENT_DAY', dates: ['2026-03-04'] }],
    });
    const onReorder = vi.fn();

    try {
      render(<PositionsTable items={[item]} onLots={vi.fn()} onReorder={onReorder} />);
      fireEvent.click(screen.getByRole('button', { name: 'Ordre à vérifier' }));
      expect(onReorder).toHaveBeenCalledWith(item);
      expect(screen.getByRole('table').parentElement?.className).toContain('scroll');
    } finally {
      Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalWidth });
    }
  });
});
