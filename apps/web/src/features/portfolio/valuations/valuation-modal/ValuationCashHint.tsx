import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import styles from './ValuationForm.module.css';

/** The fallback explanation stays readable even before its computed cash amount arrives. */
export function ValuationCashHint({ cash }: { cash?: { value: string; assetCode: string } }) {
  const { i18n } = useTranslation();
  return (
    <>
      Laissez vide pour retenir les espèces calculées :{' '}
      {cash ? (
        formatAmount(cash.value, cash.assetCode, i18n.language)
      ) : (
        <SkeletonBlock className={styles.skeletonHint} />
      )}
    </>
  );
}
