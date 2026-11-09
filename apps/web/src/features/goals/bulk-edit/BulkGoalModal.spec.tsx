import type { BulkGoalResult, GoalSummary } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BulkGoalModal } from './BulkGoalModal';

const api = vi.hoisted(() => ({ previewBulkGoalEdit: vi.fn(), bulkEditGoals: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});
const goal = {
  id: 'g1',
  label: 'Épargne',
  version: 1,
  assetCode: 'EUR',
  currentVersion: { valueType: 'AMOUNT', schedule: null },
} as GoalSummary;
const mismatch: BulkGoalResult = {
  effectiveFrom: '2026-10',
  canApply: false,
  items: [
    {
      goalId: 'g1',
      label: goal.label,
      expectedVersion: 2,
      valueType: 'RATE',
      assetCode: 'EUR',
      before: { target: '0.15', tolerance: '0' },
      after: { target: '0.20', tolerance: '0' },
      firstAffectedMonth: '2026-10',
      lastAffectedMonth: null,
      changed: false,
      laterRevisions: [],
      overrides: [],
      conflict: { code: 'goal_target_unit_changed', field: 'selection' },
    },
  ],
};

describe('bulk goal unit binding', () => {
  it('pins displayed units across refreshes and blocks a differing effective-month definition', async () => {
    api.previewBulkGoalEdit.mockResolvedValue({ data: mismatch, response: new Response('{}') });
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
    });
    const view = (goals: GoalSummary[]) => (
      <QueryClientProvider client={client}>
        <BulkGoalModal goals={goals} month="2026-11" close={vi.fn()} onSaved={vi.fn()} />
      </QueryClientProvider>
    );
    const rendered = render(view([goal]));
    fireEvent.click(screen.getByLabelText('Modifier la cible'));
    fireEvent.change(screen.getByLabelText('Cible commune (EUR)'), { target: { value: '20' } });
    fireEvent.change(screen.getByLabelText('Mois d’effet commun'), {
      target: { value: '2026-10' },
    });
    fireEvent.change(screen.getByLabelText('Motif de la correction rétroactive'), {
      target: { value: 'Correction historique' },
    });
    rendered.rerender(
      view([
        { ...goal, version: 2, currentVersion: { ...goal.currentVersion, valueType: 'RATE' } },
      ]),
    );
    expect((screen.getByLabelText('Cible commune (EUR)') as HTMLInputElement).value).toBe('20');
    fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser les changements' }));
    await waitFor(() => expect(api.previewBulkGoalEdit).toHaveBeenCalledOnce());
    expect(api.previewBulkGoalEdit.mock.calls[0]![0].body).toEqual({
      items: [
        { goalId: 'g1', expectedVersion: 2, expectedValueType: 'AMOUNT', expectedAssetCode: 'EUR' },
      ],
      effectiveFrom: '2026-10',
      reason: 'Correction historique',
      changes: { target: '20' },
    });
    await waitFor(() =>
      expect(screen.getByRole('alert').textContent).toContain('diffère au mois choisi'),
    );
    expect(
      screen.getByRole('button', { name: 'Appliquer à la sélection' }).hasAttribute('disabled'),
    ).toBe(true);
    expect(api.bulkEditGoals).not.toHaveBeenCalled();
    expect(screen.queryByText(/20,00.*%/)).toBeNull();
  });
});
