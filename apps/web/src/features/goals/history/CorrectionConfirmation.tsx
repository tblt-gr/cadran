import type { GoalCorrectionPreview } from '@cadran/api-client';
import { useEffect, useRef } from 'react';
import { useTranslation } from 'react-i18next';
import type { GoalErrorKind } from '@/features/goals/goalError';
import styles from '@/features/goals/GoalsPage.module.css';
import { CorrectionImpactTable } from './CorrectionImpactTable';

/** Confirmation step of a retroactive correction: the impact preview and an explicit approval. */
export function CorrectionConfirmation({
  assetCode,
  confirmed,
  error,
  onBack,
  onConfirm,
  onConfirmedChange,
  pending,
  preview,
}: {
  assetCode: string;
  confirmed: boolean;
  error: GoalErrorKind | null;
  onBack: () => void;
  onConfirm: () => void;
  onConfirmedChange: (confirmed: boolean) => void;
  pending: boolean;
  preview: GoalCorrectionPreview;
}) {
  const { t } = useTranslation();
  const heading = useRef<HTMLHeadingElement>(null);
  // The form this step replaces held the focus: announce the new step instead of dropping it on <body>.
  useEffect(() => heading.current?.focus(), []);
  return (
    <section
      className={styles.correctionConfirmation}
      aria-labelledby="goal-correction-confirmation"
    >
      <h3 id="goal-correction-confirmation" ref={heading} tabIndex={-1}>
        {t('goals.correctionConfirmationTitle')}
      </h3>
      <p>{t('goals.correctionConfirmationDescription')}</p>
      <CorrectionImpactTable assetCode={assetCode} preview={preview} />
      {error ? (
        <p className={styles.alert} role="alert">
          {t(`goals.errors.${error}`)}
        </p>
      ) : null}
      <label className={styles.confirmCheck}>
        <input
          checked={confirmed}
          onChange={(event) => onConfirmedChange(event.target.checked)}
          type="checkbox"
        />
        <span>{t('goals.correctionConfirmation')}</span>
      </label>
      <div className={styles.formActions}>
        <button className="secondary-action" onClick={onBack} type="button">
          {t('goals.correctionBack')}
        </button>
        <button
          className="primary-action"
          disabled={!confirmed || pending || error === 'conflict'}
          onClick={onConfirm}
          type="button"
        >
          {t(pending ? 'goals.saving' : 'goals.correctionConfirm')}
        </button>
      </div>
    </section>
  );
}
