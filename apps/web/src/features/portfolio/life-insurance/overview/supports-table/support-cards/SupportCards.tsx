import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { useTranslation } from 'react-i18next';
import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import type { Summary } from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import {
  supportCells,
  supportResultTone,
  useSupportHeaders,
} from '@/features/portfolio/life-insurance/overview/supports-table/supportPresentation';
import styles from './SupportCards.module.css';

/** Indexes in `supportHeaders`: the value reading first, the identification figures in "Détails". */
const MAIN = [5, 6, 7, 8];
const DETAILS = [1, 2, 3, 4, 9];

export function SupportCards({ summary }: { summary: Summary | null }) {
  const { t, i18n } = useTranslation();
  const supportHeaders = useSupportHeaders();
  const blocks = (positions: number[]) =>
    positions.map((position) => (
      <div key={supportHeaders[position]}>
        <dt>{supportHeaders[position]}</dt>
        <dd>
          <SkeletonBlock className={styles.value} />
        </dd>
      </div>
    ));
  return (
    <div className={styles.cards}>
      {!summary
        ? [0, 1].map((index) => (
            <article className={styles.support} key={index}>
              <SkeletonBlock className={styles.name} />
              <dl>{blocks(MAIN)}</dl>
              <details className={styles.details}>
                <summary>{t('portfolio.lifeInsurance.supports.details')}</summary>
                <dl>{blocks(DETAILS)}</dl>
              </details>
            </article>
          ))
        : summary.supports.map((row) => {
            const cells = supportCells(row, summary.assetCode, t, i18n.language);
            const figures = (positions: number[]) =>
              positions.map((position) => (
                <div key={supportHeaders[position]}>
                  <dt>{supportHeaders[position]}</dt>
                  <dd
                    className={
                      position === 8
                        ? `money ${styles[supportResultTone(row) ?? ''] ?? ''}`
                        : 'money'
                    }
                  >
                    {typeof cells[position] === 'string' ? (
                      <MoneyValue wrapGroups={false} value={cells[position]} />
                    ) : (
                      cells[position]
                    )}
                  </dd>
                </div>
              ));
            return (
              <article className={styles.support} key={row.instrumentId} aria-label={row.name}>
                <h4>
                  <InstrumentLink instrument={{ id: row.instrumentId, name: row.name }} />
                </h4>
                <dl>{figures(MAIN)}</dl>
                <details className={styles.details}>
                  <summary>{t('portfolio.lifeInsurance.supports.details')}</summary>
                  <dl>{figures(DETAILS)}</dl>
                </details>
              </article>
            );
          })}
    </div>
  );
}
