import type { CreateCategorizationRuleRequest } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useRuleEditor } from './useRuleEditor';

const api = vi.hoisted(() => ({
  createCategorizationRule: vi.fn(),
  updateCategorizationRule: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const body = { label: 'Courses' } as unknown as CreateCategorizationRuleRequest;
const failure = () =>
  Promise.resolve({
    error: { type: 'about:blank', title: 'Unavailable', status: 503 },
    response: new Response(null, { status: 503 }),
  });
const created = () =>
  Promise.resolve({ data: { id: 'rule' }, response: new Response('{}', { status: 201 }) });

function wrapper({ children }: { children: ReactNode }) {
  const client = new QueryClient({ defaultOptions: { mutations: { retry: false } } });
  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}
const keys = () =>
  api.createCategorizationRule.mock.calls.map(([options]) => options.headers['Idempotency-Key']);

describe('useRuleEditor creation', () => {
  afterEach(() => vi.clearAllMocks());

  it('reuses the Idempotency-Key when the same body is retried, then issues a new one', async () => {
    api.createCategorizationRule
      .mockImplementationOnce(failure)
      .mockImplementationOnce(created)
      .mockImplementation(created);
    const { result } = renderHook(() => useRuleEditor(true, vi.fn()), { wrapper });
    act(() => result.current.open('create'));

    await act(async () => {
      await result.current.save.mutateAsync(body).catch(() => undefined);
    });
    await act(async () => {
      await result.current.save.mutateAsync(body);
    });
    act(() => result.current.open('create'));
    await act(async () => {
      await result.current.save.mutateAsync(body);
    });

    expect(keys()[0]).toMatch(/^[0-9a-f-]{36}$/);
    expect(keys()[1]).toBe(keys()[0]);
    expect(keys()[2]).not.toBe(keys()[0]);
  });
});
