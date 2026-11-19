import type { DecimalAmount, PortfolioCash } from '@cadran/api-client';
import { useId, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount, isZeroDecimal } from '@/lib/decimal';
import styles from './CashSummary.module.css';

type RowKey =
  | 'opening'
  | 'deposits'
  | 'withdrawals'
  | 'buys'
  | 'sells'
  | 'supportsBought'
  | 'supportsSold'
  | 'dividends'
  | 'interest'
  | 'fees'
  | 'taxes'
  | 'cashInLieu'
  | 'closing';

const rows: { key: RowKey; pick: (cash: PortfolioCash) => DecimalAmount }[] = [
  { key: 'opening', pick: (cash) => cash.openingBalance },
  { key: 'deposits', pick: (cash) => cash.totals.deposits },
  { key: 'withdrawals', pick: (cash) => cash.totals.withdrawals },
  { key: 'buys', pick: (cash) => cash.totals.buysGross },
  { key: 'sells', pick: (cash) => cash.totals.sellsGross },
  { key: 'supportsBought', pick: (cash) => cash.totals.supportsBought },
  { key: 'supportsSold', pick: (cash) => cash.totals.supportsSold },
  { key: 'dividends', pick: (cash) => cash.totals.dividendsGross },
  { key: 'interest', pick: (cash) => cash.totals.interestGross },
  { key: 'fees', pick: (cash) => cash.totals.fees },
  { key: 'taxes', pick: (cash) => cash.totals.taxes },
  { key: 'cashInLieu', pick: (cash) => cash.totals.cashInLieu },
  { key: 'closing', pick: (cash) => cash.closingBalance },
];

/** The balances that frame the period, always listed even when zero. */
const frame = new Set(['opening', 'closing']);

/**
 * Current balance and the period figures. Only the flows that moved during the period are
 * listed between the opening and closing balances; `null` renders the loading layout with the
 * framing balances.
 */
export function CashSummary({
  cash,
  checks,
}: {
  cash: PortfolioCash | null;
  /** The cash checks pill, beside the title. */
  checks?: ReactNode;
}) {
  const { i18n, t } = useTranslation();
  const [explaining, setExplaining] = useState(false);
  const titleId = useId();
  const explanationId = useId();
  const money = (amount: DecimalAmount) => (
    <MoneyValue value={formatAmount(amount.value, amount.assetCode, i18n.language)} />
  );

  return (
    <section aria-labelledby={titleId} className={`card ${styles.panel}`}>
      <div className={styles.titleRow}>
        <h3 id={titleId}>{t('portfolio.cash.summary.title')}</h3>
        {checks}
      </div>
      <div className={styles.balance}>
        {cash ? money(cash.currentBalance) : <SkeletonBlock className={styles.balanceSkeleton} />}
      </div>
      <div className={styles.period}>
        <h4>
          {t('portfolio.cash.summary.period')}
          <InfoButton
            aria-controls={explanationId}
            aria-expanded={explaining}
            label={t('portfolio.cash.summary.explainClosing')}
            onClick={() => setExplaining((value) => !value)}
          />
        </h4>
        <p className={styles.explanation} hidden={!explaining} id={explanationId}>
          {t('portfolio.cash.summary.formula')}
        </p>
        <dl className={styles.figures}>
          {rows
            .filter((row) =>
              cash
                ? frame.has(row.key) || !isZeroDecimal(row.pick(cash).value)
                : frame.has(row.key),
            )
            .map((row) => (
              <div key={row.key}>
                <dt>{t(`portfolio.cash.summary.rows.${row.key}`)}</dt>
                <dd>
                  {cash ? (
                    money(row.pick(cash))
                  ) : (
                    <SkeletonBlock className={styles.valueSkeleton} />
                  )}
                </dd>
              </div>
            ))}
        </dl>
      </div>
    </section>
  );
}
