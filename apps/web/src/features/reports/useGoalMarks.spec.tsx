import type { GoalEvaluations } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { annualPreferences } from '@/features/reports/annual/annualFixtures';
import { useGoalMarks } from './useGoalMarks';

const api = vi.hoisted(() => ({
  readAnnualReportPreferences: vi.fn(),
  listGoalEvaluations: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = <T,>(data: T) => ({ data, response: { ok: true, status: 200 } });
const range = { from: '2026-01', to: '2026-12', granularity: 'BOTH' as const };
const evaluationKey = ['goal-evaluations', range.from, range.to, range.granularity];
const evaluations: GoalEvaluations = { months: [], years: [] };
function mount(client = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  const wrapper = ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, ...renderHook(() => useGoalMarks(range), { wrapper }) };
}
beforeEach(() => {
  api.readAnnualReportPreferences.mockResolvedValue(
    ok({ ...annualPreferences, goalMarks: false, version: 0 }),
  );
  api.listGoalEvaluations.mockResolvedValue(ok(evaluations));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('useGoalMarks', () => {
  it('defaults to hidden and makes no evaluation request while preferences are pending', () => {
    api.readAnnualReportPreferences.mockImplementation(() => new Promise(() => undefined));
    const { result } = mount();
    expect(result.current.shown).toBe(false);
    expect(result.current.evaluations).toBeUndefined();
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
  });

  it('keeps marks hidden on preference failure instead of requesting evaluations', async () => {
    api.readAnnualReportPreferences.mockResolvedValue({ response: { ok: false, status: 500 } });
    const { client, result } = mount();
    await waitFor(() =>
      expect(client.getQueryState(['annual-report-preferences'])?.status).toBe('error'),
    );
    expect(result.current.shown).toBe(false);
    expect(result.current.failed).toBe(false);
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
  });

  it('does not reveal cached evaluations when disabled', async () => {
    const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
    client.setQueryData(evaluationKey, evaluations);
    const { result } = mount(client);
    await waitFor(() => expect(client.getQueryData(['annual-report-preferences'])).toBeTruthy());
    expect(result.current.shown).toBe(false);
    expect(result.current.evaluations).toBeUndefined();
    expect(api.listGoalEvaluations).not.toHaveBeenCalled();
  });

  it('restores saved true marks and masks the cache immediately when disabled again', async () => {
    api.readAnnualReportPreferences.mockResolvedValue(ok(annualPreferences));
    const { client, result } = mount();
    await waitFor(() => expect(result.current.evaluations).toEqual(evaluations));
    expect(result.current.shown).toBe(true);
    expect(api.listGoalEvaluations).toHaveBeenCalledTimes(1);
    act(() =>
      client.setQueryData(['annual-report-preferences'], {
        ...annualPreferences,
        goalMarks: false,
      }),
    );
    await waitFor(() => expect(result.current.shown).toBe(false));
    expect(result.current.evaluations).toBeUndefined();
    expect(api.listGoalEvaluations).toHaveBeenCalledTimes(1);
  });

  it('offers evaluation recovery only while enabled', async () => {
    api.readAnnualReportPreferences.mockResolvedValue(ok(annualPreferences));
    api.listGoalEvaluations.mockResolvedValueOnce({ response: { ok: false, status: 500 } });
    const { client, result } = mount();
    await waitFor(() => expect(result.current.failed).toBe(true));
    act(() => result.current.retry());
    await waitFor(() => expect(result.current.failed).toBe(false));
    expect(api.listGoalEvaluations).toHaveBeenCalledTimes(2);
    act(() =>
      client.setQueryData(['annual-report-preferences'], {
        ...annualPreferences,
        goalMarks: false,
      }),
    );
    await waitFor(() => expect(result.current.evaluations).toBeUndefined());
    expect(result.current.failed).toBe(false);
  });
});
