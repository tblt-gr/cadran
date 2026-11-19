import type { TFunction } from 'i18next';
import { warningLabel } from '@/features/portfolio/portfolio-account/overviewLabels';
import type { PortfolioTab } from '@/features/portfolio/portfolio-account/portfolio-tabs/PortfolioTabs';

/** What a review point means, what to do about it and the tab where it is fixed. */
interface ReviewGuide {
  title: string;
  hint: string;
  tab: PortfolioTab;
}

/** Whether the title carries the number of items behind the point. */
const guides = {
  NEGATIVE_CASH: { counted: true, tab: 'cash' },
  UNLINKED_TRANSFER_IN: { counted: true, tab: 'cash' },
  UNLINKED_TRANSFER_OUT: { counted: true, tab: 'cash' },
  LINKED_TRANSFER_CHANGED: { counted: true, tab: 'cash' },
  NON_TRANSFER_TRANSACTION: { counted: true, tab: 'account' },
  CASH_DIFFERENCE: { counted: false, tab: 'valuations' },
  OBSERVED_TOTAL_DIFFERENCE: { counted: false, tab: 'valuations' },
  POSITIONS_CHANGED: { counted: false, tab: 'valuations' },
  NEGATIVE_SECURITIES_VALUE: { counted: false, tab: 'valuations' },
  STALE_PRICES: { counted: false, tab: 'valuations' },
  PARTIAL_VALUATION: { counted: false, tab: 'valuations' },
  STALE_VALUATION: { counted: false, tab: 'valuations' },
  UNKNOWN_LOT_COST: { counted: true, tab: 'operations' },
  ORDER_DEPENDENT_DAY: { counted: true, tab: 'operations' },
} as const satisfies Record<string, { counted: boolean; tab: PortfolioTab }>;

type GuideCode = keyof typeof guides;

function isGuideCode(code: string): code is GuideCode {
  return code in guides;
}

/** A code without advice still shows under its label, sent to Operations, rather than hidden. */
export function reviewGuide(t: TFunction, code: string, count: number): ReviewGuide {
  if (!isGuideCode(code)) {
    const label = (warningLabel(t, code) ?? code).replace(/\.$/, '');
    return { title: label, hint: '', tab: 'operations' };
  }
  const guide = guides[code];
  return {
    title: guide.counted
      ? t(`portfolio.account.review.${code}.title`, { count })
      : t(`portfolio.account.review.${code}.title`),
    hint: t(`portfolio.account.review.${code}.hint`),
    tab: guide.tab,
  };
}

export function tabLinkLabel(t: TFunction, tab: PortfolioTab): string | undefined {
  return tab === 'cash' || tab === 'valuations' || tab === 'operations' || tab === 'account'
    ? t(`portfolio.account.review.links.${tab}`)
    : undefined;
}
