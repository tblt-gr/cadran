import type {
  PortfolioAnnualMoneyWeighted,
  PortfolioAnnualPeriodLine,
  PortfolioAnnualRateCell,
  PortfolioPerformanceReason,
} from '@cadran/api-client';
import type { ParseKeys } from 'i18next';
import type { ReactNode } from 'react';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Rate, signed } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import type { FigureKey } from '@/features/portfolio/performance/performanceFigures';
import {
  summarizeReason,
  type ReasonContext,
} from '@/features/portfolio/performance/performanceReasons';
import { formatAmount } from '@/lib/decimal';

export interface LineContext extends ReasonContext {
  /** The year line labels its MWR as not chained; a month shows its period rate. */
  kind: 'month' | 'year';
}

export interface LineColumn {
  id: string;
  /** Translation key of the column heading. */
  label: ParseKeys;
  /** Present when the cell opens a PERF-005 explanation. */
  figure?: FigureKey;
  render: (line: PortfolioAnnualPeriodLine, context: LineContext) => ReactNode;
}

function missing(reasons: PortfolioPerformanceReason[], context: LineContext) {
  const reason = reasons[0];
  return (
    <EmptyValue
      label={context.t('portfolio.performance.value.notCalculable')}
      reason={reason ? summarizeReason(reason, context) : null}
    />
  );
}

function amount(
  pick: (line: PortfolioAnnualPeriodLine) => string | null,
  { withSign }: { withSign: boolean },
): LineColumn['render'] {
  return (line, context) => {
    const value = pick(line);
    if (value === null) return missing(line.gainReasons, context);
    const text = formatAmount(value, context.assetCode, context.locale);
    if (!withSign) return text;
    const { text: shown, tone } = signed(text);
    return <span className={tone}>{shown}</span>;
  };
}

function rateOf(cell: PortfolioAnnualRateCell, context: LineContext) {
  return cell.value === null ? missing(cell.reasons, context) : <Rate ratio={cell.value} />;
}

/** A month shows the period rate; the year line shows the annual rate and says it is not chained. */
function moneyWeighted(figure: PortfolioAnnualMoneyWeighted, context: LineContext) {
  const year = context.kind === 'year';
  const ratio = year && !figure.shortPeriod ? figure.annualizedRate : figure.periodRate;
  if (ratio === null) return missing(figure.reasons, context);
  return (
    <>
      <Rate ratio={ratio} />
      {year ? (
        <>
          {' '}
          <span title={context.t('portfolio.performance.lines.notChainedHint')}>
            {context.t('portfolio.performance.lines.notChained')}
          </span>
        </>
      ) : null}
    </>
  );
}

/** The fourteen figure columns after the month label, shared by the table and the cards. */
export const lineColumns: LineColumn[] = [
  {
    id: 'startValue',
    label: 'portfolio.performance.columns.startValue',
    render: amount((l) => l.startValue, { withSign: false }),
  },
  {
    id: 'deposits',
    label: 'portfolio.performance.columns.deposits',
    render: amount((l) => l.deposits, { withSign: false }),
  },
  {
    id: 'withdrawals',
    label: 'portfolio.performance.columns.withdrawals',
    render: amount((l) => l.withdrawals, { withSign: false }),
  },
  {
    id: 'endValue',
    label: 'portfolio.performance.columns.endValue',
    render: amount((l) => l.endValue, { withSign: false }),
  },
  {
    id: 'gain',
    label: 'portfolio.performance.columns.gain',
    figure: 'gain',
    render: amount((l) => l.gain, { withSign: true }),
  },
  {
    id: 'realized',
    label: 'portfolio.performance.columns.realized',
    render: amount((l) => l.realized, { withSign: true }),
  },
  {
    id: 'unrealizedChange',
    label: 'portfolio.performance.columns.unrealizedChange',
    render: amount((l) => l.unrealizedChange, { withSign: true }),
  },
  {
    id: 'income',
    label: 'portfolio.performance.columns.income',
    render: amount((l) => l.income, { withSign: true }),
  },
  {
    id: 'fees',
    label: 'portfolio.performance.columns.fees',
    render: amount((l) => l.fees, { withSign: true }),
  },
  {
    id: 'feesPaid',
    label: 'portfolio.performance.columns.feesPaid',
    render: amount((l) => l.feesPaid, { withSign: true }),
  },
  {
    id: 'taxes',
    label: 'portfolio.performance.columns.taxes',
    render: amount((l) => l.taxes, { withSign: true }),
  },
  {
    id: 'modifiedDietz',
    label: 'portfolio.performance.columns.modifiedDietz',
    figure: 'modifiedDietz',
    render: (line, context) => rateOf(line.modifiedDietz, context),
  },
  {
    id: 'timeWeighted',
    label: 'portfolio.performance.columns.timeWeighted',
    figure: 'timeWeighted',
    render: (line, context) => rateOf(line.timeWeighted, context),
  },
  {
    id: 'moneyWeighted',
    label: 'portfolio.performance.columns.moneyWeighted',
    figure: 'moneyWeighted',
    render: (line, context) => moneyWeighted(line.moneyWeighted, context),
  },
];
