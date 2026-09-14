import type { Account, Recurrence, RecurrenceCandidate } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { RecurrencesPage } from './RecurrencesPage';

const api = vi.hoisted(() => ({
  archiveRecurrence: vi.fn(),
  confirmRecurrence: vi.fn(),
  detectRecurrenceCandidates: vi.fn(),
  dismissRecurrenceCandidate: vi.fn(),
  listAccounts: vi.fn(),
  listRecurrenceOccurrences: vi.fn(),
  listRecurrences: vi.fn(),
  restoreRecurrence: vi.fn(),
  restoreRecurrenceCandidate: vi.fn(),
  updateRecurrence: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function success<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

const account: Account = {
  id: '00000000-0000-7000-8000-000000000011',
  label: 'Compte courant',
  assetCode: 'EUR',
  kind: 'CURRENT',
  productCode: null,
  productModelId: null,
  institution: null,
  maskedIdentifier: null,
  valuationMode: 'TRANSACTIONS',
  liquidityLevel: 'IMMEDIATE',
  includeInNetWorth: true,
  includeInEmergencyFund: false,
  openedOn: '2025-01-01',
  closedOn: null,
  status: 'ACTIVE',
  netWorthSign: 1,
  used: false,
  editable: true,
  kindEditable: true,
  kindEditReason: null,
  version: 1,
  archivedAt: null,
  primaryGroupId: null,
  tagGroupIds: [],
  share: { ratio: null, percent: null, percentDisplay: null, reason: 'MISSING_VALUATION' },
  valuation: {
    accountId: '00000000-0000-7000-8000-000000000011',
    requestedOn: '2026-06-04',
    asOf: '2026-06-04',
    amount: { value: '100.00', assetCode: 'EUR' },
    display: { value: '100.00', assetCode: 'EUR' },
    belowDisplayStep: false,
    source: 'MANUAL',
    ageDays: 0,
    quality: 'CURRENT',
    reconciliationStatus: 'UNRECONCILED',
    snapshotId: '00000000-0000-7000-8000-000000000012',
    version: 1,
  },
};

const candidate: RecurrenceCandidate = {
  fingerprint: 'a'.repeat(64),
  accountId: account.id,
  counterparty: 'Netflix',
  intervalKind: 'MONTHLY',
  medianAmount: { value: '-14.99', assetCode: 'EUR' },
  tolerance: { value: '0.30', assetCode: 'EUR' },
  occurrenceCount: 6,
  firstSeenOn: '2026-01-04',
  lastSeenOn: '2026-06-04',
  confidence: 'HIGH',
  confidenceReason: null,
};

const archivedRecurrence: Recurrence = {
  id: '00000000-0000-7000-8000-000000000013',
  accountId: account.id,
  label: 'Netflix',
  counterparty: 'Netflix',
  expectedAmount: { value: '-14.99', assetCode: 'EUR' },
  amountTolerance: { value: '0.30', assetCode: 'EUR' },
  intervalKind: 'MONTHLY',
  dayOfPeriod: 4,
  nextExpectedOn: '2026-07-04',
  confirmedAt: '2026-06-04T09:00:00+02:00',
  createdAt: '2026-06-04T09:00:00+02:00',
  updatedAt: '2026-06-04T09:00:00+02:00',
  version: 2,
  archivedAt: '2026-06-05T09:00:00+02:00',
};

function renderPage() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <RecurrencesPage />
    </QueryClientProvider>,
  );
}

describe('RecurrencesPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('explains that a no-candidate scan leaves the books untouched', async () => {
    api.detectRecurrenceCandidates.mockImplementation(() => success({ items: [], partial: false }));
    api.listRecurrences.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    renderPage();

    expect(
      await screen.findByRole('heading', { name: 'Aucune proposition à confirmer' }),
    ).toBeTruthy();
    expect(screen.getByText(/n’est comptée dans aucun total enregistré/)).toBeTruthy();
    expect(screen.getByRole('heading', { name: 'Aucune récurrence confirmée' })).toBeTruthy();
  });

  it('opens a shared confirmation modal and submits exact server strings', async () => {
    api.detectRecurrenceCandidates.mockImplementation(() =>
      success({ items: [candidate], partial: false }),
    );
    api.listRecurrences.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.confirmRecurrence.mockImplementation(({ body }) =>
      success({ id: 'recurrence', ...body }, 201),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Confirmer' }));
    expect(screen.getByRole('dialog', { name: 'Nouvelle récurrence' })).toBeTruthy();
    fireEvent.change(screen.getByLabelText('Jour de période'), { target: { value: '4' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(api.confirmRecurrence).toHaveBeenCalledOnce());
    expect(api.confirmRecurrence.mock.calls[0]?.[0].body).toMatchObject({
      accountId: account.id,
      amountTolerance: '0.30',
      expectedAmount: '-14.99',
      intervalKind: 'MONTHLY',
    });
  });

  it('shows archived recurrences on demand and opens occurrences in the shared modal', async () => {
    api.detectRecurrenceCandidates.mockImplementation(() => success({ items: [], partial: false }));
    api.listRecurrences.mockImplementation(({ query }) =>
      query.includeArchived
        ? success({ items: [archivedRecurrence], page: 1, perPage: 100, total: 1 })
        : success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.listRecurrenceOccurrences.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Voir les récurrences archivées' }));
    expect(await screen.findByRole('heading', { name: 'Récurrences archivées' })).toBeTruthy();

    fireEvent.click(await screen.findByRole('button', { name: 'Voir les échéances' }));
    expect(await screen.findByRole('dialog', { name: 'Échéances de Netflix' })).toBeTruthy();
    expect(screen.queryByRole('dialog', { name: 'Nouvelle récurrence' })).toBeNull();
  });

  it('restores an archived recurrence and reports success', async () => {
    let restored = false;
    api.detectRecurrenceCandidates.mockImplementation(() => success({ items: [], partial: false }));
    api.listRecurrences.mockImplementation(({ query }) =>
      query.includeArchived
        ? success({
            items: restored ? [] : [archivedRecurrence],
            page: 1,
            perPage: 100,
            total: restored ? 0 : 1,
          })
        : success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    api.listAccounts.mockImplementation(() =>
      success({ items: [account], page: 1, perPage: 100, total: 1 }),
    );
    api.restoreRecurrence.mockImplementation(() => {
      restored = true;

      return success({ ...archivedRecurrence, archivedAt: null, version: 3 });
    });
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Voir les récurrences archivées' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Restaurer' }));

    await waitFor(() =>
      expect(api.restoreRecurrence).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { id: archivedRecurrence.id },
          body: { version: archivedRecurrence.version },
        }),
      ),
    );
    expect(await screen.findByText('La récurrence a été restaurée.')).toBeTruthy();
    expect(await screen.findByRole('heading', { name: 'Aucune récurrence archivée' })).toBeTruthy();
  });
});
