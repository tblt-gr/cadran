import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './AnnualCharts.module.css';

interface ChartFrameProps {
  /** Controls at the end of the header, such as a grouping toggle. */
  actions?: ReactNode;
  children: ReactNode;
  /** The exact values behind the drawing, when the chart has no text alternative of its own. */
  data?: ReactNode;
  title: string;
}

export function ChartFrame({ actions, children, data, title }: ChartFrameProps) {
  const { t } = useTranslation();

  return (
    <section aria-label={title} className={`card ${styles.chart}`}>
      <header className={styles.header}>
        <h3>{title}</h3>
        {actions}
      </header>
      <div className={styles.plot}>{children}</div>
      {data ? (
        <details>
          <summary>{t('reports.annual.charts.viewData')}</summary>
          {data}
        </details>
      ) : null}
    </section>
  );
}
