import type { PortfolioAnnualPerformance, PortfolioAnnualPeriodLine } from '@cadran/api-client';
import { useState } from 'react';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
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

/** What a folded card keeps visible: the closing value and the unrealised change. */
const FOLDED_FIGURES = ['endValue', 'unrealizedChange'];

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
  selected,
}: {
  id: string;
  title: string;
  titleHint?: string;
  status: string;
  line: PortfolioAnnualPeriodLine | null;
  context: LineContext;
  onExplain: (target: ExplainTarget) => void;
  target: (figure: NonNullable<(typeof lineColumns)[number]['figure']>) => ExplainTarget;
  selected?: string[];
  emptyLabel?: string;
}) {
  const [open, setOpen] = useState(false);
  const heading = (
    <h3 id={id} title={titleHint}>
      {title}
    </h3>
  );

  if (emptyLabel) {
    return (
      <article
        aria-labelledby={id}
        className={`card ${styles.card} ${styles.greyed}`}
        data-status={status}
      >
        {heading}
        <p className={styles.empty}>
          <EmptyValue label={emptyLabel} />
        </p>
      </article>
    );
  }

  const value = (column: (typeof lineColumns)[number]) =>
    !line ? (
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
    );

  return (
    <article
      aria-labelledby={id}
      className={`card ${styles.card} ${styles.collapsible}`}
      data-status={status}
    >
      <Disclosure
        meta={
          <span className={styles.folded}>
            {lineColumns
              .filter((column) => FOLDED_FIGURES.includes(column.id))
              .map((column) => (
                <span className={styles.foldedFigure} key={column.id}>
                  <span className={styles.foldedLabel}>{column.label}</span>
                  {!line ? (
                    <SkeletonBlock className={styles.skeleton} />
                  ) : (
                    column.render(line, context)
                  )}
                </span>
              ))}
          </span>
        }
        onToggle={setOpen}
        open={open}
        spread
        title={heading}
      >
        <dl className={styles.figures}>
          {lineColumns
            .filter((column) => !selected || selected.includes(column.id))
            .map((column) => (
              <div className={styles.figure} key={column.id}>
                <dt>{column.label}</dt>
                <dd>{value(column)}</dd>
              </div>
            ))}
        </dl>
      </Disclosure>
    </article>
  );
}

/** The same figures as the month table, one card per month then the year, under 768 px. */
export function PerformanceMonthCards({
  annual,
  selected,
  year,
  onExplain,
}: {
  selected?: string[];
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
            selected={selected}
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
        selected={selected}
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
