import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { SupportLegsField } from './SupportLegsField';
import { FullSurrenderPrefill } from './FullSurrenderPrefill';
import type { SupportLegValue } from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';
import {
  operationFieldErrors,
  operationRequestError,
} from '@/features/portfolio/portfolio-account/operations/operationError';
const api = vi.hoisted(() => ({
  listInstruments: vi.fn(),
  readInstrument: vi.fn(),
  listPortfolioPositions: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = (data: unknown) => ({ data, response: new Response('{}', { status: 200 }) });
const originalWidth = window.innerWidth;
const euro = { id: 'euro', name: 'Fonds euros', instrumentType: 'EURO_FUND' };
const units = { id: 'units', name: 'Fonds actions', instrumentType: 'FUND' };
function Wrapper({ children }: { children: React.ReactNode }) {
  return (
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      {children}
    </QueryClientProvider>
  );
}
function Editor() {
  const [value, setValue] = useState<SupportLegValue[]>([]);
  return <SupportLegsField assetCode="EUR" errors={{}} value={value} onChange={setValue} />;
}
beforeEach(() => {
  api.listInstruments.mockResolvedValue(ok({ items: [euro, units] }));
  api.readInstrument.mockImplementation(({ path }) =>
    Promise.resolve(ok(path.id === 'euro' ? euro : units)),
  );
  api.listPortfolioPositions.mockResolvedValue(
    ok({
      items: [
        { instrument: euro, quantity: '6000.00' },
        { instrument: units, quantity: '999999999999999999.123456789012345678901234' },
      ],
    }),
  );
});
afterEach(() => {
  cleanup();
  window.innerWidth = originalWidth;
  vi.clearAllMocks();
});
describe('support editor', () => {
  it('selects a euro fund by keyboard, hides parts and price, and removes the row', async () => {
    window.innerWidth = 360;
    render(<Editor />, { wrapper: Wrapper });
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter un support' }));
    const picker = screen.getByRole('combobox', { name: 'Support 1' });
    fireEvent.focus(picker);
    await screen.findByRole('option', { name: /Fonds euros/ });
    fireEvent.keyDown(picker, { key: 'Enter' });
    expect(screen.queryByLabelText('Parts 1')).toBeNull();
    expect(screen.queryByLabelText('Valeur liquidative 1')).toBeNull();
    expect(screen.getByLabelText('Montant 1')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Retirer ce support' }));
    expect(screen.queryByLabelText('Montant 1')).toBeNull();
  });
  it('places the exact server mismatch on the corresponding row', () => {
    const errors = operationFieldErrors(
      operationRequestError({
        response: new Response('{}', { status: 422 }),
        error: { code: 'leg_amount_mismatch', legIndex: 2, expectedAmount: '3900.00' },
      }),
    );
    const value = [euro, units].map((instrument) => ({
      key: instrument.id,
      instrument,
      quantity: '40',
      unitPrice: '97.50',
      amount: '3901',
    }));
    render(<SupportLegsField assetCode="EUR" errors={errors} value={value} onChange={vi.fn()} />, {
      wrapper: Wrapper,
    });
    expect(screen.getByRole('alert').textContent).toContain('3900.00');
    expect(errors['legs.2.amount']).toContain('3900.00');
  });
  it('resolves an edited euro-fund support before sending its amount-only mode', async () => {
    function Edited() {
      const [value, setValue] = useState<SupportLegValue[]>([
        {
          key: 'euro',
          instrument: { id: 'euro', name: 'Fonds euros' },
          quantity: '6000',
          unitPrice: '1',
          amount: '6000',
        },
      ]);
      return <SupportLegsField assetCode="EUR" errors={{}} value={value} onChange={setValue} />;
    }
    render(<Edited />, { wrapper: Wrapper });
    await waitFor(() => expect(screen.queryByLabelText('Parts 1')).toBeNull());
  });
});
describe('full surrender prefill', () => {
  it('loads as-of positions and copies very large exact quantities without computing prices', async () => {
    const onPrefill = vi.fn();
    render(<FullSurrenderPrefill accountId="a" tradeDate="2026-06-10" onPrefill={onPrefill} />, {
      wrapper: Wrapper,
    });
    expect(screen.getByRole('button', { name: 'Reprendre toutes les positions' })).toBeTruthy();
    await waitFor(() =>
      expect(
        (
          screen.getByRole('button', {
            name: 'Reprendre toutes les positions',
          }) as HTMLButtonElement
        ).disabled,
      ).toBe(false),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Reprendre toutes les positions' }));
    expect(api.listPortfolioPositions.mock.calls[0]![0].query.asOf).toBe('2026-06-10');
    expect(onPrefill.mock.calls[0]![0]).toMatchObject([
      { instrument: euro, quantity: '6000.00', amount: '6000.00', unitPrice: '' },
      {
        instrument: units,
        quantity: '999999999999999999.123456789012345678901234',
        amount: '',
        unitPrice: '',
      },
    ]);
  });
  it('shows empty and error states with retry', async () => {
    api.listPortfolioPositions
      .mockResolvedValueOnce({ response: new Response('{}', { status: 500 }) })
      .mockResolvedValue(ok({ items: [] }));
    render(<FullSurrenderPrefill accountId="a" tradeDate="2026-06-10" onPrefill={vi.fn()} />, {
      wrapper: Wrapper,
    });
    fireEvent.click(await screen.findByRole('button', { name: 'Réessayer' }));
    expect(await screen.findByText('Aucune position détenue à cette date.')).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Reprendre toutes les positions' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
  });
});
