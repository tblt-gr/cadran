import type { NetWorthDelta as Delta } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { NetWorthDelta } from '@/features/dashboard/net-worth-delta/NetWorthDelta';
import deltaStyles from '@/features/dashboard/net-worth-delta/NetWorthDelta.module.css';
import styles from './PeriodNetWorthDelta.module.css';

interface Props {
  delta: Delta | null;
  pending: boolean;
  fetching: boolean;
}

/** The history owns both its series and comparison, including their shared refresh state. */
export function PeriodNetWorthDelta({ delta, pending, fetching }: Props) {
  const { t } = useTranslation();
  return (
    <div aria-busy={fetching}>
      {delta !== null ? (
        <NetWorthDelta delta={delta} />
      ) : (
        <p className={deltaStyles.delta} role={pending ? undefined : 'status'}>
          {pending ? (
            <SkeletonBlock className={styles.placeholder} />
          ) : (
            t('dashboard.chart.deltaUnavailable')
          )}
        </p>
      )}
    </div>
  );
}
