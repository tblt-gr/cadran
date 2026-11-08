import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it } from 'vitest';
import { useGoalWriteRefresh } from './useGoalEditor';

describe('useGoalWriteRefresh', () => {
  it('invalidates the evaluations of every range and granularity, and the reports they mark', async () => {
    const client = new QueryClient();
    const keys = [
      ['goal-evaluations', '2026-01', '2026-12', 'BOTH'],
      ['goal-evaluations', '2024-01', '2026-12', 'YEAR'],
      ['goal-evaluations', '2026-08', '2026-08', 'MONTH'],
      ['annual-report', 2026],
      ['multi-year-report', 2024, 2026],
    ];
    for (const key of keys) client.setQueryData(key, {});
    const { result } = renderHook(() => useGoalWriteRefresh(), {
      wrapper: ({ children }: { children: ReactNode }) => (
        <QueryClientProvider client={client}>{children}</QueryClientProvider>
      ),
    });
    await result.current();
    for (const key of keys) expect(client.getQueryState(key)?.isInvalidated).toBe(true);
  });
});
