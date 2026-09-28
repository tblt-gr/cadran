import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import type { GoalAnnualStatus } from '@cadran/api-client';
import { GoalProgressBar } from './GoalProgressBar';

const baseStatus: GoalAnnualStatus = {
  goalId: '00000000-0000-7000-8000-000000000001',
  month: '2026-09',
  kind: 'ANNUAL',
  direction: 'MINIMUM',
  goalVersion: 1,
  scheduleMode: 'LINEAR',
  provisional: false,
  metricPolicy: { state: 'SINGLE', version: 1, versions: [1] },
  target: '2000',
  achieved: '1500',
  expected: '1500.03',
  gap: '-0.03',
  remaining: '500',
  excess: '0',
  status: 'BEHIND',
  progressRatio: '0.75',
  progressRatioDisplay: '0.75',
  reason: null,
  quality: 'COMPLETE',
  months: [],
};

describe('GoalProgressBar', () => {
  afterEach(() => cleanup());

  it('shows the expected-tick value as the true percentage, not multiplied a second time', () => {
    render(<GoalProgressBar status={baseStatus} />);

    // 1500.03 / 2000 = 75.0015 %, rounded to two digits: 75,00 %.
    expect(screen.getByText('Échéance attendue : 75,00 %')).toBeTruthy();
    expect(screen.queryByText(/7\s?500,15\s?%/)).toBeNull();
  });
});
