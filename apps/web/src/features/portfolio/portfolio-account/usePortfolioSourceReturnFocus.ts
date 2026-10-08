import { useEffect, useRef } from 'react';
import type { PortfolioTab } from './portfolio-tabs/PortfolioTabs';
/** Rebind a source link after its report tab returns, rather than focusing a detached node. */
export function usePortfolioSourceReturnFocus(active: PortfolioTab, asOf: string) {
  const ref = useRef<HTMLElement | null>(null);
  useEffect(() => {
    const source = ref.current;
    const location = `${window.location.pathname}${window.location.search}${window.location.hash}`;
    if (!source || source.isConnected || source.dataset.sourceReturnLocation !== location) return;
    const href = source.getAttribute('href');
    const link = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href]')).find(
      (candidate) => candidate.getAttribute('href') === href,
    );
    if (link) {
      ref.current = link;
      link.focus();
    }
  }, [active, asOf]);
  return ref;
}
