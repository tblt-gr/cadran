import { afterEach, expect, it, vi } from 'vitest';
import { listActiveAccounts } from './transactionAccounts';

const api = vi.hoisted(() => ({ listAccounts: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));

afterEach(() => vi.clearAllMocks());

it('finds a primary account beyond the first page of options', async () => {
  const primary = { id: 'primary', isPrimary: true, status: 'ACTIVE' };
  api.listAccounts.mockImplementation(({ query }) =>
    Promise.resolve({
      data: {
        items:
          query.page === 1
            ? Array.from({ length: 100 }, (_, id) => ({ id: `account-${id}`, isPrimary: false }))
            : [primary],
        page: query.page,
        pageSize: 100,
        total: 101,
      },
      response: new Response('{}', { status: 200 }),
    }),
  );

  const result = await listActiveAccounts();
  expect(result.items).toHaveLength(101);
  expect(result.items.find((account) => account.isPrimary)).toEqual(primary);
  expect(api.listAccounts).toHaveBeenCalledTimes(2);
});
