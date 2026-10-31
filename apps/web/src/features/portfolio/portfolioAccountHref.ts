import type { Account } from '@cadran/api-client';

/** An investment account whose value comes from its portfolio: its page is the portfolio page. */
export function valuedByPortfolio(account: Pick<Account, 'kind' | 'valuationMode'>): boolean {
  return (
    (account.kind === 'PORTFOLIO' || account.kind === 'INSURANCE_CONTRACT') &&
    account.valuationMode === 'PORTFOLIO'
  );
}

/**
 * The tab of a portfolio page that holds the account itself (the page shows it as the contract
 * tab of a life insurance), optionally narrowed to the bank movements of one month.
 */
export function portfolioAccountHref(accountId: string, month: string | null = null): string {
  const valid = month !== null && /^\d{4}-\d{2}$/.test(month);
  return `/portfolios/${accountId}?tab=account${valid ? `&month=${month}` : ''}`;
}
