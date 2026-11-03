import type { Transfer } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { formatAmount } from '@/lib/decimal';
import styles from './TransferUpdateForm.module.css';

/** The immutable legs of a transfer; a missing transfer shows blocks in the value slots. */
export function TransferLegs({ transfer }: { transfer: Transfer | null }) {
  const { t, i18n } = useTranslation();
  return (
    <dl className={styles.legs} data-span="full">
      {(['source', 'target', 'fee'] as const).map((leg) => (
        <div data-span={leg === 'fee' ? 'full' : undefined} key={leg}>
          <dt>{t(`transactions.workflow.legs.${leg}`)}</dt>
          <dd>
            {transfer === null ? (
              <SkeletonBlock />
            ) : transfer[leg] ? (
              formatAmount(
                transfer[leg].amount.value,
                transfer[leg].amount.assetCode,
                i18n.language,
              )
            ) : (
              t('transactions.workflow.unspecified')
            )}
          </dd>
        </div>
      ))}
    </dl>
  );
}
