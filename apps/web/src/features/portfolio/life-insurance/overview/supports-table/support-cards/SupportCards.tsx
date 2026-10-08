import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import type { Summary } from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import {
  supportCells,
  supportHeaders,
} from '@/features/portfolio/life-insurance/overview/supports-table/supportPresentation';
import styles from './SupportCards.module.css';
export function SupportCards({ summary }: { summary: Summary | null }) {
  return (
    <div className={styles.cards}>
      {!summary
        ? [0, 1].map((index) => (
            <article className={styles.support} key={index}>
              <SkeletonBlock className={styles.name} />
              <dl>
                {supportHeaders.slice(1).map((label) => (
                  <div key={label}>
                    <dt>{label}</dt>
                    <dd>
                      <SkeletonBlock className={styles.value} />
                    </dd>
                  </div>
                ))}
              </dl>
            </article>
          ))
        : summary.supports.map((row) => {
            const cells = supportCells(row, summary.assetCode);
            return (
              <article className={styles.support} key={row.instrumentId} aria-label={row.name}>
                <h4>{row.name}</h4>
                <dl>
                  {cells.slice(1).map((cell, index) => (
                    <div key={supportHeaders[index + 1]}>
                      <dt>{supportHeaders[index + 1]}</dt>
                      <dd className="money">{cell}</dd>
                    </div>
                  ))}
                </dl>
              </article>
            );
          })}
    </div>
  );
}
