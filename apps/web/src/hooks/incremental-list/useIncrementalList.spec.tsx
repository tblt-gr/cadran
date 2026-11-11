import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, renderHook, waitFor } from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { afterEach, expect, it, vi } from 'vitest';
import { useIncrementalList, type ListPageRequest, type ListResponse } from './useIncrementalList';

afterEach(cleanup);
const revision = 'a'.repeat(64);
const success = (items: string[], page = 1, total = 2) => ({
  data: { items, page, pageSize: 1, total },
  response: new Response(null, { headers: { 'X-List-Revision': revision } }),
});
function setup(request: (input: ListPageRequest) => Promise<ListResponse<string>>) {
  const client = new QueryClient();
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return {
    ...renderHook(
      ({ filter }) =>
        useIncrementalList({
          queryKey: ['list', filter],
          request: (input: ListPageRequest) => request(input) as Promise<ListResponse<string>>,
          requestError: () => new Error('failed'),
        }),
      { initialProps: { filter: 'old' }, wrapper },
    ),
    client,
  };
}
it('appends bounded pages with the revision and keeps rows on failure and retry', async () => {
  const request = vi
    .fn()
    .mockResolvedValueOnce(success(['one']))
    .mockResolvedValueOnce({ response: new Response(null, { status: 500 }) })
    .mockResolvedValueOnce(success(['two'], 2));
  const { result } = setup(request);
  await waitFor(() => expect(result.current.data?.items).toEqual(['one']));
  act(() => {
    result.current.loadMore();
    result.current.loadMore();
  });
  await waitFor(() => expect(result.current.isError).toBe(true));
  expect(result.current.data?.items).toEqual(['one']);
  act(() => result.current.loadMore());
  await waitFor(() => expect(result.current.data?.items).toEqual(['one', 'two']));
  expect(request.mock.calls[1]?.[0]).toMatchObject({ page: 2, revision });
  expect(request).toHaveBeenCalledTimes(3);
});
it('keeps stale rows until explicit restart and discards obsolete filter responses', async () => {
  const request = vi
    .fn()
    .mockResolvedValueOnce(success(['one']))
    .mockResolvedValueOnce({
      response: new Response(null, { status: 409 }),
      error: { type: '/problems/lists.revision_stale' },
    })
    .mockResolvedValueOnce(success(['fresh']))
    .mockResolvedValueOnce(success(['new']));
  const { result, rerender } = setup(request);
  await waitFor(() => expect(result.current.data?.items).toEqual(['one']));
  act(() => result.current.loadMore());
  await waitFor(() => expect(result.current.stale).toBe(true));
  expect(result.current.data?.items).toEqual(['one']);
  act(() => result.current.restart());
  await waitFor(() => expect(result.current.data?.items).toEqual(['fresh']));
  rerender({ filter: 'new' });
  await waitFor(() => expect(result.current.data?.items).toEqual(['new']));
  expect(request.mock.calls[2]?.[0]).toMatchObject({ page: 1, revision: 'start' });
});

it('discards an obsolete in-flight next page after a rapid filter change', async () => {
  let finish: ((value: ListResponse<string>) => void) | undefined;
  const request = vi
    .fn()
    .mockResolvedValueOnce(success(['old']))
    .mockImplementationOnce(
      () =>
        new Promise<ListResponse<string>>((resolve) => {
          finish = resolve;
        }),
    )
    .mockResolvedValueOnce(success(['new'], 1, 1));
  const { result, rerender } = setup(request);
  await waitFor(() => expect(result.current.data?.items).toEqual(['old']));
  act(() => result.current.loadMore());
  await waitFor(() => expect(request).toHaveBeenCalledTimes(2));
  rerender({ filter: 'new' });
  await waitFor(() => expect(result.current.data?.items).toEqual(['new']));
  await act(async () => finish?.(success(['obsolete'], 2)));
  expect(result.current.data?.items).toEqual(['new']);
  expect(request.mock.calls[1]?.[0].signal.aborted).toBe(true);
});

it('refreshes from the first page after a deletion without keeping obsolete pages', async () => {
  const request = vi
    .fn()
    .mockResolvedValueOnce(success(['one']))
    .mockResolvedValueOnce(success(['two'], 2))
    .mockResolvedValueOnce(success(['remaining'], 1, 1));
  const { result, client } = setup(request);
  await waitFor(() => expect(result.current.data?.items).toEqual(['one']));
  act(() => result.current.loadMore());
  await waitFor(() => expect(result.current.data?.items).toEqual(['one', 'two']));
  await act(async () => client.invalidateQueries({ queryKey: ['list'] }));
  await waitFor(() => expect(result.current.data?.items).toEqual(['remaining']));
  expect(request.mock.calls[2]?.[0]).toMatchObject({ page: 1, revision: 'start' });
  expect(request).toHaveBeenCalledTimes(3);
});

it('rebuilds every retained page with the new revision after an edit', async () => {
  const nextRevision = 'b'.repeat(64);
  const next = (items: string[], page: number) => ({
    ...success(items, page),
    response: new Response(null, { headers: { 'X-List-Revision': nextRevision } }),
  });
  const request = vi
    .fn()
    .mockResolvedValueOnce(success(['one']))
    .mockResolvedValueOnce(success(['two'], 2))
    .mockResolvedValueOnce(next(['edited'], 1))
    .mockResolvedValueOnce(next(['retained'], 2));
  const { result, client } = setup(request);
  await waitFor(() => expect(result.current.data?.items).toEqual(['one']));
  act(() => result.current.loadMore());
  await waitFor(() => expect(result.current.data?.items).toEqual(['one', 'two']));
  await act(async () => client.invalidateQueries({ queryKey: ['list'] }));
  await waitFor(() => expect(result.current.data?.items).toEqual(['edited', 'retained']));
  expect(request.mock.calls[3]?.[0]).toMatchObject({ page: 2, revision: nextRevision });
});
