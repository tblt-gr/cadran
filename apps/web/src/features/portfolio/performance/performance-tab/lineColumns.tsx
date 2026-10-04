import type {
  PortfolioAnnualMoneyWeighted,
  PortfolioAnnualPeriodLine,
  PortfolioAnnualRateCell,
  PortfolioPerformanceReason,
} from '@cadran/api-client';
import type { ReactNode } from 'react';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Rate, signed } from '@/features/portfolio/performance/performance-value/PerformanceValue';
import type { FigureKey } from '@/features/portfolio/performance/performanceFigures';
import {
  describeReason,
  type ReasonContext,
} from '@/features/portfolio/performance/performanceReasons';
import { formatAmount } from '@/lib/decimal';

export interface LineContext extends ReasonContext {
  /** The year line labels its MWR as not chained; a month shows its period rate. */
  kind: 'month' | 'year';
}

export interface LineColumn {
  id: string;
  label: string;
  /** Present when the cell opens a PERF-005 explanation. */
  figure?: FigureKey;
  render: (line: PortfolioAnnualPeriodLine, context: LineContext) => ReactNode;
}

function missing(reasons: PortfolioPerformanceReason[], context: LineContext) {
  const reason = reasons[0];
  return (
    <EmptyValue
      label="Non calculable"
      reason={reason ? describeReason(reason, context).sentence : null}
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
    const text = formatAmount(value, context.assetCode, 'fr');
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
          <span title="Calculé sur les flux de l'année, pas à partir des mois.">non chaîné</span>
        </>
      ) : null}
    </>
  );
}

/** The thirteen figure columns after the month label, shared by the table and the cards. */
export const lineColumns: LineColumn[] = [
  {
    id: 'startValue',
    label: 'Valeur début',
    render: amount((l) => l.startValue, { withSign: false }),
  },
  { id: 'deposits', label: 'Versements', render: amount((l) => l.deposits, { withSign: false }) },
  {
    id: 'withdrawals',
    label: 'Retraits',
    render: amount((l) => l.withdrawals, { withSign: false }),
  },
  { id: 'endValue', label: 'Valeur fin', render: amount((l) => l.endValue, { withSign: false }) },
  { id: 'gain', label: 'Gain', figure: 'gain', render: amount((l) => l.gain, { withSign: true }) },
  { id: 'realized', label: 'Réalisé', render: amount((l) => l.realized, { withSign: true }) },
  {
    id: 'unrealizedChange',
    label: 'Var. latente',
    render: amount((l) => l.unrealizedChange, { withSign: true }),
  },
  { id: 'income', label: 'Revenus', render: amount((l) => l.income, { withSign: true }) },
  { id: 'fees', label: 'Frais', render: amount((l) => l.fees, { withSign: true }) },
  { id: 'taxes', label: 'Taxes', render: amount((l) => l.taxes, { withSign: true }) },
  {
    id: 'modifiedDietz',
    label: 'Rendement (MD)',
    figure: 'modifiedDietz',
    render: (line, context) => rateOf(line.modifiedDietz, context),
  },
  {
    id: 'timeWeighted',
    label: 'TWR',
    figure: 'timeWeighted',
    render: (line, context) => rateOf(line.timeWeighted, context),
  },
  {
    id: 'moneyWeighted',
    label: 'MWR (mois)',
    figure: 'moneyWeighted',
    render: (line, context) => moneyWeighted(line.moneyWeighted, context),
  },
];
