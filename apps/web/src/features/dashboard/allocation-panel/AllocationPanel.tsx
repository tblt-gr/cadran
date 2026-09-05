import type { NetWorth } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatSharePercent } from '@/lib/formatSharePercent';
import { AllocationBar } from './AllocationBar';
import styles from './AllocationPanel.module.css';

interface AllocationPanelProps {
  netWorth: NetWorth;
}

/**
 * The exclusive weight of each top-level group.
 *
 * Only the first level is listed: a child already counts inside its parent, so
 * showing both would let the same euro be read twice. Every percentage is the
 * string the backend produced; the bar repeats it and carries no meaning of
 * its own.
 */
export function AllocationPanel({ netWorth }: AllocationPanelProps) {
  const { t } = useTranslation();
  const roots = netWorth.allocation.filter((entry) => entry.depth === 1);

  return (
    <section className={`card ${styles.card}`} aria-labelledby="allocation-title">
      <div className={styles.heading}>
        <div>
          <h2 id="allocation-title">{t('dashboard.allocation.title')}</h2>
          <p>{t('dashboard.allocation.description')}</p>
        </div>
        <a className={styles.link} href="/account-groups">
          {t('dashboard.allocation.viewGroups')}
        </a>
      </div>

      {roots.length === 0 ? (
        <p className={styles.empty}>{t('dashboard.allocation.empty')}</p>
      ) : (
        <ul className={styles.list}>
          {roots.map((entry) => (
            <li key={entry.groupId}>
              <span className={styles.name}>{entry.label}</span>
              <span className={styles.share}>
                {entry.share.percent === null
                  ? t('states.notCalculable.label')
                  : formatSharePercent(entry.share.percent)}
              </span>
              <strong className={`money ${styles.amount}`}>
                <NetWorthFigure amount={entry.value} reason={netWorth.reason} />
              </strong>
              <AllocationBar percent={entry.share.percent} />
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
