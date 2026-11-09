import type { GoalEvaluation } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { Modal } from '@/components/ui/modal/Modal';
import { formatEvaluationFigure } from '@/features/goals/goalFormat';
import {
  formulaText,
  gapText,
  toleranceText,
} from '@/features/goals/goal-panel/goalEvaluationText';
import styles from './GoalExplanation.module.css';

interface Props {
  evaluation: GoalEvaluation | null;
  replaced: { id: string; label: string }[];
}

/** The same explanation entry point for every goal; all figures come from its evaluation. */
export function GoalExplanation({ evaluation, replaced }: Props) {
  const { t, i18n } = useTranslation();
  const [open, setOpen] = useState(false);
  const stock = evaluation?.metric === 'NET_WORTH' || evaluation?.metric === 'ACCOUNT_BALANCE';
  const details = evaluation
    ? [
        stock && evaluation.periodicity === 'YEARLY'
          ? t('goals.panel.start', {
              start:
                formatEvaluationFigure(evaluation.start ?? null, i18n.language) ??
                t('goals.noValue'),
            })
          : null,
        gapText(evaluation, t, i18n.language),
        toleranceText(evaluation, t, i18n.language),
        formulaText(evaluation, t, i18n.language),
        t('goals.panel.version', { versionNo: evaluation.versionNo }),
      ].filter(Boolean)
    : [];
  return (
    <>
      <InfoButton
        disabled={evaluation === null}
        label={t('goals.panel.explain', { label: evaluation?.label ?? '' })}
        onClick={() => setOpen(true)}
      />
      {open && evaluation ? (
        <Modal
          intent="detail"
          close={() => setOpen(false)}
          title={t('goals.panel.details', { label: evaluation.label })}
        >
          <div className={styles.details}>
            {details.map((text, index) => (
              <p key={index}>{text}</p>
            ))}
            {evaluation.explanationUrl ? (
              <a href={evaluation.explanationUrl}>{t('goals.stockExplanation')}</a>
            ) : null}
            {replaced.length > 0 ? (
              <ul>
                {replaced.map((hidden) => (
                  <li key={hidden.id}>
                    {t(
                      evaluation.periodicity === 'YEARLY'
                        ? 'goals.panel.replacedYear'
                        : 'goals.panel.replaced',
                      { hidden: hidden.label, label: evaluation.label },
                    )}
                  </li>
                ))}
              </ul>
            ) : null}
          </div>
        </Modal>
      ) : null}
    </>
  );
}
