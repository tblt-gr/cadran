import type { Instrument } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';

/** The same notice holds its queried name while the scoped instrument read is pending. */
export function ExistingInstrumentChoice({
  instrument,
  pending,
  failed,
  compatible,
  onUse,
  onRetry,
}: {
  instrument: Instrument | null;
  pending: boolean;
  failed: boolean;
  compatible: boolean;
  onUse: () => void;
  onRetry: () => void;
}) {
  const { t } = useTranslation();
  return (
    <>
      {pending && !instrument ? (
        <SkeletonStatus>{t('investments.instruments.existing.searching')}</SkeletonStatus>
      ) : null}
      <p>
        {t('investments.instruments.existing.exists')}{' '}
        <strong>
          {instrument?.name ??
            (failed ? t('investments.instruments.existing.unavailableName') : <SkeletonBlock />)}
        </strong>
        .
      </p>
      {failed ? (
        <p>
          {t('investments.instruments.existing.unavailable')}{' '}
          <button className="secondary-action" type="button" onClick={onRetry}>
            {t('actions.retry')}
          </button>
        </p>
      ) : null}
      {instrument && !compatible ? (
        <p>{t('investments.instruments.existing.incompatible')}</p>
      ) : null}
      <button
        className="primary-action"
        disabled={pending || !instrument || !compatible}
        type="button"
        onClick={onUse}
      >
        {instrument?.status === 'INACTIVE'
          ? t('investments.instruments.duplicate.reactivateAndUse')
          : t('investments.instruments.existing.useThis')}
      </button>
    </>
  );
}
