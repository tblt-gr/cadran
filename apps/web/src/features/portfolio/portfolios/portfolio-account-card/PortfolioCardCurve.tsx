import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { useValueHistory } from '@/features/portfolio/portfolio-account/value-history-card/useValueHistory';
import { ValueHistoryChart } from '@/features/portfolio/portfolio-account/value-history-card/ValueHistoryChart';
import { historyFrom } from '@/features/portfolio/portfolio-account/value-history-card/valueHistoryRange';
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
  const history = useValueHistory(accountId, today, historyFrom(today, '12'));

  if (history.isPending) {
    return <SkeletonBlock className={styles.placeholder} />;
  }
  if (history.isError) {
    return <p className={styles.note}>Courbe indisponible.</p>;
  }
  if (history.data.points.length === 0) {
    return <p className={styles.note}>Aucune valorisation.</p>;
  }

  return <ValueHistoryChart assetCode={assetCode} locale="fr" points={history.data.points} />;
}
