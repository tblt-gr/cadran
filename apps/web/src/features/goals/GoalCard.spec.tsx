import type { GoalMonthlyStatus, GoalStatus, GoalSummary } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalCard } from './GoalCard';

const baseGoal: GoalSummary = {
  id: '00000000-0000-7000-8000-000000000001',
  label: 'Épargne',
  periodicity: 'MONTHLY',
  startPeriod: '2026-01',
  endPeriod: null,
  metric: 'AXIS_SPENDING',
  direction: 'MINIMUM',
  scope: { type: 'AXIS', label: 'Discrétionnaire', archived: false },
  assetCode: 'EUR',
  currentVersion: {
    versionNo: 1,
    effectiveFrom: '2026-09',
    valueType: 'INCOME_SHARE',
    targetAmount: null,
    ratio: '0.30',
    schedule: null,
  },
  version: 1,
  warnings: [],
  status: null,
};

const notCalculableMonthlyStatus: GoalMonthlyStatus = {
  goalId: baseGoal.id,
  month: '2026-09',
  periodicity: 'MONTHLY',
  valueType: 'INCOME_SHARE',
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
  months: [],
};

function noop() {}

describe('GoalCard', () => {
  afterEach(() => cleanup());

  it('shows the reason immediately after the status heading when the status is not calculable', () => {
    render(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, status: notCalculableMonthlyStatus }}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(
      screen.getAllByText('Les politiques d’indicateurs diffèrent selon les mois').length,
    ).toBeGreaterThan(0);
  });

  it('never shows a bare zero or dash for a null value without its reason', () => {
    render(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, status: notCalculableMonthlyStatus }}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    // The actual/target dashes each carry the reason as accessible text, not a silent '-'.
    const reasons = screen.getAllByText('Les politiques d’indicateurs diffèrent selon les mois');
    expect(reasons.length).toBeGreaterThanOrEqual(2);
  });

  it('explains a future-month status with its own reason', () => {
    render(
      <GoalCard
        currentMonth="2026-09"
        goal={{
          ...baseGoal,
          status: { ...notCalculableMonthlyStatus, month: '2027-01', reason: 'FUTURE_MONTH' },
        }}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getAllByText('Ce mois n’a pas encore commencé').length).toBeGreaterThanOrEqual(1);
  });

  it('labels a monthly minimum goal\'s second amount "Cible" rather than a cumulative expected amount', () => {
    const status: GoalStatus = {
      ...notCalculableMonthlyStatus,
      target: '750.00',
      actual: '500.00',
      gap: '-250.00',
      reason: null,
      status: 'WITHIN',
      quality: 'COMPLETE',
    };
    render(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, status }}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByText(/Il manque 250,00\s€/)).toBeTruthy();
    expect(screen.getByText('Ratio visé : 30,00 %')).toBeTruthy();
    expect(screen.queryByText(/Objectif à cette date/)).toBeNull();
  });

  it('labels a monthly ceiling goal\'s second amount "Limite" rather than a cumulative expected amount', () => {
    const status: GoalStatus = {
      ...notCalculableMonthlyStatus,
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
        currentMonth="2026-09"
        goal={{ ...baseGoal, direction: 'CEILING', status }}
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
      periodicity: 'YEARLY',
      startPeriod: '2026',
      currentVersion: {
        ...baseGoal.currentVersion,
        valueType: 'AMOUNT',
        targetAmount: '2000.00',
        ratio: null,
      },
    };
    const status: GoalStatus = {
      goalId: baseGoal.id,
      month: '2026-09',
      periodicity: 'YEARLY',
      valueType: 'AMOUNT',
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
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...annualGoal, status }}
        onDetail={noop}
        onEdit={noop}
      />,
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
      periodicity: 'YEARLY',
      valueType: 'AMOUNT',
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
      periodicity: 'YEARLY',
      startPeriod: '2026',
      currentVersion: {
        ...baseGoal.currentVersion,
        valueType: 'AMOUNT',
        targetAmount: '12000',
        ratio: null,
      },
      status,
    };
    const { rerender } = render(
      <GoalCard currentMonth="2026-09" goal={annual} onDetail={noop} onEdit={noop} />,
    );
    expect(screen.getByRole('button', { name: 'Voir le détail mensuel' })).toBeTruthy();
    expect(screen.getByText('En avance')).toBeTruthy();
    expect(screen.getAllByText(/12\s000,00/)).toHaveLength(1);
    expect(screen.getByText(/Il manque 3\s000,00\s€/)).toBeTruthy();
    expect(screen.getByText(/Objectif attendu à cette date : 9\s000,00\s€/)).toBeTruthy();
    expect(screen.getByTestId('gauge-marker').getAttribute('style')).toContain('left: 75%');

    rerender(<GoalCard currentMonth="2026-09" goal={annual} onEdit={noop} />);
    expect(screen.queryByRole('button', { name: 'Voir le détail mensuel' })).toBeNull();
  });

  it('offers the monthly detail of a monthly goal as well', () => {
    render(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, status: notCalculableMonthlyStatus }}
        onDetail={noop}
        onEdit={noop}
      />,
    );

    expect(screen.getByRole('button', { name: 'Voir le détail mensuel' })).toBeTruthy();
  });

  it('keeps the history action available after the goal has ended', () => {
    const onHistory = vi.fn();
    render(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, endPeriod: '2026-08' }}
        onEdit={noop}
        onHistory={onHistory}
      />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Actions de l’objectif « Épargne »' }));
    fireEvent.click(screen.getByRole('button', { name: 'Historique de l’objectif « Épargne »' }));
    expect(onHistory).toHaveBeenCalledOnce();
  });

  it('flags an ended goal that missed its target and leaves a running or met one unflagged', () => {
    const missed = { ...notCalculableMonthlyStatus, status: 'MISSED' as const };
    const { container, rerender } = render(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, endPeriod: '2026-08', status: missed }}
        onEdit={noop}
      />,
    );
    expect(container.querySelector('[data-failed="true"]')).not.toBeNull();

    rerender(
      <GoalCard currentMonth="2026-09" goal={{ ...baseGoal, status: missed }} onEdit={noop} />,
    );
    expect(container.querySelector('[data-failed]')).toBeNull();

    rerender(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, endPeriod: '2026-08', status: { ...missed, status: 'MET' } }}
        onEdit={noop}
      />,
    );
    expect(container.querySelector('[data-failed]')).toBeNull();
  });

  it('states the periodicity and span of the goal under its metric', () => {
    const { rerender } = render(<GoalCard currentMonth="2026-09" goal={baseGoal} onEdit={noop} />);
    expect(screen.getByText('Mensuel, depuis janvier 2026')).toBeTruthy();

    rerender(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, endPeriod: '2026-01' }}
        onEdit={noop}
      />,
    );
    expect(screen.getByText('Mensuel, janvier 2026')).toBeTruthy();

    rerender(
      <GoalCard
        currentMonth="2026-09"
        goal={{ ...baseGoal, periodicity: 'YEARLY', startPeriod: '2025', endPeriod: '2026' }}
        onEdit={noop}
      />,
    );
    expect(screen.getByText('Annuel, de 2025 à 2026')).toBeTruthy();
  });

  it('shows a rate goal as percentages and omits the share caption', () => {
    const rateGoal: GoalSummary = {
      ...baseGoal,
      metric: 'SAVINGS_RATE',
      scope: { type: 'WORKSPACE', label: 'Espace', archived: false },
      currentVersion: { ...baseGoal.currentVersion, valueType: 'RATE', ratio: '0.20' },
      status: {
        ...notCalculableMonthlyStatus,
        valueType: 'RATE',
        ratio: '0.20',
        target: '0.20',
        actual: '0.15',
        gap: '-0.05',
        reason: null,
        status: 'MISSED',
        quality: 'COMPLETE',
      },
    };
    render(<GoalCard currentMonth="2026-09" goal={rateGoal} onEdit={noop} />);

    expect(screen.getByText(/Taux d’épargne/)).toBeTruthy();
    expect(screen.getByText(/Il manque 5,00\s%/)).toBeTruthy();
    expect(screen.queryByText(/Ratio visé/)).toBeNull();
  });

  it('explains a goal that is not yet started with its own reason', () => {
    render(
      <GoalCard
        currentMonth="2026-09"
        goal={{
          ...baseGoal,
          status: { ...notCalculableMonthlyStatus, reason: 'GOAL_NOT_STARTED' },
        }}
        onEdit={noop}
      />,
    );

    expect(
      screen.getAllByText('L’objectif ne s’applique pas encore à ce mois').length,
    ).toBeGreaterThan(0);
  });
});
