import { setAmountsHidden } from '@/lib/amountPrivacy';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { datedListLoaded } from '@/components/ui/dated-list/datedListLoaded';
import {
  operationFixture,
  switchFixture,
} from '@/features/portfolio/portfolio-account/operations/operations-table/operationFixtures';
import { OperationDayList } from './OperationDayList';
import type { OperationRowActions } from './OperationRowMenu';

afterEach(() => {
  cleanup();
  setAmountsHidden(false);
});

const buy = operationFixture({
  id: 'op-buy',
  type: 'BUY',
  tradeDate: '2026-03-02',
  instrument: { id: 'i1', name: 'ETF Monde', isin: null, ticker: 'CW8', status: 'ACTIVE' },
  quantity: '10',
  unitPrice: '230.5688',
  grossAmount: { value: '2305.69', assetCode: 'EUR' },
  fees: { value: '1.99', assetCode: 'EUR' },
  cashAmount: { value: '-2307.68', assetCode: 'EUR' },
});

function actions(overrides: Partial<OperationRowActions> = {}): OperationRowActions {
  return {
    onDetail: vi.fn(),
    onEdit: vi.fn(),
    onReorder: vi.fn(),
    onVoid: vi.fn(),
    ...overrides,
  };
}

function renderList(items: Parameters<typeof OperationDayList>[0]['operations'], a = actions()) {
  return render(<OperationDayList actions={a} operations={items} />);
}

describe('OperationDayList', () => {
  it('groups operations under one heading per trade day, one card per day', async () => {
    renderList([buy, operationFixture({ id: 'other', tradeDate: '2026-03-01' })]);
    await datedListLoaded();

    expect(screen.getAllByRole('heading', { level: 3 })).toHaveLength(2);
    expect(screen.getByRole('list', { name: 'Opérations du portefeuille' })).toBeTruthy();
  });

  it('titles a row with the instrument, with the type, quantity and unit price as its meta', async () => {
    renderList([buy]);
    await datedListLoaded();

    const row = screen.getByText('ETF Monde').closest('li')!;
    expect(within(row).getByText('Achat · 10 × 230,5688')).toBeTruthy();
    expect(within(row).getByRole('img', { name: 'Frais' })).toBeTruthy();
    expect(within(row).queryByRole('img', { name: 'Taxes' })).toBeNull();
  });

  it('shows the signed cash effect as text, with its sign, on the right of the row', async () => {
    renderList([buy, operationFixture({ id: 'dep', tradeDate: '2026-03-01' })]);
    await datedListLoaded();

    const out = screen.getByText('ETF Monde').closest('li')!;
    expect(within(out).getByText(/2.307,68/).textContent).toContain('−');
    const inflow = screen.getByText('Versement', { selector: 'button' }).closest('li')!;
    expect(within(inflow).getByText(/^\+\s*1.000,00/)).toBeTruthy();
  });

  it('titles a transfer row Virement and an instrument-less row with its type', async () => {
    renderList([
      operationFixture({ id: 'a', type: 'DEPOSIT', linkedTransactionId: 'tx-1' }),
      operationFixture({ id: 'b', type: 'FEE', tradeDate: '2026-02-01' }),
    ]);
    await datedListLoaded();

    expect(screen.getByText('Virement', { selector: 'button' })).toBeTruthy();
    expect(screen.getByText('Frais', { selector: 'button' })).toBeTruthy();
  });

  it('summarises the legs of a switch in its meta line', async () => {
    renderList([switchFixture()]);
    await datedListLoaded();

    expect(screen.getByText(/1 supports vendus, 1 achetés/)).toBeTruthy();
  });

  it('greys a voided row and badges it, replaced or voided, disabling edit and void', async () => {
    renderList([
      operationFixture({ id: 'a', state: 'VOIDED', voidKind: 'VOIDED' }),
      operationFixture({ id: 'b', state: 'VOIDED', voidKind: 'REPLACED' }),
    ]);
    await datedListLoaded();

    expect(screen.getByText('Annulée').closest('li')!.className).toMatch(/muted/);
    expect(screen.getByText('Remplacée')).toBeTruthy();
    fireEvent.click(screen.getAllByRole('button', { name: /^Actions pour/ })[0]!);
    expect((screen.getByRole('button', { name: /^Modifier/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect((screen.getByRole('button', { name: /^Annuler/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
  });

  it('opens the detail from the row, from its title and from the menu, but not from the menu button', async () => {
    const a = actions();
    renderList([buy], a);
    await datedListLoaded();

    fireEvent.click(screen.getByText('Achat · 10 × 230,5688'));
    fireEvent.click(screen.getByRole('button', { name: 'ETF Monde' }));
    expect(a.onDetail).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));
    expect(a.onDetail).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: /^Détail/ }));
    expect(a.onDetail).toHaveBeenCalledWith(buy);
  });

  it('edits and voids from the menu', async () => {
    const a = actions();
    renderList([buy], a);
    await datedListLoaded();

    for (const [name, handler] of [
      ['Modifier', a.onEdit],
      ['Annuler', a.onVoid],
    ] as const) {
      fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));
      fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }));
      expect(handler).toHaveBeenCalledWith(buy);
    }
  });

  it('keeps the menu on a phone, where the dated list would otherwise hide it', async () => {
    renderList([buy]);
    await datedListLoaded();

    expect(screen.getByText('ETF Monde').closest('li')!.getAttribute('data-menu-phone')).toBe(
      'true',
    );
  });

  it('offers reordering only when the server reports at least two active operations that day', async () => {
    const a = actions();
    const { rerender } = renderList([{ ...buy, activeDayOperationCount: 1 }], a);
    await datedListLoaded();

    fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));
    expect(screen.queryByRole('button', { name: /^Réordonner la journée/ })).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));

    const reorderable = { ...buy, activeDayOperationCount: 2 };
    rerender(<OperationDayList actions={a} operations={[reorderable]} />);
    fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));
    fireEvent.click(screen.getByRole('button', { name: /^Réordonner la journée/ }));
    expect(a.onReorder).toHaveBeenCalledWith(reorderable);
  });

  it('mirrors the rows while loading, with no value and one announcement', () => {
    renderList(null);

    const skeleton = screen.getByTestId('operations-skeleton');
    expect(skeleton.getAttribute('aria-busy')).toBe('true');
    expect(within(skeleton).getAllByRole('listitem').length).toBeGreaterThan(3);
    expect(within(skeleton).getAllByText('Chargement des opérations…')).toHaveLength(1);
    expect(within(skeleton).queryByText(/€/)).toBeNull();
  });
});

it.each(['12', '-12', '0', '0.000000000000000000000001'])(
  'announces every masked operation cell for %s',
  async (value) => {
    setAmountsHidden(true);
    const amount = { value, assetCode: 'EUR' };
    renderList([
      {
        ...buy,
        quantity: value,
        unitPrice: value,
        grossAmount: amount,
        fees: amount,
        taxes: amount,
        cashAmount: amount,
      },
    ]);
    await datedListLoaded();
    const row = screen.getByRole('button', { name: /^ETF Monde$/ }).closest('li')!;
    expect(within(row).getAllByText('Montant masqué')).toHaveLength(5);
    expect(row.querySelector('[class*="positive"], [class*="negative"]')).toBeNull();
    expect(row.textContent).not.toMatch(/[0-9+−]/);
  },
);
