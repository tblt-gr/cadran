import { useCallback, useMemo, useState, type ReactNode } from 'react';
import { BreadcrumbContext, type AccountLabel } from './BreadcrumbContext';

export function BreadcrumbProvider({ children }: { children: ReactNode }) {
  const [account, setAccount] = useState<AccountLabel | null>(null);
  const publish = useCallback((value: AccountLabel | null) => setAccount(value), []);
  const context = useMemo(() => ({ account, publish }), [account, publish]);
  return <BreadcrumbContext.Provider value={context}>{children}</BreadcrumbContext.Provider>;
}
