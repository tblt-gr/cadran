import type { NetWorthPoint } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatCalendarMonth } from '@/lib/decimal';
import { plotRuns, polylinePoints, type PlotArea } from './plotGeometry';
import styles from './WealthChart.module.css';

const AREA: PlotArea = { height: 170, padding: 16, width: 620 };

interface WealthChartProps {
  points: NetWorthPoint[];
}

/**
 * The net-worth curve and its tabular alternative.
 *
 * The drawing is decorative: it is announced as one image and every figure
 * behind it stays readable in the table, where a month without a computable
 * value states its reason instead of dropping to zero.
 */
export function WealthChart({ points }: WealthChartProps) {
  const { i18n, t } = useTranslation();
  const runs = plotRuns(
    points.map((point) => point.total?.display?.value ?? point.total?.value ?? null),
    AREA,
  );

  return (
    <figure className={styles.chart} aria-labelledby="wealth-chart-title">
      <figcaption id="wealth-chart-title">{t('dashboard.chart.title')}</figcaption>
      {runs.length === 0 ? (
        <p className={styles.emptyPlot}>{t('dashboard.chart.empty')}</p>
      ) : (
        <svg
          aria-label={t('dashboard.chart.summary', {
            endPeriod: formatCalendarMonth(points[points.length - 1].on, i18n.language),
            startPeriod: formatCalendarMonth(points[0].on, i18n.language),
          })}
          className={styles.plot}
          preserveAspectRatio="none"
          role="img"
          viewBox={`0 0 ${AREA.width} ${AREA.height}`}
        >
          <defs>
            <linearGradient id="wealth-chart-area" x1="0" x2="0" y1="0" y2="1">
              <stop className={styles.areaTop} offset="0" />
              <stop className={styles.areaBottom} offset="1" />
            </linearGradient>
          </defs>
          <g className={styles.grid}>
            <path d={`M0 30H${AREA.width}M0 90H${AREA.width}`} />
          </g>
          {runs.map((run) => (
            <polygon
              className={styles.area}
              key={`area-${run[0].index}`}
              points={`${run[0].x},${AREA.height} ${polylinePoints(run)} ${run[run.length - 1].x},${AREA.height}`}
            />
          ))}
          {runs.map((run) => (
            <polyline
              className={styles.line}
              key={`line-${run[0].index}`}
              points={polylinePoints(run)}
            />
          ))}
          {/* A month standing alone between two uncomputable ones has no
              segment to belong to. Without a mark it would read as no data at
              all, which is the opposite of what it is. */}
          {runs
            .filter((run) => run.length === 1)
            .map((run) => (
              <circle
                className={styles.point}
                cx={run[0].x}
                cy={run[0].y}
                key={`point-${run[0].index}`}
                r="4"
              />
            ))}
        </svg>
      )}
      <details className={styles.details}>
        <summary>
          <span className={styles.disclosure}>
            <Icon name="chevron-right" size={14} />
          </span>
          {t('dashboard.chart.showTable')}
        </summary>
        <div className={styles.tableWrap}>
          <table aria-label={t('dashboard.chart.title')} className={styles.table}>
            <thead>
              <tr>
                <th scope="col">{t('dashboard.chart.monthColumn')}</th>
                <th scope="col">{t('dashboard.chart.valueColumn')}</th>
              </tr>
            </thead>
            <tbody>
              {points.map((point) => (
                <tr key={point.on}>
                  <th scope="row">{formatCalendarMonth(point.on, i18n.language)}</th>
                  <td>
                    <NetWorthFigure amount={point.total} reason={point.reason} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </details>
    </figure>
  );
}
