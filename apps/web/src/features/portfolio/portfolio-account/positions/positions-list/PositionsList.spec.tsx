import { setAmountsHidden } from '@/lib/amountPrivacy';
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

afterEach(() => {
  cleanup();
  setAmountsHidden(false);
});

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
    expect(row.getByText('Quantité')).toBeTruthy();
    expect(row.getByText('PRU')).toBeTruthy();
    expect(row.getAllByText(/106,87/).length).toBeGreaterThan(0);
    expect(row.getByText('Coût')).toBeTruthy();
    expect(row.getAllByText(/320,60/).length).toBeGreaterThan(0);
    expect(row.getAllByText(/\+29,40/).length).toBeGreaterThan(0);
    expect(row.getAllByText(/350,00/).length).toBeGreaterThan(0);
    expect(screen.queryByRole('table')).toBeNull();
    fireEvent.click(row.getByRole('button', { name: 'ETF Monde' }));
    expect(onOpen).toHaveBeenCalledWith(item);
  });

  it('opens the detail from anywhere on the row', () => {
    const onOpen = vi.fn();
    const item = position();
    renderList([item], { onOpen });

    fireEvent.click(screen.getAllByText(/350,00/)[0]!);
    expect(onOpen).toHaveBeenCalledWith(item);
  });

  it('writes a loss with its sign', () => {
    renderList([position({ unrealizedGain: { value: '-12.50', assetCode: 'EUR' } })]);

    expect(screen.getAllByText(/-12,50/).length).toBeGreaterThan(0);
  });

  it('says why a value is missing instead of showing zero', () => {
    renderList([
      position({
        marketValue: null,
        unrealizedGain: null,
        valueReason: 'QUANTITY_CHANGED',
      }),
    ]);

    expect(screen.getAllByText('Quantité modifiée depuis la valorisation').length).toBeGreaterThan(
      0,
    );
    expect(screen.queryByText(/350,00/)).toBeNull();
    expect(screen.queryByText(/^0,00/)).toBeNull();
  });

  it('keeps the labelled total cost as the phone value when no market value can be computed', () => {
    renderList([
      position({ marketValue: null, unrealizedGain: null, valueReason: 'NO_VALUATION' }),
    ]);

    const fallback = document.querySelector('[class*="costFallback"]')!;
    expect(fallback.textContent).toMatch(/Coût\s*320,60/);
  });

  it('shows no phone cost value when the cost is unknown', () => {
    renderList([
      position({
        marketValue: null,
        unrealizedGain: null,
        averageCostBasis: null,
        averageUnitCost: null,
        costReason: 'UNKNOWN_LOT_COST',
        valueReason: 'NO_VALUATION',
      }),
    ]);

    expect(document.querySelector('[class*="costFallback"]')).toBeNull();
    expect(screen.getAllByText('Coût inconnu').length).toBeGreaterThan(0);
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

it.each(['12', '-12', '0', '0.000000000000000000000001'])(
  'announces every masked position figure for %s',
  (value) => {
    setAmountsHidden(true);
    const amount = { value, assetCode: 'EUR' };
    const { container } = renderList([
      position({
        quantity: value,
        averageUnitCost: amount,
        averageCostBasis: amount,
        marketValue: amount,
        unrealizedGain: amount,
      }),
    ]);
    expect(screen.getAllByText('Montant masqué')).toHaveLength(8);
    const figures = [...container.querySelectorAll('[data-visual-mask]')];
    expect(figures).toHaveLength(8);
    for (const figure of figures) {
      expect(figure.textContent).toBe('••••••Montant masqué');
      expect(figure.querySelector('[aria-hidden="true"]')?.textContent).toBe('••••••');
    }
    expect(container.querySelector('[class*="phoneFigures"]')?.textContent).toContain('PRU');
    expect(container.querySelector('[data-closed], [class*="gain"], [class*="loss"]')).toBeNull();
  },
);

it('announces the masked fallback cost without hiding its missing valuation reason', () => {
  setAmountsHidden(true);
  renderList([position({ marketValue: null, unrealizedGain: null, valueReason: 'NO_VALUATION' })]);
  const fallback = document.querySelector('[class*="costFallback"]')!;
  expect(within(fallback as HTMLElement).getByText('Montant masqué')).toBeTruthy();
  expect(fallback.textContent).toContain('Coût');
  expect(fallback.textContent).not.toContain('320,60');
});
