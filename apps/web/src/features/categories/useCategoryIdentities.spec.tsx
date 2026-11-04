import type { Category } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, renderHook, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useRecurrenceCategory } from '@/features/recurrences/useRecurrenceCategory';
import { useCategoryIdentities } from './useCategoryIdentities';

const api = vi.hoisted(() => ({ listCategories: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

function page(items: Partial<Category>[], total = items.length) {
  return Promise.resolve({
    data: { items, total },
    response: new Response(null, { status: 200 }),
  });
}

function wrapper() {
  const queryClient = new QueryClient();
  return ({ children }: { children: ReactNode }) => (
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>
  );
}

describe('useCategoryIdentities', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('indexes every page of categories, archived ones included, by id', async () => {
    api.listCategories
      .mockReturnValueOnce(page([{ id: 'a', label: 'Courses' }], 2))
      .mockReturnValueOnce(page([{ id: 'b', label: 'Ancienne' }], 2));

    const { result } = renderHook(() => useCategoryIdentities(), { wrapper: wrapper() });

    await waitFor(() => expect(result.current.data).toBeDefined());
    expect(result.current.data?.get('a')?.label).toBe('Courses');
    expect(result.current.data?.get('b')?.label).toBe('Ancienne');
    expect(api.listCategories.mock.calls[0][0].query).toMatchObject({ includeArchived: true });
  });

  it('does not fetch while disabled', () => {
    renderHook(() => useCategoryIdentities(false), { wrapper: wrapper() });

    expect(api.listCategories).not.toHaveBeenCalled();
  });

  it('feeds useRecurrenceCategory', async () => {
    api.listCategories.mockReturnValue(page([{ id: 'a', label: 'Courses' }]));

    const { result } = renderHook(() => useRecurrenceCategory('a'), { wrapper: wrapper() });

    await waitFor(() => expect(result.current?.label).toBe('Courses'));
  });

  it('returns nothing for a null id without fetching', () => {
    const { result } = renderHook(() => useRecurrenceCategory(null), { wrapper: wrapper() });

    expect(result.current).toBeUndefined();
    expect(api.listCategories).not.toHaveBeenCalled();
  });
});
