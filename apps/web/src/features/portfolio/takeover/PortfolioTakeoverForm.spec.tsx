import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PortfolioTakeoverForm } from './PortfolioTakeoverForm';

const api = vi.hoisted(() => ({ previewPortfolioTakeover: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
// The picker has its own specs: here it only hands back an instrument and records its props.
const picker = vi.hoisted(() => ({ props: [] as Array<Record<string, unknown>> }));
vi.mock('@/features/portfolio/instrument-picker/InstrumentPicker', () => ({
  InstrumentPicker: (props: {
    label: string;
    onChange: (item: { id: string; name: string } | null) => void;
  }) => {
    picker.props.push(props);
    return (
      <button type="button" onClick={() => props.onChange({ id: 'i-1', name: 'World ETF' })}>
        Choisir un instrument
      </button>
    );
  },
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  picker.props.length = 0;
});

const preview = {
  lines: [{ instrumentId: 'i-1', value: '507.50', averageUnitCost: '96.0000' }],
  securitiesValue: '507.50',
  cash: '120.00',
  totalValue: '627.50',
  errors: [],
};

function renderForm(props: Partial<React.ComponentProps<typeof PortfolioTakeoverForm>> = {}) {
  const onSubmit = vi.fn();
  render(
    <QueryClientProvider client={new QueryClient()}>
      <PortfolioTakeoverForm
        account={{ id: 'acc-1', assetCode: 'EUR' }}
        defaultDate="2026-10-31"
        onEdit={vi.fn()}
        onSubmit={onSubmit}
        pending={false}
        {...props}
      />
    </QueryClientProvider>,
  );
  return onSubmit;
}

function fillFirstRow() {
  fireEvent.click(screen.getByRole('button', { name: 'Choisir un instrument' }));
  fireEvent.change(screen.getByLabelText('Quantité'), { target: { value: '5' } });
  expect(document.querySelector('a[href^="/investments/instruments/"]')).toBeNull();
  fireEvent.change(screen.getByLabelText('Coût de revient total'), { target: { value: '480' } });
  fireEvent.change(screen.getByLabelText('Cours'), { target: { value: '101.5' } });
}

describe('PortfolioTakeoverForm', () => {
  it('starts with the default date, one row and the cash of that date', () => {
    renderForm();
    expect(screen.getByLabelText('Date de reprise') as HTMLInputElement).toHaveProperty(
      'value',
      '2026-10-31',
    );
    expect(screen.getByLabelText('Date du cours') as HTMLInputElement).toHaveProperty(
      'value',
      '2026-10-31',
    );
    expect(
      screen.getByText(
        'La date de votre dernier relevé. La performance est calculée à partir de cette date.',
      ),
    ).toBeTruthy();
    expect(screen.getByText('Laissez vide si inconnu.')).toBeTruthy();
    expect(screen.getByLabelText('Espèces au 31 octobre 2026')).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Retirer la ligne 1' }) as HTMLButtonElement).disabled,
    ).toBe(true);
    expect(picker.props[0]).toMatchObject({ allowCreate: true, currencyCode: 'EUR' });
    expect(
      (screen.getByRole('button', { name: 'Enregistrer la reprise' }) as HTMLButtonElement)
        .disabled,
    ).toBe(false);
  });

  it('adds and removes rows, keeping the others', () => {
    renderForm();
    fireEvent.change(screen.getByLabelText('Quantité'), { target: { value: '7' } });
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une ligne' }));
    expect(screen.getAllByLabelText('Quantité')).toHaveLength(2);
    expect(
      (screen.getByRole('button', { name: 'Retirer la ligne 2' }) as HTMLButtonElement).disabled,
    ).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Retirer la ligne 2' }));
    expect(screen.getAllByLabelText('Quantité')).toHaveLength(1);
    expect(screen.getByLabelText('Quantité') as HTMLInputElement).toHaveProperty('value', '7');
  });

  it('shows the server values, unit cost and totals of a complete request', async () => {
    api.previewPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 200 }),
      data: preview,
    });
    renderForm();
    fillFirstRow();
    fireEvent.change(screen.getByLabelText(/^Espèces au/), { target: { value: '120' } });
    const row = screen.getByRole('group', { name: 'Ligne 1' });
    await waitFor(() => expect(within(row).getByText(/507,50/)).toBeTruthy());
    expect(within(row).getByText(/96/)).toBeTruthy();
    const summary = screen.getByRole('group', { name: 'Résumé de la reprise' });
    expect(within(summary).getByText('Titres')).toBeTruthy();
    expect(within(summary).getByText(/627,50/)).toBeTruthy();
    expect(api.previewPortfolioTakeover).toHaveBeenCalledWith(
      expect.objectContaining({
        body: {
          takenOverOn: '2026-10-31',
          cash: '120',
          lines: [
            {
              instrumentId: 'i-1',
              quantity: '5',
              costBasis: '480',
              price: '101.5',
              priceDate: null,
            },
          ],
        },
      }),
    );
  });

  it('keeps submitting possible when the preview is unavailable', async () => {
    api.previewPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 500 }),
      error: {},
    });
    const onSubmit = renderForm();
    fillFirstRow();
    expect(await screen.findByText('Aperçu indisponible.')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la reprise' }));
    expect(onSubmit).toHaveBeenCalledTimes(1);
  });

  it('refuses an incomplete row on submit and says where', () => {
    const onSubmit = renderForm();
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer la reprise' }));
    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByText('Choisissez un instrument.')).toBeTruthy();
    expect(screen.getByText('Saisissez une quantité strictement positive.')).toBeTruthy();
    expect(screen.getByLabelText('Quantité').getAttribute('aria-invalid')).toBe('true');
  });

  it('shows the errors the preview and the server report on their row', async () => {
    api.previewPortfolioTakeover.mockResolvedValue({
      response: new Response('{}', { status: 200 }),
      data: {
        ...preview,
        lines: [{ instrumentId: 'i-1', value: null, averageUnitCost: null }],
        securitiesValue: null,
        totalValue: null,
        errors: [{ line: 1, field: 'price', code: 'price_precision_exceeded' }],
      },
    });
    renderForm({
      serverErrors: {
        lines: { 0: { quantity: 'La quantité dépasse la précision de l’instrument.' } },
      },
    });
    fillFirstRow();
    expect(await screen.findByText('Le cours dépasse la précision de l’instrument.')).toBeTruthy();
    expect(screen.getByText('La quantité dépasse la précision de l’instrument.')).toBeTruthy();
  });

  it('marks the rows to stack their fields on a narrow screen', () => {
    renderForm();
    expect(screen.getByRole('group', { name: 'Ligne 1' }).className).toMatch(/row/);
  });

  it('announces edits and labels the pending submit', () => {
    const onEdit = vi.fn();
    renderForm({ onEdit, pending: true });
    fireEvent.change(screen.getByLabelText('Date de reprise'), { target: { value: '2026-10-30' } });
    expect(onEdit).toHaveBeenCalled();
    expect(
      (screen.getByRole('button', { name: 'Enregistrement…' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });
});
