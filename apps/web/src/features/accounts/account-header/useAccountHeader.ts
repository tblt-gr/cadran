import type { Account } from '@cadran/api-client';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';

/** Publishes the account name as the page title; institution and product live in the identity panel. */
export function useAccountHeader(path: string, account: Account | undefined) {
  useHeaderTitle(path, account?.label ?? null);
}
