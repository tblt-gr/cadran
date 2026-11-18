import type { PortfolioPosition } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PositionsList } from './PositionsList';

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
    marketValue: { value: '350.00', assetCode: 'EUR' },
    unrealizedGain: { value: '29.40', assetCode: 'EUR' },
    valueReason: null,
    firstAcquiredOn: '2026-01-10',
    lastOperationOn: '2026-03-04',
    openLotCount: 1,
    flags: [],
    ...overrides,
  };
}

afterEach(cleanup);

function renderList(items: PortfolioPosition[] | null, handlers = {}) {
  return render(<PositionsList items={items} onOpen={vi.fn()} onReorder={vi.fn()} {...handlers} />);
}

describe('PositionsList', () => {
  it('shows one compact row per position and opens its detail', () => {
    const onOpen = vi.fn();
    const item = position();
    renderList([item], { onOpen });

    const list = screen.getByRole('list', { name: 'Positions du portefeuille' });
    const row = within(within(list).getAllByRole('listitem')[0]!);
    expect(row.getByText('ETF Monde')).toBeTruthy();
    expect(row.getByText('3')).toBeTruthy();
    expect(row.getByText(/\+29,40/)).toBeTruthy();
    expect(row.getByText(/350,00/)).toBeTruthy();
    expect(screen.queryByRole('table')).toBeNull();
    expect(screen.queryByText(/106,87/)).toBeNull();
    fireEvent.click(row.getByRole('button', { name: 'ETF Monde' }));
    expect(onOpen).toHaveBeenCalledWith(item);
  });

  it('opens the detail from anywhere on the row', () => {
    const onOpen = vi.fn();
    const item = position();
    renderList([item], { onOpen });

    fireEvent.click(screen.getByText(/350,00/));
    expect(onOpen).toHaveBeenCalledWith(item);
  });

  it('writes a loss with its sign', () => {
    renderList([position({ unrealizedGain: { value: '-12.50', assetCode: 'EUR' } })]);

    expect(screen.getByText(/-12,50/)).toBeTruthy();
  });

  it('says why a value is missing instead of showing zero', () => {
    renderList([
      position({
        marketValue: null,
        unrealizedGain: null,
        valueReason: 'QUANTITY_CHANGED',
      }),
    ]);

    expect(screen.getByText('Quantité modifiée depuis la valorisation')).toBeTruthy();
    expect(screen.queryByText(/350,00/)).toBeNull();
    expect(screen.queryByText(/0,00/)).toBeNull();
  });

  it('marks a closed position in words and mutes the row', () => {
    renderList([position({ quantity: '0', marketValue: null, valueReason: 'NO_VALUATION' })]);

    expect(screen.getByText('Clôturée')).toBeTruthy();
    expect(screen.getByRole('listitem').getAttribute('data-closed')).toBe('true');
  });

  it('opens the flagged day reorder without opening the detail', () => {
    const item = position({ flags: [{ code: 'ORDER_DEPENDENT_DAY', dates: ['2026-03-04'] }] });
    const onOpen = vi.fn();
    const onReorder = vi.fn();
    renderList([item], { onOpen, onReorder });

    fireEvent.click(screen.getByRole('button', { name: 'Ordre à vérifier' }));
    expect(onReorder).toHaveBeenCalledWith(item);
    expect(onOpen).not.toHaveBeenCalled();
  });

  it('mirrors the rows while loading', () => {
    renderList(null);

    expect(screen.getByTestId('positions-skeleton').getAttribute('aria-busy')).toBe('true');
    expect(screen.getAllByRole('listitem')).toHaveLength(6);
    expect(screen.getAllByText('Chargement des positions…')).toHaveLength(1);
  });
});
