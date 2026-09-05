import type { ProductRuleKind, RecordAccountRuleOverrideRequest } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { FormField } from '@/features/accounts/account-form/form-field/FormField';
import type { AccountErrorKind } from '@/features/accounts/accountError';
import { PeriodFields } from '@/features/product-models/period-fields/PeriodFields';
import {
  emptyPeriod,
  periodProblems,
  toRuleInput,
  type PeriodValues,
} from '@/features/product-models/period-fields/periodValues';
import styles from './AccountRuleOverrideForm.module.css';

/** The reason is bounded by the contract; the field says so before the API does. */
const MAX_REASON = 200;

interface AccountRuleOverrideFormProps {
  accountAsset: string;
  initialKind: ProductRuleKind;
  kinds: ProductRuleKind[];
  onCancel: () => void;
  onSubmit: (body: RecordAccountRuleOverrideRequest) => void;
  pending: boolean;
  submitError: AccountErrorKind | null;
}

/**
 * Recording what this account claims in front of what it inherits.
 *
 * The dated period is described exactly as a product model describes one, so a
 * ceiling, a rate scale and a contractual term are entered the same way
 * wherever they are stated. What is specific here is the reason: an
 * unexplained local figure sitting beside a published one is the drift the
 * whole feature exists to make visible, so it is required rather than
 * optional.
 *
 * The unit is the account's own and is not offered as a choice: a local
 * ceiling is checked against this account and nothing else, and no conversion
 * ships with the application.
 */
export function AccountRuleOverrideForm({
  accountAsset,
  initialKind,
  kinds,
  onCancel,
  onSubmit,
  pending,
  submitError,
}: AccountRuleOverrideFormProps) {
  const { t } = useTranslation();
  const [period, setPeriod] = useState<PeriodValues>(() => ({
    ...emptyPeriod(initialKind),
    amountAssetCode: accountAsset,
  }));
  const [reason, setReason] = useState('');
  const [showErrors, setShowErrors] = useState(false);

  const trimmedReason = reason.trim();
  const reasonInvalid = trimmedReason === '' || trimmedReason.length > MAX_REASON;
  const problems = periodProblems(period);

  function submit(event: FormEvent) {
    event.preventDefault();
    if (problems.length > 0 || reasonInvalid) {
      setShowErrors(true);

      return;
    }

    onSubmit({ ...toRuleInput(period), reason: trimmedReason });
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accounts.overrides.errors.${submitError}`)}
        </p>
      ) : null}

      <p className={styles.hint}>{t('accounts.overrides.formHint')}</p>

      <PeriodFields
        capabilities={[]}
        kinds={kinds}
        onChange={(next) => setPeriod({ ...next, amountAssetCode: accountAsset })}
        showErrors={showErrors}
        value={period}
        yieldKind="CONTRACTUAL_FIXED"
      />

      <FormField
        error={showErrors && reasonInvalid ? t('accounts.overrides.errors.reason') : undefined}
        hint={t('accounts.overrides.reasonHint')}
        label={t('accounts.overrides.reason')}
        name="override-reason"
      >
        {({ fieldId, describedBy }) => (
          <textarea
            aria-describedby={describedBy}
            aria-invalid={showErrors && reasonInvalid ? true : undefined}
            id={fieldId}
            maxLength={MAX_REASON}
            onChange={(event) => setReason(event.target.value)}
            rows={3}
            value={reason}
          />
        )}
      </FormField>

      <div className={styles.actions}>
        <button className="secondary-action" onClick={onCancel} type="button">
          {t('accounts.form.cancel')}
        </button>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'accounts.overrides.recording' : 'accounts.overrides.record')}
        </button>
      </div>
    </form>
  );
}
