import type { GoalStatus, GoalSummary } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalCard } from './GoalCard';

const baseGoal: GoalSummary = {
  id: '00000000-0000-7000-8000-000000000001',
  label: 'Épargne',
  kind: 'ROLLING_RATIO',
  metric: 'AXIS_SPENDING',
  direction: 'MINIMUM',
  scope: { type: 'AXIS', label: 'Discrétionnaire', archived: false },
  assetCode: 'EUR',
  state: 'ACTIVE',
  currentVersion: {
    versionNo: 1,
    effectiveFrom: '2026-09',
    year: null,
    targetAmount: null,
    ratio: '0.30',
    schedule: null,
  },
  version: 1,
  warnings: [],
  status: null,
};

const notCalculableRollingStatus: GoalStatus = {
  goalId: baseGoal.id,
  month: '2026-09',
  kind: 'ROLLING_RATIO',
  direction: 'MINIMUM',
  goalVersion: 1,
  provisional: false,
  metricPolicy: { state: 'MIXED', version: null, versions: [1, 2] },
  ratio: '0.30',
  referenceIncome: null,
  target: null,
  actual: null,
  gap: null,
  status: 'NOT_CALCULABLE',
  reason: 'MIXED_METRIC_POLICIES',
  quality: 'PARTIAL',
};

function noop() {}

describe('GoalCard', () => {
  afterEach(() => cleanup());

  it('shows the reason immediately after the status heading when the status is not calculable', () => {
    render(
      <GoalCard
        goal={{ ...baseGoal, status: notCalculableRollingStatus }}
        onClose={noop}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByText('Non calculable')).toBeTruthy();
    const heading = screen.getByText('Situation pour septembre 2026').parentElement;
    expect(heading?.nextElementSibling?.textContent).toBe(
      'Les politiques d’indicateurs diffèrent selon les mois',
    );
  });

  it('never shows a bare zero or dash for a null value without its reason', () => {
    render(
      <GoalCard
        goal={{ ...baseGoal, status: notCalculableRollingStatus }}
        onClose={noop}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    // The actual/target dashes each carry the reason as accessible text, not a silent '-'.
    const reasons = screen.getAllByText('Les politiques d’indicateurs diffèrent selon les mois');
    expect(reasons.length).toBeGreaterThanOrEqual(2);
  });

  it('shows the month the status refers to, even when it is not the viewed calendar month', () => {
    render(
      <GoalCard
        goal={{ ...baseGoal, status: { ...notCalculableRollingStatus, month: '2027-01' } }}
        onClose={noop}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByText('Situation pour janvier 2027')).toBeTruthy();
  });

  it('explains a future-month status with its own reason next to the evaluated month', () => {
    render(
      <GoalCard
        goal={{
          ...baseGoal,
          status: { ...notCalculableRollingStatus, month: '2027-01', reason: 'FUTURE_MONTH' },
        }}
        onClose={noop}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByText('Situation pour janvier 2027')).toBeTruthy();
    expect(screen.getAllByText('Ce mois n’a pas encore commencé').length).toBeGreaterThanOrEqual(1);
  });

  it('labels a rolling minimum goal\'s second amount "Cible" rather than a cumulative expected amount', () => {
    const status: GoalStatus = {
      ...notCalculableRollingStatus,
      target: '750.00',
      actual: '500.00',
      gap: '-250.00',
      reason: null,
      status: 'WITHIN',
      quality: 'COMPLETE',
    };
    render(
      <GoalCard goal={{ ...baseGoal, status }} onClose={noop} onDetail={noop} onEdit={noop} />,
    );

    expect(screen.getByText(/Il manque 250,00\s€/)).toBeTruthy();
    expect(screen.getByText('Ratio visé : 30,00 %')).toBeTruthy();
    expect(screen.queryByText(/Objectif à cette date/)).toBeNull();
  });

  it('labels a rolling ceiling goal\'s second amount "Limite" rather than a cumulative expected amount', () => {
    const status: GoalStatus = {
      ...notCalculableRollingStatus,
      direction: 'CEILING',
      target: '750.00',
      actual: '500.00',
      gap: '-250.00',
      reason: null,
      status: 'WITHIN',
      quality: 'COMPLETE',
    };
    render(
      <GoalCard
        goal={{ ...baseGoal, direction: 'CEILING', status }}
        onClose={noop}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByText(/Marge de 250,00\s€ avant le plafond/)).toBeTruthy();
    expect(screen.queryByText(/Objectif à cette date/)).toBeNull();
  });

  it('labels the annual remaining row "Reste à atteindre" when excess is not calculable', () => {
    const annualGoal: GoalSummary = {
      ...baseGoal,
      kind: 'ANNUAL',
      currentVersion: {
        ...baseGoal.currentVersion,
        year: 2026,
        targetAmount: '2000.00',
        ratio: null,
      },
    };
    const status: GoalStatus = {
      goalId: baseGoal.id,
      month: '2026-09',
      kind: 'ANNUAL',
      direction: 'MINIMUM',
      goalVersion: 1,
      scheduleMode: 'LINEAR',
      provisional: false,
      metricPolicy: { state: 'MIXED', version: null, versions: [1, 2] },
      target: '2000.00',
      achieved: null,
      expected: null,
      gap: null,
      remaining: null,
      excess: null,
      status: 'NOT_CALCULABLE',
      progressRatio: null,
      progressRatioDisplay: null,
      reason: 'MIXED_METRIC_POLICIES',
      quality: 'PARTIAL',
      months: [],
    };
    render(
      <GoalCard goal={{ ...annualGoal, status }} onClose={noop} onDetail={noop} onEdit={noop} />,
    );

    expect(
      screen.getAllByTitle('Les politiques d’indicateurs diffèrent selon les mois').length,
    ).toBeGreaterThan(0);
    expect(screen.queryByText(/d’excédent/)).toBeNull();
  });

  it('offers the monthly detail of an annual goal only when a detail handler is provided', () => {
    const status: GoalStatus = {
      goalId: baseGoal.id,
      month: '2026-09',
      kind: 'ANNUAL',
      direction: 'MINIMUM',
      goalVersion: 1,
      scheduleMode: 'LINEAR',
      provisional: false,
      metricPolicy: { state: 'SINGLE', version: 1, versions: [1] },
      target: '12000',
      achieved: '9000',
      expected: '9000',
      gap: '0',
      remaining: '3000',
      excess: '0',
      status: 'AHEAD',
      progressRatio: '0.75',
      progressRatioDisplay: '0.75',
      reason: null,
      quality: 'COMPLETE',
      months: [],
    };
    const annual: GoalSummary = {
      ...baseGoal,
      kind: 'ANNUAL',
      currentVersion: {
        ...baseGoal.currentVersion,
        year: 2026,
        targetAmount: '12000',
        ratio: null,
      },
      status,
    };
    const { rerender } = render(
      <GoalCard goal={annual} onClose={noop} onDetail={noop} onEdit={noop} />,
    );
    expect(screen.getByRole('button', { name: 'Voir le détail mensuel' })).toBeTruthy();
    expect(screen.getByText('En avance')).toBeTruthy();
    expect(screen.getAllByText(/12\s000,00/)).toHaveLength(1);
    expect(screen.getByText(/Il manque 3\s000,00\s€/)).toBeTruthy();
    expect(screen.getByText(/Objectif à cette date : 9\s000,00\s€/)).toBeTruthy();
    expect(screen.getByTestId('gauge-marker').getAttribute('style')).toContain('left: 75%');

    rerender(<GoalCard goal={annual} onClose={noop} onEdit={noop} />);
    expect(screen.queryByRole('button', { name: 'Voir le détail mensuel' })).toBeNull();
  });

  it('offers no monthly detail for a rolling goal, which has no month-by-month breakdown', () => {
    render(
      <GoalCard
        goal={{ ...baseGoal, status: notCalculableRollingStatus }}
        onClose={noop}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.queryByRole('button', { name: 'Voir le détail mensuel' })).toBeNull();
  });

  it('keeps the history action available after the goal is closed', () => {
    const onHistory = vi.fn();
    render(
      <GoalCard
        goal={{ ...baseGoal, state: 'CLOSED' }}
        onClose={noop}
        onEdit={noop}
        onHistory={onHistory}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions de l’objectif « Épargne »' }));
    fireEvent.click(screen.getByRole('button', { name: 'Historique de l’objectif « Épargne »' }));
    expect(onHistory).toHaveBeenCalledOnce();
  });

  it('flags a closed goal that missed its target and leaves an open or met one unflagged', () => {
    const missed = { ...notCalculableRollingStatus, status: 'MISSED' as const };
    const { container, rerender } = render(
      <GoalCard
        goal={{ ...baseGoal, state: 'CLOSED', status: missed }}
        onClose={noop}
        onEdit={noop}
      />,
    );
    expect(container.querySelector('[data-failed="true"]')).not.toBeNull();

    rerender(<GoalCard goal={{ ...baseGoal, status: missed }} onClose={noop} onEdit={noop} />);
    expect(container.querySelector('[data-failed]')).toBeNull();

    rerender(
      <GoalCard
        goal={{ ...baseGoal, state: 'CLOSED', status: { ...missed, status: 'MET' } }}
        onClose={noop}
        onEdit={noop}
      />,
    );
    expect(container.querySelector('[data-failed]')).toBeNull();
  });
});
