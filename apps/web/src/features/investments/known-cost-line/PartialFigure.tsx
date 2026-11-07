import type { HoldingsKnownCostFigures } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { holdingsMoney } from '@/features/investments/holdingsFormat';
import { ExplainInfo } from '@/features/investments/explain-info/ExplainInfo';
import styles from './PartialFigure.module.css';

/**
 * The "i" that explains a figure computed on the positions whose cost is known only, and what was
 * left out, in the shared modal. `corner` pins it to the top-right of the nearest positioned cell.
 */
export function PartialFigure({
  figures,
  assetCode,
  placement,
  figureLabel,
}: {
  figures: HoldingsKnownCostFigures;
  assetCode: string | null;
  placement: 'corner' | 'inline';
  /** What the figure is, so each button has its own accessible name and modal title. */
  figureLabel: string;
}) {
  const { t } = useTranslation();
  const explanation =
    figures.excludedValue === null
      ? t('investments.partial.explanation', { count: figures.excludedCount })
      : t('investments.partial.explanationWithValue', {
          count: figures.excludedCount,
          value: holdingsMoney(figures.excludedValue, assetCode),
        });

  return (
    <ExplainInfo
      buttonLabel={t('investments.partial.explain', { label: figureLabel })}
      className={placement === 'corner' ? styles.corner : undefined}
      text={explanation}
      title={figureLabel}
    />
  );
}
