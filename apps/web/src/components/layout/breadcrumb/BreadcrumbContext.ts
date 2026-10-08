import { createContext, useContext, useEffect } from 'react';

export interface AccountLabel {
  path: string;
  label: string | null;
  pending: boolean;
}

export const BreadcrumbContext = createContext<{
  account: AccountLabel | null;
  publish: (value: AccountLabel | null) => void;
}>({ account: null, publish: () => {} });

/** Pages publish their existing query result; the shell never reads account data itself. */
export function useBreadcrumbAccount(path: string, label: string | null, pending: boolean) {
  const { publish } = useContext(BreadcrumbContext);
  useEffect(() => {
    publish({ path, label, pending });
    return () => publish(null);
  }, [path, label, pending, publish]);
}

export function useBreadcrumbAccountValue(path: string) {
  const { account } = useContext(BreadcrumbContext);
  return account?.path === path ? account : null;
}
