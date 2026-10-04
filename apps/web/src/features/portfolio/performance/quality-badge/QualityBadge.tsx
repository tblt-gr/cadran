import type { PortfolioGainFigure } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';

type Quality = PortfolioGainFigure['quality'];

const badges = {
  EXACT: { label: 'Exact', tone: 'positive' },
  APPROXIMATION: { label: 'Approximation', tone: 'neutral' },
  DEGRADED: { label: 'Données à vérifier', tone: 'warning' },
  NOT_CALCULABLE: { label: 'Non calculable', tone: 'neutral' },
} as const;

/** The quality the API attached to a figure, in words; the browser never upgrades it. */
export function QualityBadge({ quality }: { quality: Quality }) {
  const { label, tone } = badges[quality];
  return <StatusBadge tone={tone}>{label}</StatusBadge>;
}
