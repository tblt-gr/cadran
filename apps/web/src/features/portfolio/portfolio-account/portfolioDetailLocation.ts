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
