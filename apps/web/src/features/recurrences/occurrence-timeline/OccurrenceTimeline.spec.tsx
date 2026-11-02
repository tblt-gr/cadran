import type { Recurrence } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { OccurrenceTimeline } from './OccurrenceTimeline';

const api = vi.hoisted(() => ({ listRecurrenceOccurrences: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const recurrence = { id: 'r1', label: 'Loyer' } as unknown as Recurrence;

function occurrence(
  id: string,
  periodClosed: boolean,
  generatedTransactionId: string | null = null,
  status = 'EXPECTED',
  matchedTransactionId: string | null = null,
) {
  return {
    id,
    expectedOn: '2026-08-05',
    expectedAmount: { value: '-800.00', assetCode: 'EUR' },
    status,
    matchedTransactionId,
    generatedTransactionId,
    periodClosed,
  };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('OccurrenceTimeline', () => {
  it('flags an occurrence waiting in a closed month with text, not colour alone', async () => {
    api.listRecurrenceOccurrences.mockResolvedValue({
      data: { items: [occurrence('o1', true), occurrence('o2', false)] },
      response: { ok: true, status: 200 },
    });
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OccurrenceTimeline close={() => {}} recurrence={recurrence} />
      </QueryClientProvider>,
    );

    expect(await screen.findAllByText(/Mois clôturé/)).toHaveLength(1);
  });

  it('reads "Transaction en attente créée" only for a generated instalment not yet received', async () => {
    api.listRecurrenceOccurrences.mockResolvedValue({
      data: {
        items: [
          occurrence('o1', false, 'tx1'),
          occurrence('o2', false, 'tx2', 'RECEIVED', 'tx2'),
          occurrence('o3', false),
        ],
      },
      response: { ok: true, status: 200 },
    });
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OccurrenceTimeline close={() => {}} recurrence={recurrence} />
      </QueryClientProvider>,
    );

    expect(await screen.findAllByText('Transaction en attente créée')).toHaveLength(1);
    expect(screen.getAllByText('Reçue').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Sans transaction')).toHaveLength(1);
  });

  it('keeps the table headers as text over a skeleton while the occurrences load', () => {
    api.listRecurrenceOccurrences.mockReturnValue(new Promise(() => undefined));
    render(
      <QueryClientProvider client={new QueryClient()}>
        <OccurrenceTimeline close={() => {}} recurrence={recurrence} />
      </QueryClientProvider>,
    );

    expect(screen.getByTestId('occurrence-timeline-skeleton').getAttribute('aria-busy')).toBe(
      'true',
    );
    expect(screen.getByRole('columnheader', { name: 'Montant attendu' })).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByRole('heading', { name: 'Loyer' })).toBeTruthy();
  });
});
