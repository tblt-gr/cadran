import type { GoalStatus } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { GoalDetailModal } from './GoalDetailModal';

const status: GoalStatus = {
  goalId: '00000000-0000-7000-8000-000000000001',
  month: '2026-09',
  periodicity: 'YEARLY',
  valueType: 'AMOUNT',
  direction: 'MINIMUM',
  goalVersion: 1,
  scheduleMode: 'LINEAR',
  provisional: true,
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
  quality: 'PROVISIONAL',
  months: [
    {
      month: '2026-01',
      state: 'NO_DATA',
      value: null,
      expectedPart: '1000',
      cumulativeValue: null,
      cumulativeExpected: '1000',
      goalVersion: 1,
      policyVersion: null,
    },
    {
      month: '2026-09',
      state: 'PROVISIONAL',
      value: '1000',
      expectedPart: '1000',
      cumulativeValue: '9000',
      cumulativeExpected: '9000',
      goalVersion: 1,
      policyVersion: 1,
    },
  ],
};

describe('GoalDetailModal', () => {
  afterEach(() => cleanup());

  it('shows the no-data and provisional state of each month in the detail table', () => {
    render(<GoalDetailModal assetCode="EUR" close={() => {}} status={status} />);

    const rows = screen.getAllByRole('row');
    // Header row + one row per month.
    expect(rows).toHaveLength(3);
    expect(rows[1].textContent).toContain('Aucune donnée');
    expect(rows[2].textContent).toContain('Provisoire');
  });

  it('hides the cumulative columns for a monthly goal and shows a rate as a percentage', () => {
    const monthly: GoalStatus = {
      goalId: '00000000-0000-7000-8000-000000000002',
      month: '2026-09',
      periodicity: 'MONTHLY',
      direction: 'MINIMUM',
      valueType: 'RATE',
      goalVersion: 1,
      provisional: false,
      metricPolicy: { state: 'SINGLE', version: 1, versions: [1] },
      ratio: '0.20',
      referenceIncome: null,
      target: '0.20',
      actual: '0.25',
      gap: '0.05',
      status: 'MET',
      reason: null,
      quality: 'COMPLETE',
      months: [
        {
          month: '2026-09',
          state: 'COMPLETE',
          value: '0.25',
          expectedPart: '0.20',
          cumulativeValue: null,
          cumulativeExpected: null,
          goalVersion: 1,
          policyVersion: 1,
        },
      ],
    };
    render(<GoalDetailModal assetCode="EUR" close={() => {}} status={monthly} />);

    expect(screen.getAllByRole('columnheader')).toHaveLength(4);
    expect(screen.getAllByRole('row')[1]!.textContent).toContain('25,00');
    expect(screen.getAllByRole('row')[1]!.textContent).toContain('20,00');
  });
});
