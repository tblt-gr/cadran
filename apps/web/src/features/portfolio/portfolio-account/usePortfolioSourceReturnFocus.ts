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
    const qualitySourceKey = source.dataset.qualitySourceKey;
    const link = Array.from(document.querySelectorAll<HTMLAnchorElement>('a[href]')).find(
      (candidate) =>
        candidate.getAttribute('href') === href &&
        (!qualitySourceKey || candidate.dataset.qualitySourceKey === qualitySourceKey),
    );
    if (link) {
      ref.current = link;
      // A remounted sources disclosure starts closed; reveal the target before focusing it.
      const disclosure = link.closest('details');
      if (disclosure) disclosure.open = true;
      link.focus();
    }
  }, [active, asOf]);
  useEffect(() => {
    const observer = new MutationObserver(() => {
      if (
        ref.current &&
        !ref.current.isConnected &&
        !document.querySelector('[role="dialog"]') &&
        document.activeElement === document.body
      ) {
        document
          .querySelector<HTMLElement>(
            'nav[aria-label="Sections du portefeuille"] [aria-current="page"]',
          )
          ?.focus();
      }
    });
    observer.observe(document.body, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, []);
  return ref;
}
