import type { GoalSummary } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BulkGoalLifecycleDialog } from './BulkGoalLifecycleDialog';

const api = vi.hoisted(() => ({ bulkStopGoals: vi.fn(), bulkDeleteGoals: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const monthly = {
  id: 'monthly',
  label: 'Courses',
  periodicity: 'MONTHLY',
  version: 2,
} as GoalSummary;
const yearly = { id: 'yearly', label: 'Voyage', periodicity: 'YEARLY', version: 4 } as GoalSummary;
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('bulk goal lifecycle request', () => {
  it('sends one stop request with each periodicity and refreshes every affected query', async () => {
    api.bulkStopGoals.mockResolvedValue({ response: new Response(null, { status: 204 }) });
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const invalidations = vi.spyOn(client, 'invalidateQueries');
    const saved = vi.fn();
    render(
      <QueryClientProvider client={client}>
        <BulkGoalLifecycleDialog
          action="stop"
          goals={[monthly, yearly]}
          month="2026-11"
          year={2026}
          close={vi.fn()}
          onSaved={saved}
        />
      </QueryClientProvider>,
    );
    expect(api.bulkStopGoals).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('button', { name: 'Arrêter' }));
    await waitFor(() => expect(saved).toHaveBeenCalledOnce());
    expect(api.bulkStopGoals.mock.calls[0]![0].body).toEqual({
      items: [
        { goalId: 'monthly', expectedVersion: 2, lastPeriod: '2026-10' },
        { goalId: 'yearly', expectedVersion: 4, lastPeriod: '2025' },
      ],
    });
    for (const key of [
      'goals',
      'goal',
      'goal-evaluations',
      'goal-versions',
      'annual-report',
      'monthly-report',
      'monthly-recap',
      'multi-year-report',
    ]) {
      expect(invalidations).toHaveBeenCalledWith({ queryKey: [key] });
    }
  });
  it('preserves the draft and all selected identifiers after stale refresh, using latest versions', async () => {
    api.bulkStopGoals.mockResolvedValueOnce({
      response: new Response('{}', { status: 409 }),
      error: { code: 'stale_goal_version', goalId: 'monthly', field: 'expectedVersion' },
    });
    api.bulkStopGoals.mockResolvedValueOnce({ response: new Response(null, { status: 204 }) });
    const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
    const saved = vi.fn();
    const view = (goals: GoalSummary[]) => (
      <QueryClientProvider client={client}>
        <BulkGoalLifecycleDialog
          action="stop"
          goals={goals}
          month="2026-11"
          year={2026}
          close={vi.fn()}
          onSaved={saved}
        />
      </QueryClientProvider>
    );
    const mounted = render(view([monthly, yearly]));
    fireEvent.change(screen.getByLabelText('Dernier mois concerné'), {
      target: { value: '2026-12' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Arrêter' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('Courses'));
    expect((screen.getByLabelText('Dernier mois concerné') as HTMLInputElement).value).toBe(
      '2026-12',
    );
    mounted.rerender(view([{ ...monthly, version: 3 }]));
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Arrêter' })).toHaveProperty('disabled', false),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Arrêter' }));
    await waitFor(() => expect(saved).toHaveBeenCalledOnce());
    expect(api.bulkStopGoals.mock.calls[1]![0].body.items).toEqual([
      { goalId: 'monthly', expectedVersion: 3, lastPeriod: '2026-12' },
      { goalId: 'yearly', expectedVersion: 4, lastPeriod: '2025' },
    ]);
  });
  it('does not expose an inaccessible identifier or label on deletion failure', async () => {
    api.bulkDeleteGoals.mockResolvedValue({
      response: new Response('{}', { status: 404 }),
      error: { code: 'goal_not_found', goalId: 'foreign', label: 'Secret goal' },
    });
    const client = new QueryClient();
    render(
      <QueryClientProvider client={client}>
        <BulkGoalLifecycleDialog
          action="delete"
          goals={[monthly]}
          month="2026-11"
          year={2026}
          close={vi.fn()}
          onSaved={vi.fn()}
        />
      </QueryClientProvider>,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Supprimer' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('indisponible'));
    expect(screen.queryByText(/foreign|Secret goal/)).toBeNull();
  });
});
