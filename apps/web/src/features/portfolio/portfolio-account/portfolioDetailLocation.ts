import type { RefObject } from 'react';
/** Dismiss detail deep links in place, keeping the account context and browser history intact. */
export function clearPortfolioDetailSelection(): URL {
  const url = new URL(window.location.href);
  const selected = url.searchParams.has('operation') || url.searchParams.has('valuation');
  url.searchParams.delete('operation');
  url.searchParams.delete('valuation');
  if (selected) {
    window.history.replaceState(
      window.history.state,
      '',
      `${url.pathname}${url.search}${url.hash}`,
    );
  }
  return url;
}

/** The originating report tab can unmount while its operation source is open. */
export function capturePortfolioSource(
  link: HTMLAnchorElement,
  returnFocus?: RefObject<HTMLElement | null>,
) {
  if (!returnFocus) return;
  link.dataset.sourceReturnLocation = `${window.location.pathname}${window.location.search}${window.location.hash}`;
  returnFocus.current = link;
}

export function dismissPortfolioDetailSelection(returnFocus?: RefObject<HTMLElement | null>) {
  const current = clearPortfolioDetailSelection();
  const target = returnFocus?.current;
  const location = target?.dataset.sourceReturnLocation;
  if (!location || target.isConnected) return;
  const origin = new URL(location, window.location.origin);
  if (origin.origin !== current.origin || origin.pathname !== current.pathname) return;
  window.history.replaceState(
    window.history.state,
    '',
    `${origin.pathname}${origin.search}${origin.hash}`,
  );
  window.dispatchEvent(new PopStateEvent('popstate'));
}
