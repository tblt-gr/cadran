import type { CategorizationApplyReport, CategorizationPreview } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { todayInBrowser } from '@/lib/businessDay';
import type { CategorizationRuleErrorKind } from '../categorizationRuleError';
import styles from './RulePreviewDialog.module.css';

interface RulePreviewDialogProps {
  applyError: CategorizationRuleErrorKind | null;
  applyPending: boolean;
  onApply: (token: string) => Promise<CategorizationApplyReport>;
  onPreview: (from: string, to: string) => void;
  preview: CategorizationPreview | null;
  previewError: CategorizationRuleErrorKind | null;
  previewPending: boolean;
  ruleLabel?: string;
}

export function RulePreviewDialog({
  applyError,
  applyPending,
  onApply,
  onPreview,
  preview,
  previewError,
  previewPending,
  ruleLabel,
}: RulePreviewDialogProps) {
  const { t } = useTranslation();
  const today = todayInBrowser();
  const [from, setFrom] = useState(`${today.slice(0, 4)}-01-01`);
  const [to, setTo] = useState(today);
  const [invalid, setInvalid] = useState(false);
  const [applied, setApplied] = useState<CategorizationApplyReport | null>(null);

  function requestPreview() {
    if (from === '' || to === '' || to < from) {
      setInvalid(true);
      return;
    }
    setInvalid(false);
    setApplied(null);
    onPreview(from, to);
  }

  return (
    <div className={styles.dialog}>
      <p>
        {t('categorizationRules.preview.introduction', {
          label: ruleLabel ?? t('categorizationRules.preview.allRules'),
        })}
      </p>
      <p className={styles.notice}>{t('categorizationRules.preview.noChangeYet')}</p>
      <div className={styles.dates}>
        <label>
          <span>{t('categorizationRules.preview.from')}</span>
          <input
            aria-invalid={invalid ? true : undefined}
            onChange={(event) => setFrom(event.target.value)}
            type="date"
            value={from}
          />
        </label>
        <label>
          <span>{t('categorizationRules.preview.to')}</span>
          <input
            aria-invalid={invalid ? true : undefined}
            onChange={(event) => setTo(event.target.value)}
            type="date"
            value={to}
          />
        </label>
      </div>
      {invalid ? (
        <p className={styles.alert} role="alert">
          {t('categorizationRules.validation.previewPeriod')}
        </p>
      ) : null}
      {previewError ? (
        <p className={styles.alert} role="alert">
          {t(`categorizationRules.errors.${previewError}`)}
        </p>
      ) : null}
      {applyError ? (
        <p className={styles.alert} role="alert">
          {t(`categorizationRules.errors.${applyError}`)}
        </p>
      ) : null}
      <button
        className="secondary-action"
        disabled={previewPending}
        onClick={requestPreview}
        type="button"
      >
        {t(
          previewPending
            ? 'categorizationRules.preview.loading'
            : 'categorizationRules.preview.action',
        )}
      </button>
      {preview ? (
        <>
          <dl className={styles.metrics}>
            <div>
              <dt>{t('categorizationRules.preview.matched')}</dt>
              <dd>{preview.matched}</dd>
            </div>
            <div>
              <dt>{t('categorizationRules.preview.wouldChange')}</dt>
              <dd>{preview.wouldChange}</dd>
            </div>
            <div>
              <dt>{t('categorizationRules.preview.skippedManual')}</dt>
              <dd>{preview.skippedManual}</dd>
            </div>
            <div>
              <dt>{t('categorizationRules.preview.conflicts')}</dt>
              <dd>{preview.conflicts.length}</dd>
            </div>
          </dl>
          {preview.samples.length === 0 ? (
            <p>{t('categorizationRules.preview.zeroMatch')}</p>
          ) : (
            <ul className={styles.samples}>
              {preview.samples.map((sample) => (
                <li key={sample.transactionId}>
                  {sample.bookedOn} ·{' '}
                  {sample.currentCategoryId ?? t('categorizationRules.preview.noCategory')} →{' '}
                  {sample.targetCategoryId}
                </li>
              ))}
            </ul>
          )}
          {preview.deactivatedRuleIds.length > 0 ? (
            <p className={styles.alert} role="alert">
              {t('categorizationRules.preview.deactivated')}
            </p>
          ) : null}
          {applied ? (
            <p className={styles.notice} role="status">
              {t('categorizationRules.preview.applied', { count: applied.changed })}
            </p>
          ) : (
            <div className={styles.actions}>
              <button
                className="primary-action"
                disabled={applyPending}
                onClick={() =>
                  void onApply(preview.previewToken)
                    .then(setApplied)
                    .catch(() => undefined)
                }
                type="button"
              >
                {t(
                  applyPending
                    ? 'categorizationRules.preview.applying'
                    : 'categorizationRules.preview.apply',
                )}
              </button>
            </div>
          )}
        </>
      ) : null}
    </div>
  );
}
