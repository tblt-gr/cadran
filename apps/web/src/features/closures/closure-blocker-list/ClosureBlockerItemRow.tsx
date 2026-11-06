import type { PeriodClosingBlockerItem, PeriodClosingReason } from '@cadran/api-client';
import type { MouseEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount, formatCalendarDay, isZeroDecimal } from '@/lib/decimal';
import styles from './ClosureBlockerItemRow.module.css';

const ACTION_BY_REASON: Record<PeriodClosingReason, string> = {
  NO_BALANCE_IN_MONTH: 'record-balance',
  BALANCE_NOT_RECONCILED: 'reconcile',
  DISCREPANCY: 'reconcile',
  NO_MONTH_END_VALUATION: 'record-valuation',
  VALUATION_NOT_CALCULATED: 'record-valuation',
};

/** Fixed in-app paths only: the account id is the sole interpolated part and is encoded. */
function itemLinks(item: PeriodClosingBlockerItem, asOf: string) {
  const portfolio =
    item.reason === 'NO_MONTH_END_VALUATION' || item.reason === 'VALUATION_NOT_CALCULATED';
  const account = `${portfolio ? '/portfolios' : '/accounts'}/${encodeURIComponent(item.accountId)}`;
  const tab = portfolio ? 'valuations' : 'balance';
  const params = new URLSearchParams({ tab, action: ACTION_BY_REASON[item.reason], asOf });

  return { account, action: `${account}?${params.toString()}` };
}

/** A gap keeps its sign, `+` included, and is rounded only for display. */
function signedGap(value: string, assetCode: string, locale: string): string {
  const formatted = formatAmount(value, assetCode, locale);

  return isZeroDecimal(value) || value.startsWith('-') ? formatted : `+${formatted}`;
}

interface ClosureBlockerItemRowProps {
  /** Last day (`YYYY-MM-DD`) of the closure month, handed to the fix to prefill its date. */
  asOf: string;
  item: PeriodClosingBlockerItem;
  onNavigate?: () => void;
}

/** One account standing in the way of a closing: where it is, why, and the screen that fixes it. */
export function ClosureBlockerItemRow({ asOf, item, onNavigate }: ClosureBlockerItemRowProps) {
  const { i18n, t } = useTranslation();
  const links = itemLinks(item, asOf);
  const date = item.snapshotAsOf ? formatCalendarDay(item.snapshotAsOf, i18n.language) : '-';
  const amount = item.discrepancy
    ? signedGap(item.discrepancy.value, item.discrepancy.assetCode, i18n.language)
    : '-';

  function follow(event: MouseEvent<HTMLAnchorElement>, href: string) {
    if (handleClientNavigation(event, href)) onNavigate?.();
  }

  return (
    <li className={styles.row}>
      <a
        className={styles.account}
        href={links.account}
        onClick={(event) => follow(event, links.account)}
      >
        {item.label}
      </a>
      <span className={styles.reason}>
        {t(`closures.items.reasons.${item.reason}`, { amount, date })}
      </span>
      <a
        className={`secondary-action ${styles.action}`}
        href={links.action}
        onClick={(event) => follow(event, links.action)}
      >
        {t(`closures.items.actions.${item.reason}`)}
      </a>
    </li>
  );
}
