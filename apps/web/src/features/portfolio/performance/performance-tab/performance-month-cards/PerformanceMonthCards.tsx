import type { PortfolioAnnualPerformance, PortfolioAnnualPeriodLine } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { FigureCell } from '@/features/portfolio/performance/performance-tab/figure-cell/FigureCell';
import type { ExplainTarget } from '@/features/portfolio/performance/performance-tab/explainTarget';
import {
  lineColumns,
  type LineContext,
} from '@/features/portfolio/performance/performance-tab/lineColumns';
import {
  anchorsTitle,
  emptyMonthLabel,
  monthName,
} from '@/features/portfolio/performance/performance-tab/monthLabels';
import styles from './PerformanceMonthCards.module.css';

function Card({
  id,
  title,
  titleHint,
  status,
  line,
  context,
  onExplain,
  target,
  emptyLabel,
}: {
  id: string;
  title: string;
  titleHint?: string;
  status: string;
  line: PortfolioAnnualPeriodLine | null;
  context: LineContext;
  onExplain: (target: ExplainTarget) => void;
  target: (figure: NonNullable<(typeof lineColumns)[number]['figure']>) => ExplainTarget;
  emptyLabel?: string;
}) {
  return (
    <article
      aria-labelledby={id}
      className={`card ${styles.card} ${emptyLabel ? styles.greyed : ''}`}
      data-status={status}
    >
      <h3 id={id} title={titleHint}>
        {title}
      </h3>
      <dl className={styles.figures}>
        {lineColumns.map((column) => (
          <div className={styles.figure} key={column.id}>
            <dt>{column.label}</dt>
            <dd>
              {emptyLabel ? (
                <EmptyValue label={emptyLabel} />
              ) : !line ? (
                <SkeletonBlock className={styles.skeleton} />
              ) : column.figure ? (
                <FigureCell
                  figure={column.figure}
                  onExplain={() => onExplain(target(column.figure!))}
                  scope={title}
                >
                  {column.render(line, context)}
                </FigureCell>
              ) : (
                column.render(line, context)
              )}
            </dd>
          </div>
        ))}
      </dl>
    </article>
  );
}

/** The same figures as the month table, one card per month then the year, under 768 px. */
export function PerformanceMonthCards({
  annual,
  year,
  onExplain,
}: {
  annual: PortfolioAnnualPerformance | null;
  year?: number;
  onExplain: (target: ExplainTarget) => void;
}) {
  const base = {
    accountId: annual?.accountId ?? '',
    assetCode: annual?.assetCode ?? '',
    residual: null,
  };
  const shownYear = annual?.year ?? year;
  const months = annual
    ? annual.months
    : Array.from({ length: 12 }, (_, index) => ({
        month: `${shownYear}-${String(index + 1).padStart(2, '0')}`,
        status: 'LOADING' as const,
      }));

  return (
    <div className={styles.cards}>
      {months.map((line) => {
        const empty =
          line.status === 'BEFORE_OPENING' || line.status === 'FUTURE'
            ? emptyMonthLabel[line.status]
            : undefined;
        return (
          <Card
            context={{ ...base, kind: 'month' }}
            emptyLabel={empty}
            id={`performance-card-${line.month}`}
            key={line.month}
            line={'startValue' in line ? line : null}
            onExplain={onExplain}
            status={line.status}
            target={(figure) => ({ kind: 'month', month: line.month, figure })}
            title={monthName(line.month)}
            titleHint={'from' in line ? anchorsTitle(line.from, line.to) : undefined}
          />
        );
      })}
      <Card
        context={{ ...base, kind: 'year' }}
        id="performance-card-year"
        line={annual?.yearTotal ?? null}
        onExplain={onExplain}
        status="YEAR"
        target={(figure) => ({ kind: 'year', figure })}
        title={`Année ${shownYear}`}
      />
    </div>
  );
}
