import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './AnnualCharts.module.css';

interface ChartFrameProps {
  children: ReactNode;
  /** The exact values behind the drawing, when the chart has no text alternative of its own. */
  data?: ReactNode;
  title: string;
}

export function ChartFrame({ children, data, title }: ChartFrameProps) {
  const { t } = useTranslation();

  return (
    <section aria-label={title} className={`card ${styles.chart}`}>
      <h3>{title}</h3>
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
