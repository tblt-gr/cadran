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

  it('shows the server-provided percentage without calculating a second percentage', () => {
    render(<GoalProgressBar assetCode="EUR" status={baseStatus} />);

    expect(screen.getByText('75,00 % atteint')).toBeTruthy();
    expect(screen.getByRole('meter')).toBeTruthy();
    expect(screen.queryByText(/Repère à cette date/)).toBeNull();
  });

  it('shows the achieved amount, the expected amount, and what remains without repeating the target', () => {
    render(<GoalProgressBar assetCode="EUR" status={baseStatus} />);

    expect(screen.getByText(/1\s*500,00/)).toBeTruthy();
    expect(screen.getByText('Cumul réalisé')).toBeTruthy();
    expect(screen.queryByText(/2\s*000,00/)).toBeNull();
    expect(screen.getByText('Objectif à cette date')).toBeTruthy();
    expect(screen.getByText(/1\s*500,03/)).toBeTruthy();
    expect(screen.getByText('Reste à atteindre')).toBeTruthy();
    expect(screen.getByText('Reste à atteindre').parentElement?.textContent).toMatch(/500,00/);
  });
});
