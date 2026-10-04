import type {
  PortfolioGainFigure,
  PortfolioModifiedDietzFigure,
  PortfolioMoneyWeightedFigure,
  PortfolioTimeWeightedFigure,
} from '@cadran/api-client';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import {
  describeReason,
  type ReasonContext,
} from '@/features/portfolio/performance/performanceReasons';
import { QualityBadge } from '@/features/portfolio/performance/quality-badge/QualityBadge';
import styles from './PerformanceValue.module.css';

export type PerformanceFigure =
  | PortfolioGainFigure
  | PortfolioModifiedDietzFigure
  | PortfolioTimeWeightedFigure
  | PortfolioMoneyWeightedFigure;

/** Server text with an explicit sign; the sign is never left to the colour alone. */
export function signed(text: string): { text: string; tone: string } {
  if (text.startsWith('-')) return { text, tone: styles.negative };
  if (/^0(?:[,.]0+)?(?![\d,.])/.test(text)) return { text, tone: '' };
  return { text: `+${text}`, tone: styles.positive };
}

export function Rate({ ratio, unit }: { ratio: string; unit?: string }) {
  const { text, tone } = signed(formatRatioPercentage(ratio, 'fr'));
  return (
    <span className={tone}>
      {text}
      {unit ? <span className={styles.unit}> {unit}</span> : null}
    </span>
  );
}

function MoneyWeighted({ figure }: { figure: PortfolioMoneyWeightedFigure }) {
  if (figure.shortPeriod && figure.periodRate !== null) {
    return (
      <>
        <Rate ratio={figure.periodRate} unit="sur la période" />
        {figure.annualizedRate !== null ? (
          <span className={styles.secondary}>
            <Rate ratio={figure.annualizedRate} unit="annualisé" />
          </span>
        ) : null}
      </>
    );
  }
  return figure.annualizedRate === null ? null : (
    <Rate ratio={figure.annualizedRate} unit="annualisé" />
  );
}

function Figure({ figure, assetCode }: { figure: PerformanceFigure; assetCode: string }) {
  if ('annualizedRate' in figure) return <MoneyWeighted figure={figure} />;
  if (figure.value === null) return null;
  if ('decomposition' in figure) {
    const { text, tone } = signed(formatAmount(figure.value, assetCode, 'fr'));
    return <span className={tone}>{text}</span>;
  }
  return <Rate ratio={figure.value} />;
}

/**
 * One performance figure: the server value with its sign, the quality the server gave it and
 * the button that explains it. A missing figure is a greyed dash that still says why.
 */
export function PerformanceValue({
  figure,
  label,
  context,
  emptyLabel = 'Non calculable',
  onExplain,
}: {
  /** `null` while loading: only the value becomes a block. */
  figure: PerformanceFigure | null;
  label: string;
  context: ReasonContext;
  emptyLabel?: string;
  onExplain: (() => void) | null;
}) {
  const reason = figure?.reasons[0];
  return (
    <article className={styles.figure}>
      <div className={styles.heading}>
        <h3>{label}</h3>
        <InfoButton
          disabled={!onExplain || !figure}
          label={`Expliquer : ${label}`}
          onClick={onExplain ?? undefined}
        />
      </div>
      <div className={styles.value} data-testid="performance-value">
        {!figure ? (
          <SkeletonBlock className={styles.valueSkeleton} />
        ) : figure.value === null ? (
          <EmptyValue
            label={emptyLabel}
            reason={reason ? describeReason(reason, context).sentence : null}
          />
        ) : (
          <Figure assetCode={context.assetCode} figure={figure} />
        )}
      </div>
      {figure ? <QualityBadge quality={figure.quality} /> : null}
    </article>
  );
}
