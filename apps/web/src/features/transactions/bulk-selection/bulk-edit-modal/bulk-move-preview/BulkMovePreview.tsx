import type { BulkEditPreview, BulkMoveImpact } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import type { BulkSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { formatAmount, formatCalendarDay, isZeroDecimal } from '@/lib/decimal';
import type { BulkRequestError } from '@/features/transactions/bulk-selection/bulk-edit-modal/useBulkEdit';
import { BulkSkippedList } from '@/features/transactions/bulk-selection/bulk-edit-modal/BulkSkippedList';
import styles from './BulkMovePreview.module.css';

interface BulkMovePreviewProps {
  error: BulkRequestError | null;
  /** Why the preview was refreshed, when it was. */
  notice: string | null;
  onBack: () => void;
  onConfirm: () => void;
  pageItems: BulkSelection['pageItems'];
  /** The confirmation is in flight. */
  pending: boolean;
  /** `null` while the impact is being computed: the screen keeps its final layout. */
  preview: BulkEditPreview | null;
}

/** The plain zero `formatAmount` abbreviates to a hyphen: a balance impact reads `0,00 €`. */
function zeroAmount(assetCode: string, language: string): string {
  try {
    return new Intl.NumberFormat(language, { style: 'currency', currency: assetCode }).format(0);
  } catch {
    return `0 ${assetCode}`;
  }
}

/**
 * The server gives a signed decimal; a gain gets an explicit plus so it is not read as unsigned,
 * and zero carries no sign. The sign is read from the canonical string, never a number.
 */
function signed(impact: BulkMoveImpact, language: string): string {
  if (isZeroDecimal(impact.delta)) return zeroAmount(impact.assetCode, language);
  const text = formatAmount(impact.delta, impact.assetCode, language);
  return impact.delta.startsWith('-') ? text : `+${text}`;
}

/**
 * What the move would do, before anything is written: how many transactions move, the effect on
 * each balance, and the ones left out with their reason.
 */
export function BulkMovePreview({
  error,
  notice,
  onBack,
  onConfirm,
  pageItems,
  pending,
  preview,
}: BulkMovePreviewProps) {
  const { i18n, t } = useTranslation();
  const day = (value: string) => formatCalendarDay(value, i18n.language);

  function impactLine(impact: BulkMoveImpact): string {
    if (impact.to !== null) {
      return t('transactions.bulk.move.impactDates', {
        label: impact.label,
        from: day(impact.from),
        to: day(impact.to),
      });
    }
    return t('transactions.bulk.move.impact', {
      label: impact.label,
      delta: signed(impact, i18n.language),
      from: day(impact.from),
    });
  }

  return (
    <div className={styles.preview}>
      {preview === null ? (
        <SkeletonStatus>{t('transactions.bulk.move.loading')}</SkeletonStatus>
      ) : null}
      {notice ? <p role="status">{notice}</p> : null}
      <p className={styles.summary}>
        {preview === null ? (
          <SkeletonBlock className={styles.summaryBlock} />
        ) : (
          t('transactions.bulk.move.eligible', { count: preview.eligibleCount })
        )}
      </p>
      <ul aria-label={t('transactions.bulk.move.impactsLabel')} className={styles.impacts}>
        {preview === null ? (
          <li>
            <SkeletonBlock className={styles.impactBlock} />
          </li>
        ) : (
          preview.impacts.map((impact) => <li key={impact.accountId}>{impactLine(impact)}</li>)
        )}
      </ul>
      {preview ? <BulkSkippedList pageItems={pageItems} skipped={preview.skipped} /> : null}
      {error ? (
        <p className={styles.error} role="alert">
          {t(`transactions.bulk.errors.${error.kind}`, { count: error.actualCount ?? undefined })}
        </p>
      ) : null}
      <ModalActions>
        <button className="secondary-action" disabled={pending} onClick={onBack} type="button">
          {t('transactions.bulk.move.back')}
        </button>
        <button
          className="primary-action"
          disabled={preview === null || preview.eligibleCount === 0 || pending}
          onClick={onConfirm}
          type="button"
        >
          {t(pending ? 'transactions.bulk.applying' : 'transactions.bulk.move.confirm')}
        </button>
      </ModalActions>
    </div>
  );
}
