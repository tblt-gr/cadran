import { Fragment, useSyncExternalStore, type ReactNode } from 'react';
import { areAmountsHidden, subscribeAmountPrivacy } from '@/lib/amountPrivacy';

/**
 * Amounts are formatted by pure functions that read the shared privacy state, so a change
 * must reach memoised and cached output alike. The subtree is therefore remounted on a
 * toggle; server state lives in the query cache and survives the remount.
 */
export function AmountPrivacyProvider({ children }: { children: ReactNode }) {
  const hidden = useSyncExternalStore(subscribeAmountPrivacy, areAmountsHidden);

  return <Fragment key={hidden ? 'hidden' : 'visible'}>{children}</Fragment>;
}
