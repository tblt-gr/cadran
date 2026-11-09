import type { BulkGoalRequest, BulkGoalResult, GoalSummary } from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { AmountInput } from '@/features/goals/AmountInput';
import { bulkChanges, bulkCompatibility } from './bulkGoalFields';
import { BulkGoalPreview } from './BulkGoalPreview';
import styles from './BulkGoal.module.css';

export function BulkGoalForm({
  goals,
  month,
  busy,
  preview,
  previewPending,
  error,
  onChange,
  onPreview,
  onSave,
  close,
}: {
  goals: GoalSummary[];
  month: string;
  busy: boolean;
  preview: BulkGoalResult | null;
  previewPending: boolean;
  error: string | null;
  onChange: () => void;
  onPreview: (body: BulkGoalRequest) => void;
  onSave: () => void;
  close: () => void;
}) {
  const { t } = useTranslation();
  const reasonHintId = useId();
  const [effectiveFrom, setMonth] = useState(month);
  const [reason, setReason] = useState('');
  const [changeTarget, setChangeTarget] = useState(false);
  const [changeTolerance, setChangeTolerance] = useState(false);
  const [target, setTarget] = useState('');
  const [tolerance, setTolerance] = useState('');
  const compatibility = bulkCompatibility(goals);
  const changes = bulkChanges(
    changeTarget,
    target,
    changeTolerance,
    tolerance,
    compatibility.valueType,
  );
  const retroactive = effectiveFrom < month;
  const valid =
    compatibility.compatible &&
    (!changeTarget || compatibility.targetAllowed) &&
    changes !== null &&
    /^\d{4}-(0[1-9]|1[0-2])$/.test(effectiveFrom) &&
    (!retroactive || (reason.trim().length >= 3 && reason.trim().length <= 200));
  const amount = compatibility.valueType === 'AMOUNT';
  const update = (action: () => void) => {
    action();
    onChange();
  };
  return (
    <form
      className={styles.form}
      noValidate
      onSubmit={(event) => {
        event.preventDefault();
        if (valid && !busy)
          onPreview({
            items: goals.map((goal) => ({
              goalId: goal.id,
              expectedVersion: goal.version,
              expectedValueType: goal.currentVersion.valueType,
              expectedAssetCode: goal.assetCode,
            })),
            effectiveFrom,
            changes: changes!,
            ...(retroactive ? { reason: reason.trim() } : {}),
          });
      }}
    >
      <p>{t('goals.bulk.scopeExplanation')}</p>
      <ul>
        {goals.map((goal) => (
          <li key={goal.id}>{goal.label}</li>
        ))}
      </ul>
      {!compatibility.compatible ? <p role="alert">{t('goals.bulk.incompatible')}</p> : null}
      {!compatibility.targetAllowed && compatibility.compatible ? (
        <p>{t('goals.bulk.customSchedule')}</p>
      ) : null}
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
      <fieldset disabled={busy}>
        <FormGrid>
          <label>
            <span>{t('goals.bulk.effectiveFrom')}</span>
            <input
              data-autofocus
              type="month"
              value={effectiveFrom}
              onChange={(event) => update(() => setMonth(event.target.value))}
            />
          </label>
          {retroactive ? (
            <div className={styles.field}>
              <label>
                <span>{t('goals.bulk.reason')}</span>
                <textarea
                  aria-describedby={reasonHintId}
                  maxLength={200}
                  minLength={3}
                  value={reason}
                  onChange={(event) => update(() => setReason(event.target.value))}
                />
              </label>
              <small id={reasonHintId}>{t('goals.bulk.reasonHint')}</small>
            </div>
          ) : null}
          <div className={styles.field}>
            <label className={styles.check}>
              <input
                type="checkbox"
                checked={changeTarget}
                disabled={!compatibility.targetAllowed}
                onChange={(event) => update(() => setChangeTarget(event.target.checked))}
              />
              {t('goals.bulk.changeTarget')}
            </label>
            <label>
              <span>
                {t('goals.bulk.target')} ({amount ? compatibility.assetCode : '%'})
              </span>
              <AmountInput
                disabled={!changeTarget || !compatibility.targetAllowed}
                invalid={changeTarget && changes === null}
                value={target}
                onChange={(value) => update(() => setTarget(value))}
              />
            </label>
          </div>
          <div className={styles.field}>
            <label className={styles.check}>
              <input
                type="checkbox"
                checked={changeTolerance}
                disabled={!compatibility.compatible}
                onChange={(event) => update(() => setChangeTolerance(event.target.checked))}
              />
              {t('goals.bulk.changeTolerance')}
            </label>
            <label>
              <span>
                {t('goals.tolerance')} (
                {amount ? compatibility.assetCode : t('goals.tolerancePoints')})
              </span>
              <AmountInput
                disabled={!changeTolerance || !compatibility.compatible}
                invalid={changeTolerance && changes === null}
                value={tolerance}
                onChange={(value) => update(() => setTolerance(value))}
              />
            </label>
            <small>{t('goals.bulk.toleranceHint')}</small>
          </div>
        </FormGrid>
      </fieldset>
      {preview || previewPending ? (
        <BulkGoalPreview goals={goals} pending={previewPending} result={preview} />
      ) : null}
      <ModalActions>
        <button className="secondary-action" disabled={busy} onClick={close} type="button">
          {t('goals.bulk.cancel')}
        </button>
        <button className="secondary-action" disabled={!valid || busy} type="submit">
          {t('goals.bulk.preview')}
        </button>
        <button
          className="primary-action"
          disabled={busy || preview?.canApply !== true}
          onClick={onSave}
          type="button"
        >
          {t('goals.bulk.apply')}
        </button>
      </ModalActions>
    </form>
  );
}
