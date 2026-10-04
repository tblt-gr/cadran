import type { PortfolioAnnualPerformance } from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
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
import styles from './PerformanceMonthTable.module.css';

/**
 * The twelve months and the year line, at least 768 px wide. It is also the accessible equivalent
 * of the curve. `null` renders the loading layout: real headers, every value a block.
 */
export function PerformanceMonthTable({
  annual,
  year,
  onExplain,
}: {
  annual: PortfolioAnnualPerformance | null;
  year?: number;
  onExplain: (target: ExplainTarget) => void;
}) {
  const shownYear = annual?.year ?? year;
  const base = {
    accountId: annual?.accountId ?? '',
    assetCode: annual?.assetCode ?? '',
    residual: null,
  };
  const monthContext: LineContext = { ...base, kind: 'month' };
  const yearContext: LineContext = { ...base, kind: 'year' };

  return (
    <div className={styles.scroll} data-testid="performance-scroll">
      <table aria-label="Performance par mois" className={styles.table}>
        <thead>
          <tr>
            <th scope="col">Mois</th>
            {lineColumns.map((column) => (
              <th key={column.id} scope="col">
                {column.label}
              </th>
            ))}
          </tr>
        </thead>
        {annual ? (
          <tbody>
            {annual.months.map((line) =>
              !('startValue' in line) ? (
                <tr className={styles.greyed} data-status={line.status} key={line.month}>
                  <th scope="row">{monthName(line.month)}</th>
                  {lineColumns.map((column) => (
                    <td key={column.id}>
                      <EmptyValue label={emptyMonthLabel[line.status]} />
                    </td>
                  ))}
                </tr>
              ) : (
                <tr data-status={line.status} key={line.month}>
                  <th scope="row">
                    <span title={anchorsTitle(line.from, line.to)}>{monthName(line.month)}</span>
                  </th>
                  {lineColumns.map((column) => {
                    const content = column.render(line, monthContext);
                    return (
                      <td key={column.id}>
                        {column.figure ? (
                          <FigureCell
                            figure={column.figure}
                            onExplain={() =>
                              onExplain({
                                kind: 'month',
                                month: line.month,
                                figure: column.figure!,
                              })
                            }
                            scope={monthName(line.month)}
                          >
                            {content}
                          </FigureCell>
                        ) : (
                          content
                        )}
                      </td>
                    );
                  })}
                </tr>
              ),
            )}
          </tbody>
        ) : (
          <SkeletonTableRows columns={lineColumns.length + 1} rows={12} />
        )}
        <tfoot>
          <tr>
            <th scope="row">Année {shownYear}</th>
            {lineColumns.map((column) => (
              <td key={column.id}>
                {!annual ? (
                  <SkeletonBlock className={styles.footSkeleton} />
                ) : column.figure ? (
                  <FigureCell
                    figure={column.figure}
                    onExplain={() => onExplain({ kind: 'year', figure: column.figure! })}
                    scope={`année ${annual.year}`}
                  >
                    {column.render(annual.yearTotal, yearContext)}
                  </FigureCell>
                ) : (
                  column.render(annual.yearTotal, yearContext)
                )}
              </td>
            ))}
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
