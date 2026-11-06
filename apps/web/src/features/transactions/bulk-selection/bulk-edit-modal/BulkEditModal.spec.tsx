import type { Account, Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { BulkItem, BulkScope } from '@/features/transactions/bulk-selection/useBulkSelection';
import { BulkEditModal } from './BulkEditModal';

const api = vi.hoisted(() => ({
  bulkEditTransactions: vi.fn(),
  listAccounts: vi.fn(),
  listCategories: vi.fn(),
}));

vi.mock('@/features/auth/useWorkspaceTimeZone', () => ({ useWorkspaceTimeZone: () => 'UTC' }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const id = (n: number) => `00000000-0000-7000-8000-${String(n).padStart(12, '0')}`;
const CATEGORY = id(9001);

function item(n: number, rawLabel: string, displayLabel = rawLabel): BulkItem {
  return {
    id: id(n),
    bookedOn: '2026-09-11',
    amount: { value: '-12.34', assetCode: 'EUR' },
    rawLabel,
    displayLabel,
  };
}

const category = {
  id: CATEGORY,
  type: 'EXPENSE',
  label: 'Courses',
  archivedAt: null,
  effectiveColor: null,
  effectiveIcon: null,
} as unknown as Category;

function ok<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function refused(status: number, body: Record<string, unknown>) {
  return Promise.resolve({
    data: undefined,
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

const account = (n: number, label: string, assetCode: string, valuationMode = 'TRANSACTIONS') =>
  ({ id: id(n), label, assetCode, valuationMode, status: 'ACTIVE' }) as unknown as Account;
const accounts = [
  account(101, 'Courant', 'EUR'),
  account(102, 'Joint', 'EUR'),
  account(103, 'Compte USD', 'USD'),
  account(104, 'Portefeuille', 'EUR', 'PORTFOLIO'),
];

const pageItems = [
  item(1, 'PRLV SEPA FREE MOBILE XPZ1', 'Free'),
  item(2, 'PRLV SEPA FREE MOBILE XPZ2'),
  item(3, 'CB CARREFOUR'),
];

function renderModal(scope: BulkScope, items: readonly BulkItem[] = pageItems) {
  const close = vi.fn();
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <BulkEditModal
        close={close}
        pageItems={items}
        reloadAfterWrite={() => Promise.resolve()}
        scope={scope}
      />
    </QueryClientProvider>,
  );
  return { close, dialog: screen.getByRole('dialog') };
}

const ids = (...n: number[]): BulkScope => ({ type: 'IDS', ids: n.map(id) });
const tick = (name: string) => fireEvent.click(screen.getByRole('checkbox', { name }));
const apply = () => screen.getByRole('button', { name: 'Appliquer' }) as HTMLButtonElement;

describe('BulkEditModal', () => {
  beforeEach(() => {
    api.listCategories.mockImplementation(() =>
      ok({ items: [category], page: 1, pageSize: 50, total: 1 }),
    );
    api.listAccounts.mockImplementation(() =>
      ok({ items: accounts, page: 1, pageSize: 100, total: 4 }),
    );
    api.bulkEditTransactions.mockImplementation(() =>
      ok({
        requestedCount: 3,
        changed: 3,
        fieldsChanged: [],
        skipped: [],
        impactedAccountIds: [],
      }),
    );
  });
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('keeps every control disabled until its checkbox is ticked and Appliquer until one is', () => {
    renderModal(ids(1, 2, 3));

    expect(screen.getByRole('dialog', { name: 'Modifier 3 transactions' })).toBeTruthy();
    expect(apply().disabled).toBe(true);
    expect((screen.getByLabelText('Moyen de paiement') as HTMLSelectElement).disabled).toBe(true);
    expect((screen.getByLabelText('Note') as HTMLTextAreaElement).disabled).toBe(true);
    expect((screen.getByLabelText('Tiers') as HTMLInputElement).disabled).toBe(true);
    // Inside a disabled fieldset: inert without carrying the attribute itself.
    expect(screen.getByLabelText('Libellé').matches(':disabled')).toBe(true);

    tick('Modifier la note');
    expect((screen.getByLabelText('Note') as HTMLTextAreaElement).disabled).toBe(false);
    expect(apply().disabled).toBe(false);
    tick('Modifier la note');
    expect(apply().disabled).toBe(true);
  });

  it('sends only the ticked rows, an empty note or counterparty clearing the field', async () => {
    renderModal(ids(1, 2));
    tick('Modifier le mode de paiement');
    fireEvent.change(screen.getByLabelText('Moyen de paiement'), { target: { value: 'CARD' } });
    tick('Modifier la note');
    tick('Modifier la contrepartie');
    fireEvent.change(screen.getByLabelText('Tiers'), { target: { value: 'ignored then ticked' } });
    fireEvent.click(apply());

    await waitFor(() => expect(api.bulkEditTransactions).toHaveBeenCalledTimes(1));
    expect(api.bulkEditTransactions.mock.calls[0]?.[0].body).toEqual({
      changes: { paymentMethod: 'CARD', note: null, counterparty: 'ignored then ticked' },
      scope: { type: 'IDS', ids: [id(1), id(2)] },
    });
    expect(await screen.findByText('3 transactions modifiées')).toBeTruthy();
  });

  it('clears the payment method with Aucun', async () => {
    renderModal(ids(1));
    tick('Modifier le mode de paiement');
    fireEvent.click(apply());

    await waitFor(() => expect(api.bulkEditTransactions).toHaveBeenCalled());
    expect(api.bulkEditTransactions.mock.calls[0]?.[0].body.changes).toEqual({
      paymentMethod: null,
    });
  });

  it('applies one label to all and lists at most ten current labels', async () => {
    const many = Array.from({ length: 12 }, (_, index) => item(index + 1, `LIBELLE ${index + 1}`));
    renderModal(ids(...many.map((_, index) => index + 1)), many);
    tick('Modifier le libellé');

    const list = screen.getByRole('list', { name: 'Libellés actuels' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(11);
    expect(within(list).getByText('et 2 autres')).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Free Mobile' } });
    fireEvent.click(apply());

    await waitFor(() => expect(api.bulkEditTransactions).toHaveBeenCalled());
    expect(api.bulkEditTransactions.mock.calls[0]?.[0].body.changes).toEqual({
      label: { mode: 'SAME', value: 'Free Mobile' },
    });
  });

  it('offers one line per transaction prefilled with its display label', async () => {
    renderModal(ids(1, 2));
    tick('Modifier le libellé');
    fireEvent.click(screen.getByRole('button', { name: 'Un par un' }));

    const first = screen.getByLabelText(
      'Libellé de PRLV SEPA FREE MOBILE XPZ1',
    ) as HTMLInputElement;
    const second = screen.getByLabelText(
      'Libellé de PRLV SEPA FREE MOBILE XPZ2',
    ) as HTMLInputElement;
    expect(first.value).toBe('Free');
    expect(second.value).toBe('PRLV SEPA FREE MOBILE XPZ2');
    expect(screen.getAllByText(/12,34/)).toHaveLength(2);
    fireEvent.change(second, { target: { value: 'Deuxième' } });
    fireEvent.click(apply());

    await waitFor(() => expect(api.bulkEditTransactions).toHaveBeenCalled());
    expect(api.bulkEditTransactions.mock.calls[0]?.[0].body.changes).toEqual({
      label: {
        mode: 'EACH',
        values: [
          { id: id(1), value: 'Free' },
          { id: id(2), value: 'Deuxième' },
        ],
      },
    });
  });

  it('disables Un par un above 100 selected transactions and for a filter scope', () => {
    const big = Array.from({ length: 101 }, (_, index) => item(index + 1, `L${index + 1}`));
    const { dialog } = renderModal(ids(...big.map((_, index) => index + 1)), big);
    const each = within(dialog).getByRole('button', { name: 'Un par un' }) as HTMLButtonElement;

    expect(each.disabled).toBe(true);
    expect(
      within(dialog).getByText('Disponible pour 100 transactions sélectionnées au plus.'),
    ).toBeTruthy();
  });

  it('states the filter scope and asks again with the new count when it changed', async () => {
    api.bulkEditTransactions
      .mockImplementationOnce(() =>
        refused(409, {
          type: '/problems/transactions.bulk-scope-changed',
          status: 409,
          actualCount: 125,
        }),
      )
      .mockImplementationOnce(() =>
        ok({ requestedCount: 125, changed: 125, fieldsChanged: ['note'], skipped: [] }),
      );
    renderModal({ type: 'FILTER', filters: { q: 'free' }, expectedCount: 120 });
    expect(
      screen.getByText(
        "Ces modifications s'appliqueront aux 120 transactions correspondant aux filtres.",
      ),
    ).toBeTruthy();
    expect((screen.getByRole('button', { name: 'Un par un' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    tick('Modifier la note');
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'garder' } });
    fireEvent.click(apply());

    expect(await screen.findByText(/Le nombre de mouvements a changé : 125/)).toBeTruthy();
    expect((screen.getByLabelText('Note') as HTMLTextAreaElement).value).toBe('garder');
    expect(screen.getByText(/aux 125 transactions/)).toBeTruthy();
    fireEvent.click(apply());

    await waitFor(() => expect(api.bulkEditTransactions).toHaveBeenCalledTimes(2));
    expect(api.bulkEditTransactions.mock.calls[1]?.[0].body.scope).toEqual({
      type: 'FILTER',
      filters: { q: 'free' },
      expectedCount: 125,
    });
  });

  it('keeps the modal open with the message and the ticked values on an API error', async () => {
    api.bulkEditTransactions.mockImplementation(() => refused(500, { status: 500 }));
    renderModal(ids(1));
    tick('Modifier la note');
    fireEvent.change(screen.getByLabelText('Note'), { target: { value: 'à garder' } });
    fireEvent.click(apply());

    expect((await screen.findByRole('alert')).textContent).toMatch(/n’a pas abouti/);
    expect((screen.getByLabelText('Note') as HTMLTextAreaElement).value).toBe('à garder');
    expect(
      (screen.getByRole('checkbox', { name: 'Modifier la note' }) as HTMLInputElement).checked,
    ).toBe(true);
  });

  it('shows the category picker inside the modal and requires a category once ticked', async () => {
    renderModal(ids(1));
    tick('Modifier la catégorie');
    expect(apply().disabled).toBe(true);
    fireEvent.focus(screen.getByRole('combobox', { name: 'Catégorie' }));
    fireEvent.mouseDown(await screen.findByRole('option', { name: 'Courses' }));
    expect(apply().disabled).toBe(false);
  });

  it('groups the skipped rows by reason in the result', async () => {
    api.bulkEditTransactions.mockImplementation(() =>
      ok({
        requestedCount: 3,
        changed: 1,
        fieldsChanged: ['label'],
        skipped: [
          { id: id(2), reason: 'LABEL_REQUIRED' },
          { id: id(3), reason: 'TRANSFER_LEG' },
        ],
      }),
    );
    renderModal(ids(1, 2, 3));
    tick('Modifier le libellé');
    fireEvent.click(apply());

    expect(await screen.findByText('1 transaction modifiée')).toBeTruthy();
    expect(screen.getByText('Libellé obligatoire pour une saisie manuelle')).toBeTruthy();
    expect(screen.getByText('Jambe de virement : modifiez le virement')).toBeTruthy();
  });

  describe('move', () => {
    const previewBody = {
      eligibleCount: 3,
      skipped: [],
      impacts: [
        {
          accountId: id(101),
          label: 'Courant',
          assetCode: 'EUR',
          delta: '37.02',
          from: '2026-09-11',
          to: null,
        },
        {
          accountId: id(102),
          label: 'Joint',
          assetCode: 'EUR',
          delta: '-37.02',
          from: '2026-09-11',
          to: null,
        },
      ],
      previewToken: 'tok-1',
    };
    const applied = {
      requestedCount: 3,
      changed: 3,
      fieldsChanged: ['accountId'],
      skipped: [],
      impactedAccountIds: [id(101), id(102)],
    };

    async function pickJoint() {
      tick('Modifier le compte');
      const select = (await screen.findByLabelText('Compte cible')) as HTMLSelectElement;
      await waitFor(() => expect(select.options.length).toBeGreaterThan(1));
      return select;
    }

    it('offers only active, non portfolio accounts of the selection asset', async () => {
      renderModal(ids(1, 2, 3));
      const select = await pickJoint();

      expect([...select.options].map((option) => option.textContent)).toEqual([
        'Choisir un compte',
        'Courant',
        'Joint',
      ]);
    });

    it('disables the account change with a hint when the selection mixes assets', () => {
      const mixed = [
        item(1, 'A'),
        { ...item(2, 'B'), amount: { value: '-5.00', assetCode: 'USD' } },
      ];
      renderModal(ids(1, 2), mixed);

      expect(
        (screen.getByRole('checkbox', { name: 'Modifier le compte' }) as HTMLInputElement).disabled,
      ).toBe(true);
      expect(
        screen.getByText('Sélection en plusieurs actifs : changez le compte par actif.'),
      ).toBeTruthy();
    });

    it('previews without an idempotency key, then applies with the token and a key', async () => {
      api.bulkEditTransactions
        .mockImplementationOnce(() => ok(previewBody))
        .mockImplementationOnce(() => ok(applied));
      renderModal(ids(1, 2, 3));
      const select = await pickJoint();
      fireEvent.change(select, { target: { value: id(102) } });
      fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser' }));

      expect(await screen.findByText('3 transactions seront déplacées.')).toBeTruthy();
      const previewCall = api.bulkEditTransactions.mock.calls[0]?.[0];
      expect(previewCall.body).toEqual({
        changes: { accountId: id(102) },
        scope: { type: 'IDS', ids: [id(1), id(2), id(3)] },
        preview: true,
      });
      expect(previewCall.headers['Idempotency-Key']).toBeUndefined();

      fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }));
      expect(await screen.findByText('3 transactions modifiées')).toBeTruthy();
      const applyCall = api.bulkEditTransactions.mock.calls[1]?.[0];
      expect(applyCall.body).toEqual({
        changes: { accountId: id(102) },
        scope: { type: 'IDS', ids: [id(1), id(2), id(3)] },
        previewToken: 'tok-1',
      });
      expect(applyCall.headers['Idempotency-Key']).toBeTruthy();
    });

    it('goes back from the preview to the form with the draft kept', async () => {
      api.bulkEditTransactions.mockImplementationOnce(() => ok(previewBody));
      renderModal(ids(1, 2, 3));
      const select = await pickJoint();
      fireEvent.change(select, { target: { value: id(102) } });
      fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser' }));
      await screen.findByText('3 transactions seront déplacées.');
      fireEvent.click(screen.getByRole('button', { name: 'Retour' }));

      expect(((await screen.findByLabelText('Compte cible')) as HTMLSelectElement).value).toBe(
        id(102),
      );
    });

    it('shows the refreshed preview with a notice when the token is stale', async () => {
      api.bulkEditTransactions
        .mockImplementationOnce(() => ok(previewBody))
        .mockImplementationOnce(() =>
          refused(409, { type: '/problems/transactions.bulk-preview-stale' }),
        )
        .mockImplementationOnce(() =>
          ok({ ...previewBody, eligibleCount: 2, previewToken: 'tok-2' }),
        );
      renderModal(ids(1, 2, 3));
      const select = await pickJoint();
      fireEvent.change(select, { target: { value: id(102) } });
      fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser' }));
      await screen.findByText('3 transactions seront déplacées.');
      fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }));

      expect(await screen.findByText('2 transactions seront déplacées.')).toBeTruthy();
      expect(screen.getByText(/aperçu actualisé/)).toBeTruthy();
      expect(api.bulkEditTransactions.mock.calls[2]?.[0].body.preview).toBe(true);
    });

    it('sends the new booked day with the preview', async () => {
      api.bulkEditTransactions.mockImplementationOnce(() => ok(previewBody));
      renderModal(ids(1, 2, 3));
      tick("Modifier la date d'opération");
      fireEvent.change(screen.getByLabelText("Date d'opération"), {
        target: { value: '2026-10-20' },
      });
      fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser' }));

      await screen.findByText('3 transactions seront déplacées.');
      expect(api.bulkEditTransactions.mock.calls[0]?.[0].body.changes).toEqual({
        bookedOn: '2026-10-20',
      });
    });

    it('keeps the modal open with the message when the preview is refused', async () => {
      api.bulkEditTransactions.mockImplementationOnce(() => refused(404, { status: 404 }));
      renderModal(ids(1, 2, 3));
      const select = await pickJoint();
      fireEvent.change(select, { target: { value: id(102) } });
      fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser' }));

      expect((await screen.findByRole('alert')).textContent).toContain('Vérifiez');
      expect(screen.getByRole('button', { name: 'Prévisualiser' })).toBeTruthy();
    });
  });
});
