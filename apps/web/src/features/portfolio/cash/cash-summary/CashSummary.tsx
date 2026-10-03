import type { DecimalAmount, PortfolioCash } from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import styles from './CashSummary.module.css';

const rows: { key: string; label: string; pick: (cash: PortfolioCash) => DecimalAmount }[] = [
  { key: 'opening', label: 'Solde d’ouverture', pick: (cash) => cash.openingBalance },
  { key: 'deposits', label: 'Versements', pick: (cash) => cash.totals.deposits },
  { key: 'withdrawals', label: 'Retraits', pick: (cash) => cash.totals.withdrawals },
  { key: 'buys', label: 'Achats (brut)', pick: (cash) => cash.totals.buysGross },
  { key: 'sells', label: 'Ventes (brut)', pick: (cash) => cash.totals.sellsGross },
  { key: 'dividends', label: 'Dividendes (brut)', pick: (cash) => cash.totals.dividendsGross },
  { key: 'interest', label: 'Intérêts (brut)', pick: (cash) => cash.totals.interestGross },
  { key: 'fees', label: 'Frais', pick: (cash) => cash.totals.fees },
  { key: 'taxes', label: 'Taxes', pick: (cash) => cash.totals.taxes },
  { key: 'cashInLieu', label: 'Soulte reçue', pick: (cash) => cash.totals.cashInLieu },
  { key: 'closing', label: 'Solde de clôture', pick: (cash) => cash.closingBalance },
];

/** Current balance and the period figures; `null` renders the faithful loading layout. */
export function CashSummary({ cash }: { cash: PortfolioCash | null }) {
  const { i18n } = useTranslation();
  const [explaining, setExplaining] = useState(false);
  const titleId = useId();
  const explanationId = useId();
  const money = (amount: DecimalAmount) => (
    <MoneyValue value={formatAmount(amount.value, amount.assetCode, i18n.language)} />
  );

  return (
    <section aria-labelledby={titleId} className={`card ${styles.panel}`}>
      <h3 id={titleId}>Solde espèces</h3>
      <div className={styles.balance}>
        {cash ? money(cash.currentBalance) : <SkeletonBlock className={styles.balanceSkeleton} />}
      </div>
      <div className={styles.period}>
        <h4>
          Période
          <InfoButton
            aria-controls={explanationId}
            aria-expanded={explaining}
            label="Expliquer : solde de clôture"
            onClick={() => setExplaining((value) => !value)}
          />
        </h4>
        <p className={styles.explanation} hidden={!explaining} id={explanationId}>
          Solde de clôture = solde d’ouverture + versements − retraits − achats (brut) + ventes
          (brut) + dividendes (brut) + intérêts (brut) − frais − taxes + soulte reçue. Les frais et
          les taxes sont comptés une seule fois, quel que soit le type d’opération qui les porte.
        </p>
        <dl className={styles.figures}>
          {rows.map((row) => (
            <div key={row.key}>
              <dt>{row.label}</dt>
              <dd>
                {cash ? money(row.pick(cash)) : <SkeletonBlock className={styles.valueSkeleton} />}
              </dd>
            </div>
          ))}
        </dl>
      </div>
    </section>
  );
}
