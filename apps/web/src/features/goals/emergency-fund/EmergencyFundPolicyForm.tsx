import type { EmergencyFundPolicy, UpdateEmergencyFundPolicyRequest } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import type { GoalErrorKind } from '@/features/goals/goalError';
import {
  MAX_ESSENTIAL_CATEGORIES,
  draftFromPolicy,
  policyRequest,
  policyValidity,
  type PolicyDraft,
} from './emergencyFundPolicyFields';
import styles from './EmergencyFund.module.css';

interface Props {
  policy: EmergencyFundPolicy;
  onSubmit: (body: UpdateEmergencyFundPolicyRequest) => void;
  pending: boolean;
  submitError: GoalErrorKind | null;
}

function policyErrorKey(
  kind: GoalErrorKind,
): 'conflict' | 'invalid' | 'invalid_emergency_fund_category' {
  return kind === 'conflict' || kind === 'invalid_emergency_fund_category' ? kind : 'invalid';
}

/** The policy fields; the modal around it owns the title, the loading state and the save. */
export function EmergencyFundPolicyForm({ policy, onSubmit, pending, submitError }: Props) {
  const { t } = useTranslation();
  const [draft, setDraft] = useState<PolicyDraft>(() => draftFromPolicy(policy));
  const [showErrors, setShowErrors] = useState(false);
  const [pickerKey, setPickerKey] = useState(0);
  const validity = policyValidity(draft);
  const flag = (valid: boolean) => (showErrors && !valid ? true : undefined);
  const patch = (changes: Partial<PolicyDraft>) =>
    setDraft((current) => ({ ...current, ...changes }));

  const addCategory = (id: string, category: { label: string } | null) => {
    if (!id || draft.categories.some((entry) => entry.id === id)) return;
    patch({
      categories: [...draft.categories, { id, label: category?.label ?? id, archived: false }],
    });
    setPickerKey((key) => key + 1);
  };

  const submit = (event: FormEvent) => {
    event.preventDefault();
    if (!validity.categories || !validity.trailingMonths || !validity.targetMonths) {
      setShowErrors(true);
      return;
    }
    onSubmit(policyRequest(draft, policy.version));
  };

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`goals.emergencyFund.errors.${policyErrorKey(submitError)}`)}
        </p>
      ) : null}
      <fieldset className={styles.radios}>
        <legend>{t('goals.emergencyFund.essentialMode')}</legend>
        {(['AXIS', 'CATEGORIES'] as const).map((mode) => (
          <label key={mode}>
            <input
              checked={draft.essentialMode === mode}
              data-autofocus={mode === 'AXIS' ? true : undefined}
              name="essentialMode"
              onChange={() => patch({ essentialMode: mode })}
              type="radio"
            />
            <span>{t(`goals.emergencyFund.essentialModes.${mode}`)}</span>
          </label>
        ))}
      </fieldset>
      {draft.essentialMode === 'CATEGORIES' ? (
        <div className={styles.categories}>
          {draft.categories.length > 0 ? (
            <ul aria-label={t('goals.emergencyFund.chosenCategories')}>
              {draft.categories.map((category) => (
                <li key={category.id}>
                  <span>{category.label}</span>
                  <button
                    aria-label={t('goals.emergencyFund.removeCategory', { label: category.label })}
                    className="secondary-action"
                    onClick={() =>
                      patch({
                        categories: draft.categories.filter((entry) => entry.id !== category.id),
                      })
                    }
                    type="button"
                  >
                    {t('goals.emergencyFund.remove')}
                  </button>
                </li>
              ))}
            </ul>
          ) : null}
          {draft.categories.length < MAX_ESSENTIAL_CATEGORIES ? (
            <CategoryPicker
              error={
                showErrors && !validity.categories
                  ? t('goals.emergencyFund.validation.categories')
                  : null
              }
              isEligible={(category) =>
                category.budgetIncluded &&
                !draft.categories.some((entry) => entry.id === category.id)
              }
              key={pickerKey}
              label={t('goals.emergencyFund.addCategory')}
              onChange={addCategory}
              type="EXPENSE"
              value=""
            />
          ) : (
            <p className={styles.hint}>{t('goals.emergencyFund.categoryLimit')}</p>
          )}
          <p className={styles.hint}>{t('goals.emergencyFund.descendantsHint')}</p>
        </div>
      ) : null}
      <fieldset className={styles.radios}>
        <legend>{t('goals.emergencyFund.referenceMode')}</legend>
        {(['PREVIOUS_YEAR', 'TRAILING_MONTHS'] as const).map((mode) => (
          <label key={mode}>
            <input
              checked={draft.referenceMode === mode}
              name="referenceMode"
              onChange={() => patch({ referenceMode: mode })}
              type="radio"
            />
            <span>{t(`goals.emergencyFund.referenceModes.${mode}`)}</span>
          </label>
        ))}
      </fieldset>
      {draft.referenceMode === 'TRAILING_MONTHS' ? (
        <label>
          <span>{t('goals.emergencyFund.trailingMonths')}</span>
          <input
            aria-invalid={flag(validity.trailingMonths)}
            inputMode="numeric"
            onChange={(event) => patch({ trailingMonths: event.target.value })}
            value={draft.trailingMonths}
          />
          {showErrors && !validity.trailingMonths ? (
            <small className={styles.fieldError}>
              {t('goals.emergencyFund.validation.trailingMonths')}
            </small>
          ) : null}
        </label>
      ) : null}
      <label>
        <span>{t('goals.emergencyFund.targetMonths')}</span>
        <input
          aria-invalid={flag(validity.targetMonths)}
          inputMode="decimal"
          onChange={(event) => patch({ targetMonths: event.target.value })}
          value={draft.targetMonths}
        />
        {showErrors && !validity.targetMonths ? (
          <small className={styles.fieldError}>
            {t('goals.emergencyFund.validation.targetMonths')}
          </small>
        ) : null}
      </label>
      <button className="primary-action" disabled={pending} type="submit">
        {t('goals.emergencyFund.save')}
      </button>
    </form>
  );
}
