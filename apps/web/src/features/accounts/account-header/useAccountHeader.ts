import type { Account } from '@cadran/api-client';
import { useHeaderTitle } from '@/components/layout/header/HeaderTitleContext';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { todayInBrowser } from '@/lib/businessDay';

export function useAccountHeader(path: string, account: Account | undefined) {
  const products = useProductOptions(todayInBrowser());
  const product = products.items.find((item) => item.code === account?.productCode);
  const subtitle = [account?.institution, product?.displayName].filter(Boolean).join(' · ');
  useHeaderTitle(path, account?.label ?? null, subtitle);
}
