import type { PortfolioGainFigure } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

type Quality = PortfolioGainFigure['quality'];

const tones = {
  EXACT: 'positive',
  APPROXIMATION: 'neutral',
  DEGRADED: 'warning',
  NOT_CALCULABLE: 'neutral',
} as const;

/** The quality the API attached to a figure, in words; the browser never upgrades it. */
export function QualityBadge({ quality }: { quality: Quality }) {
  const { t } = useTranslation();
  return (
    <StatusBadge tone={tones[quality]}>
      {t(`portfolio.performance.qualityBadge.${quality}`)}
    </StatusBadge>
  );
}
