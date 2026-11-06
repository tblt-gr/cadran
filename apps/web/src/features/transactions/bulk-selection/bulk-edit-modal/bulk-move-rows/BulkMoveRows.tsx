import { useTranslation } from 'react-i18next';
import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import { BulkAccountField } from '@/features/transactions/bulk-selection/bulk-edit-modal/bulk-account-field/BulkAccountField';
import { BulkEditRow } from '@/features/transactions/bulk-selection/bulk-edit-modal/BulkEditRow';
import type {
  BulkEditDraft,
  BulkEditField,
} from '@/features/transactions/bulk-selection/bulk-edit-modal/bulkEditChanges';
import styles from '@/features/transactions/bulk-selection/bulk-edit-modal/BulkEditModal.module.css';

interface BulkMoveRowsProps {
  /** Distinct assets of the selected rows: a move of the account is per asset. */
  assets: readonly string[];
  draft: BulkEditDraft;
  onPatch: (next: Partial<BulkEditDraft>) => void;
  onTick: (field: BulkEditField, on: boolean) => void;
  today: string;
}

/** The two rows that move transactions: the target account and the booked day. */
export function BulkMoveRows({ assets, draft, onPatch, onTick, today }: BulkMoveRowsProps) {
  const { t } = useTranslation();
  return (
    <>
      <BulkEditRow
        checked={draft.checked.account}
        field="account"
        full
        lockedHint={assets.length > 1 ? t('transactions.bulk.move.mixedAssets') : null}
        onTick={(on) => onTick('account', on)}
      >
        {(disabled) => (
          <fieldset className={styles.control} disabled={disabled}>
            <BulkAccountField
              assetCode={assets.length === 1 ? (assets[0] ?? null) : null}
              disabled={disabled}
              enabled={draft.checked.account}
              onChange={(accountId) => onPatch({ accountId })}
              value={draft.accountId}
            />
          </fieldset>
        )}
      </BulkEditRow>
      <BulkEditRow
        checked={draft.checked.bookedOn}
        field="bookedOn"
        onTick={(on) => onTick('bookedOn', on)}
      >
        {(disabled) => (
          <fieldset className={styles.control} disabled={disabled}>
            <BusinessDateField
              compact
              hint={null}
              label={t('transactions.bulk.move.bookedOn')}
              onChange={(bookedOn) => onPatch({ bookedOn })}
              today={today}
              value={draft.bookedOn}
            />
          </fieldset>
        )}
      </BulkEditRow>
    </>
  );
}
