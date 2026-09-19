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

function occurrence(id: string, periodClosed: boolean) {
  return {
    id,
    expectedOn: '2026-08-05',
    expectedAmount: { value: '-800.00', assetCode: 'EUR' },
    status: 'EXPECTED',
    matchedTransactionId: null,
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
});
