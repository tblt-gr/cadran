import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { operationFixture } from './operationFixtures';
import { OperationsTable } from './OperationsTable';

afterEach(cleanup);

const buy = operationFixture({
  id: 'op-buy',
  type: 'BUY',
  instrument: { id: 'i1', name: 'ETF Monde', isin: null, ticker: 'CW8', status: 'ACTIVE' },
  quantity: '10',
  unitPrice: '230.5688',
  grossAmount: { value: '2305.69', assetCode: 'EUR' },
  fees: { value: '1.99', assetCode: 'EUR' },
  cashAmount: { value: '-2307.68', assetCode: 'EUR' },
});

describe('OperationsTable', () => {
  it('shows the signed cash effect as text with the exact quantity and price', () => {
    render(
      <OperationsTable
        items={[buy, operationFixture()]}
        onDetail={vi.fn()}
        onEdit={vi.fn()}
        onVoid={vi.fn()}
      />,
    );
    const rows = screen.getAllByRole('row');
    const buyRow = within(rows[1]!);
    expect(buyRow.getByText(/2.307,68/).textContent).toContain('−');
    expect(buyRow.getByText('10')).toBeTruthy();
    expect(buyRow.getByText('230,5688')).toBeTruthy();
    expect(
      within(rows[2]!)
        .getAllByText(/1.000,00/)
        .at(-1)!.textContent,
    ).toContain('+');
    for (const header of [
      'Date',
      'Type',
      'Instrument',
      'Quantité',
      'Prix unitaire',
      'Montant brut',
      'Frais',
      'Taxes',
      'Effet espèces',
    ]) {
      expect(screen.getByRole('columnheader', { name: header })).toBeTruthy();
    }
  });

  it('badges voided and replaced rows and disables their edit and void actions', () => {
    render(
      <OperationsTable
        items={[
          operationFixture({ id: 'a', state: 'VOIDED', voidKind: 'VOIDED' }),
          operationFixture({ id: 'b', state: 'VOIDED', voidKind: 'REPLACED' }),
        ]}
        onDetail={vi.fn()}
        onEdit={vi.fn()}
        onVoid={vi.fn()}
      />,
    );
    expect(screen.getByText('Annulée')).toBeTruthy();
    expect(screen.getByText('Remplacée')).toBeTruthy();
    fireEvent.click(screen.getAllByRole('button', { name: /^Actions pour/ })[0]!);
    expect((screen.getByRole('button', { name: /^Modifier/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect((screen.getByRole('button', { name: /^Annuler/ }) as HTMLButtonElement).disabled).toBe(
      true,
    );
  });

  it('opens the detail, edit and void actions of an active row', () => {
    const onDetail = vi.fn();
    const onEdit = vi.fn();
    const onVoid = vi.fn();
    render(<OperationsTable items={[buy]} onDetail={onDetail} onEdit={onEdit} onVoid={onVoid} />);
    for (const [name, handler] of [
      ['Détail', onDetail],
      ['Modifier', onEdit],
      ['Annuler', onVoid],
    ] as const) {
      fireEvent.click(screen.getByRole('button', { name: /^Actions pour/ }));
      fireEvent.click(screen.getByRole('button', { name: new RegExp(`^${name}`) }));
      expect(handler).toHaveBeenCalledWith(buy);
    }
  });
});
