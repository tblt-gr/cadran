import { useTranslation } from 'react-i18next';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import type { BulkItem, BulkScope } from '@/features/transactions/bulk-selection/useBulkSelection';
import {
  MAX_LABEL_LENGTH,
  eachLabelAvailable,
  eachLabelValue,
  type BulkEditDraft,
  type LabelMode,
} from './bulkEditChanges';
import styles from './BulkEditModal.module.css';

/** Display labels listed under a shared label before the rest is summarised. */
const LISTED_LABELS = 10;

interface BulkLabelFieldProps {
  draft: BulkEditDraft;
  items: readonly BulkItem[];
  onChange: (patch: Partial<BulkEditDraft>) => void;
  scope: BulkScope;
}

/** The label row: one value for every transaction, or one value per transaction. */
export function BulkLabelField({ draft, items, onChange, scope }: BulkLabelFieldProps) {
  const { i18n, t } = useTranslation();
  const selected = scope.type === 'IDS' ? items.filter((item) => scope.ids.includes(item.id)) : [];
  const eachAvailable = eachLabelAvailable(scope);

  return (
    <div className={styles.labelField}>
      <SegmentedControl<LabelMode>
        label={t('transactions.bulk.label.mode')}
        onChange={(labelMode) => onChange({ labelMode })}
        options={[
          { value: 'SAME', label: t('transactions.bulk.label.same') },
          { value: 'EACH', label: t('transactions.bulk.label.each'), disabled: !eachAvailable },
        ]}
        value={draft.labelMode}
      />
      {eachAvailable ? null : (
        <p className={styles.note}>{t('transactions.bulk.label.eachLimit')}</p>
      )}
      {draft.labelMode === 'SAME' ? (
        <>
          <label>
            {t('transactions.fields.label')}
            <input
              maxLength={MAX_LABEL_LENGTH}
              onChange={(event) => onChange({ labelSame: event.target.value })}
              type="text"
              value={draft.labelSame}
            />
          </label>
          {selected.length > 0 ? (
            <ul aria-label={t('transactions.bulk.label.current')} className={styles.currentLabels}>
              {selected.slice(0, LISTED_LABELS).map((item) => (
                <li key={item.id}>{item.displayLabel}</li>
              ))}
              {selected.length > LISTED_LABELS ? (
                <li>
                  {t('transactions.bulk.label.others', { count: selected.length - LISTED_LABELS })}
                </li>
              ) : null}
            </ul>
          ) : null}
        </>
      ) : (
        <ul className={styles.eachList}>
          {selected.map((item) => (
            <li key={item.id}>
              <p className={styles.eachMeta}>
                <span>{formatCalendarNumericDay(item.bookedOn)}</span>
                <strong>
                  {formatAmount(item.amount.value, item.amount.assetCode, i18n.language)}
                </strong>
                <span>{item.rawLabel}</span>
              </p>
              <input
                aria-label={t('transactions.bulk.label.forRow', { label: item.rawLabel })}
                maxLength={MAX_LABEL_LENGTH}
                onChange={(event) =>
                  onChange({ labelEach: { ...draft.labelEach, [item.id]: event.target.value } })
                }
                type="text"
                value={eachLabelValue(draft, item, item.id)}
              />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
