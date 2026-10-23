import { CurveChartSkeleton } from '@/components/ui/charts/curve-chart/CurveChartSkeleton';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { useValueHistory } from '@/features/portfolio/portfolio-account/value-history-card/useValueHistory';
import { ValueHistoryChart } from '@/features/portfolio/portfolio-account/value-history-card/ValueHistoryChart';
import { chartPeriodFrom } from '@/lib/chartPeriodRange';
import { workspaceToday } from '@/lib/workspaceTime';
import styles from './PortfolioCardCurve.module.css';

/** The last twelve months of valuations of one account, as the shared curve. */
export function PortfolioCardCurve({
  accountId,
  assetCode,
}: {
  accountId: string;
  assetCode: string;
}) {
  const today = workspaceToday(new Date(), useWorkspaceTimeZone());
  const history = useValueHistory(accountId, today, chartPeriodFrom('LAST_12_MONTHS', today));

  if (history.isPending) {
    return <CurveChartSkeleton />;
  }
  if (history.isError) {
    return <p className={styles.note}>Courbe indisponible.</p>;
  }
  if (history.data.points.length === 0) {
    return <p className={styles.note}>Aucune valorisation.</p>;
  }

  return (
    <ValueHistoryChart
      amountFormat="display"
      assetCode={assetCode}
      locale="fr"
      points={history.data.points}
    />
  );
}
