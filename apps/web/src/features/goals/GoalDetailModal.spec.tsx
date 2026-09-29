import type { GoalStatus } from '@cadran/api-client';
import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { GoalDetailModal } from './GoalDetailModal';

const status: GoalStatus = {
  goalId: '00000000-0000-7000-8000-000000000001',
  month: '2026-09',
  kind: 'ANNUAL',
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
});
