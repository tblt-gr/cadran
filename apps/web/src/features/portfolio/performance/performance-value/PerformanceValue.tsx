import type {
  PortfolioGainFigure,
  PortfolioModifiedDietzFigure,
  PortfolioMoneyWeightedFigure,
  PortfolioTimeWeightedFigure,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import {
  summarizeReason,
  type ReasonSubject,
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
  if (text === '-') return { text, tone: '' };
  if (/^-0(?:[,.]0+)?(?![\d,.])/.test(text)) return { text: text.slice(1), tone: '' };
  if (text.startsWith('-')) return { text, tone: styles.negative };
  if (/^0(?:[,.]0+)?(?![\d,.])/.test(text)) return { text, tone: '' };
  return { text: `+${text}`, tone: styles.positive };
}

export function Rate({ ratio, unit }: { ratio: string; unit?: string }) {
  const { i18n } = useTranslation();
  const { text, tone } = signed(formatRatioPercentage(ratio, i18n.language));
  return (
    <span className={tone}>
      {text}
      {unit ? <span className={styles.unit}> {unit}</span> : null}
    </span>
  );
}

function MoneyWeighted({ figure }: { figure: PortfolioMoneyWeightedFigure }) {
  const { t } = useTranslation();
  const annualized = t('portfolio.performance.value.annualized');
  if (figure.shortPeriod && figure.periodRate !== null) {
    return (
      <>
        <Rate ratio={figure.periodRate} unit={t('portfolio.performance.value.overPeriod')} />
        {figure.annualizedRate !== null ? (
          <span className={styles.secondary}>
            <Rate ratio={figure.annualizedRate} unit={annualized} />
          </span>
        ) : null}
      </>
    );
  }
  return figure.annualizedRate === null ? null : (
    <Rate ratio={figure.annualizedRate} unit={annualized} />
  );
}

export function FigureValue({
  figure,
  assetCode,
}: {
  figure: PerformanceFigure;
  assetCode: string;
}) {
  const { i18n } = useTranslation();
  if ('annualizedRate' in figure) return <MoneyWeighted figure={figure} />;
  if (figure.value === null) return null;
  if ('decomposition' in figure) {
    const { text, tone } = signed(formatAmount(figure.value, assetCode, i18n.language));
    return <span className={tone}>{text}</span>;
  }
  return <Rate ratio={figure.value} />;
}

/**
 * One performance figure: the server value with its sign and the button that explains it.
 * The quality sits next to the value where asked. A missing figure is a greyed dash that still says why.
 */
export function PerformanceValue({
  figure,
  label,
  context,
  emptyLabel,
  onExplain,
  withQuality = false,
}: {
  /** `null` while loading: only the value becomes a block. */
  figure: PerformanceFigure | null;
  label: string;
  context: ReasonSubject;
  /** Defaults to "not calculable". */
  emptyLabel?: string;
  onExplain: (() => void) | null;
  /** The quality the API attached to the figure is shown next to the value. */
  withQuality?: boolean;
}) {
  const { t, i18n } = useTranslation();
  const reasonContext = { ...context, t, locale: i18n.language };
  const reason = figure?.reasons[0];
  return (
    <article className={styles.figure}>
      <div className={styles.heading}>
        <h3>{label}</h3>
        <InfoButton
          disabled={!onExplain || !figure}
          label={t('portfolio.performance.value.explain', { label })}
          onClick={onExplain ?? undefined}
        />
      </div>
      <div className={styles.value} data-testid="performance-value">
        {!figure ? (
          <SkeletonBlock className={styles.valueSkeleton} />
        ) : figure.value === null ? (
          <EmptyValue
            label={emptyLabel ?? t('portfolio.performance.value.notCalculable')}
            reason={reason ? summarizeReason(reason, reasonContext) : null}
          />
        ) : (
          <FigureValue assetCode={context.assetCode} figure={figure} />
        )}
      </div>
      {withQuality ? (
        <div className={styles.quality}>
          {figure ? (
            <QualityBadge quality={figure.quality} />
          ) : (
            <SkeletonBlock className={styles.qualitySkeleton} />
          )}
        </div>
      ) : null}
    </article>
  );
}
