import type { GoalStatus, GoalSummary } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
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

  it("shows the reason as the status badge's next sibling when the status is not calculable", () => {
    const { container } = render(
      <GoalCard
        goal={{ ...baseGoal, status: notCalculableRollingStatus }}
        onClose={noop}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByText('Non calculable')).toBeTruthy();
    const badge = container.querySelector('[class*="badge"]');
    expect(badge?.nextElementSibling?.textContent).toBe('Politiques d’indicateur mixtes');
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
    const reasons = screen.getAllByText('Politiques d’indicateur mixtes');
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

    expect(screen.getByText('Mois évalué : janvier 2027')).toBeTruthy();
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

    expect(screen.getByText('Mois évalué : janvier 2027')).toBeTruthy();
    expect(screen.getAllByText('Mois pas encore commencé').length).toBeGreaterThanOrEqual(1);
  });

  it('labels a rolling minimum goal\'s second amount "Cible" rather than "Attendu"', () => {
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

    expect(screen.getByText('Cible')).toBeTruthy();
    expect(screen.queryByText('Attendu')).toBeNull();
  });

  it('labels a rolling ceiling goal\'s second amount "Limite" rather than "Attendu"', () => {
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

    expect(screen.getByText('Limite')).toBeTruthy();
    expect(screen.queryByText('Attendu')).toBeNull();
  });

  it('labels the annual excess row "Reste" instead of "Dépassement" when excess is not calculable', () => {
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

    expect(screen.getByText('Reste')).toBeTruthy();
    expect(screen.queryByText('Dépassement')).toBeNull();
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
    const annual: GoalSummary = { ...baseGoal, kind: 'ANNUAL', status };
    const { rerender } = render(
      <GoalCard goal={annual} onClose={noop} onDetail={noop} onEdit={noop} />,
    );
    expect(screen.getByRole('button', { name: 'Détail mensuel' })).toBeTruthy();

    rerender(<GoalCard goal={annual} onClose={noop} onEdit={noop} />);
    expect(screen.queryByRole('button', { name: 'Détail mensuel' })).toBeNull();
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

    expect(screen.queryByRole('button', { name: 'Détail mensuel' })).toBeNull();
  });
});
