import type { GoalSummary } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BulkGoalLifecycleForm } from './BulkGoalLifecycleForm';

const monthly = {
  id: 'monthly',
  label: 'Courses',
  periodicity: 'MONTHLY',
  version: 2,
} as GoalSummary;
const yearly = { id: 'yearly', label: 'Voyage', periodicity: 'YEARLY', version: 4 } as GoalSummary;
const props = () => ({
  action: 'stop' as const,
  goals: [monthly, yearly],
  month: '2026-11',
  year: 2026,
  busy: false,
  error: null,
  rowErrors: [],
  close: vi.fn(),
  onConfirm: vi.fn(),
});
afterEach(cleanup);

describe('bulk goal lifecycle confirmation', () => {
  it('keeps monthly and yearly last periods explicit in a mixed selection', () => {
    const input = props();
    render(<BulkGoalLifecycleForm {...input} />);
    expect(
      screen.getByText(/Les révisions planifiées après cette fin sont supprimées/),
    ).toBeTruthy();
    expect((screen.getByLabelText('Dernier mois concerné') as HTMLInputElement).value).toBe(
      '2026-10',
    );
    expect((screen.getByLabelText('Dernière année concernée') as HTMLInputElement).value).toBe(
      '2025',
    );
    fireEvent.change(screen.getByLabelText('Dernier mois concerné'), {
      target: { value: '2026-11' },
    });
    fireEvent.change(screen.getByLabelText('Dernière année concernée'), {
      target: { value: '2026' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Arrêter' }));
    expect(input.onConfirm).toHaveBeenCalledWith({ monthly: '2026-11', yearly: '2026' });
  });
  it('blocks invalid periods and duplicate submissions while saving', () => {
    const input = props();
    const view = render(<BulkGoalLifecycleForm {...input} />);
    fireEvent.change(screen.getByLabelText('Dernière année concernée'), {
      target: { value: '26' },
    });
    expect(screen.getByRole('button', { name: 'Arrêter' })).toHaveProperty('disabled', true);
    view.rerender(<BulkGoalLifecycleForm {...input} busy />);
    expect(screen.getByRole('button', { name: 'Arrêter' })).toHaveProperty('disabled', true);
    expect(screen.getByRole('button', { name: 'Annuler' })).toHaveProperty('disabled', true);
  });
  it('names the destructive deletion and selected goals, and cancels without mutation', () => {
    const input = props();
    render(<BulkGoalLifecycleForm {...input} action="delete" />);
    expect(screen.getByText(/Ces 2 objectifs et leurs historiques/)).toBeTruthy();
    expect(screen.getByText('Courses')).toBeTruthy();
    expect(screen.getByText('Voyage')).toBeTruthy();
    expect(screen.queryByLabelText('Dernier mois concerné')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));
    expect(input.close).toHaveBeenCalledOnce();
    expect(input.onConfirm).not.toHaveBeenCalled();
  });
  it('keeps the chosen period with an explanatory row error', () => {
    const input = props();
    const view = render(<BulkGoalLifecycleForm {...input} />);
    fireEvent.change(screen.getByLabelText('Dernier mois concerné'), {
      target: { value: '2026-12' },
    });
    view.rerender(
      <BulkGoalLifecycleForm
        {...input}
        error="Aucune modification"
        rowErrors={['Courses : période clôturée']}
      />,
    );
    expect((screen.getByLabelText('Dernier mois concerné') as HTMLInputElement).value).toBe(
      '2026-12',
    );
    expect(screen.getByRole('alert').textContent).toContain('Courses : période clôturée');
  });
});
