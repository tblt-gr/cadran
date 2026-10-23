import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarMonth } from '@/lib/decimal';
import styles from './AccountRecap.module.css';

/** The month a balance column belongs to; only the month itself waits for its date. */
export function AccountBalanceHeading({ date }: { date: string | null }) {
  const { i18n } = useTranslation();
  return date === null ? (
    <SkeletonBlock className={styles.skeletonDate} />
  ) : (
    formatCalendarMonth(date, i18n.language)
  );
}
