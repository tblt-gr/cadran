import type { AccountKind, CreateMetricPolicyRequest } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import type { MetricPolicyErrorKind } from '@/features/metric-policy/metricPolicyError';
import styles from './CreateMetricPolicyForm.module.css';

const ACCOUNT_KIND_ORDER = {
  CURRENT: 0,
  SAVINGS: 1,
  PORTFOLIO: 2,
  INSURANCE_CONTRACT: 3,
  EMPLOYEE_BENEFIT: 4,
  CASH: 5,
  REAL_ASSET: 6,
  LIABILITY: 7,
} as const satisfies Record<AccountKind, number>;
// Typed as Record<AccountKind, number>, so a new kind fails compilation here.
const ACCOUNT_KINDS = (Object.keys(ACCOUNT_KIND_ORDER) as AccountKind[]).sort(
  (a, b) => ACCOUNT_KIND_ORDER[a] - ACCOUNT_KIND_ORDER[b],
);
// Mirrors the API bound; the server stays the authority.
const MAX_LABEL_LENGTH = 80;

interface CreateMetricPolicyFormProps {
  onCancel: () => void;
  onSubmit: (body: CreateMetricPolicyRequest) => void;
  pending: boolean;
  submitError: MetricPolicyErrorKind | null;
}

export function CreateMetricPolicyForm({
  onCancel,
  onSubmit,
  pending,
  submitError,
}: CreateMetricPolicyFormProps) {
  const { t } = useTranslation();
  const [label, setLabel] = useState('');
  const [excluded, setExcluded] = useState<AccountKind[]>([]);
  const [showErrors, setShowErrors] = useState(false);
  const trimmed = label.trim();
  const invalidLabel = trimmed === '' || [...trimmed].length > MAX_LABEL_LENGTH;

  function toggle(kind: AccountKind) {
    setExcluded((current) =>
      current.includes(kind) ? current.filter((item) => item !== kind) : [...current, kind],
    );
  }

  function handleSubmit(event: FormEvent) {
    event.preventDefault();
    if (invalidLabel) {
      setShowErrors(true);
      return;
    }
    onSubmit({
      label: trimmed,
      cashExcludedAccountKinds: ACCOUNT_KINDS.filter((kind) => excluded.includes(kind)),
    });
  }

  return (
    <form className={styles.form} noValidate onSubmit={handleSubmit}>
      {submitError ? (
        <p className={styles.error} role="alert">
          {t(`metricPolicy.errors.${submitError}`)}
        </p>
      ) : null}
      {showErrors && invalidLabel ? (
        <p className={styles.error} id="metric-policy-label-error" role="alert">
          {t('metricPolicy.create.labelError', { max: MAX_LABEL_LENGTH })}
        </p>
      ) : null}
      <label className={styles.field}>
        <span>{t('metricPolicy.create.label')}</span>
        <input
          aria-describedby={showErrors && invalidLabel ? 'metric-policy-label-error' : undefined}
          aria-invalid={showErrors && invalidLabel ? true : undefined}
          onChange={(event) => setLabel(event.target.value)}
          type="text"
          value={label}
        />
      </label>
      <fieldset className={styles.kinds}>
        <legend>{t('metricPolicy.create.kinds')}</legend>
        <p>{t('metricPolicy.create.kindsHint')}</p>
        {ACCOUNT_KINDS.map((kind) => (
          <label className={styles.kind} key={kind}>
            <input
              checked={excluded.includes(kind)}
              onChange={() => toggle(kind)}
              type="checkbox"
            />
            <span>{t(`catalog.accountKinds.${kind}`)}</span>
          </label>
        ))}
      </fieldset>
      <div className={styles.actions}>
        <button className="secondary-action" onClick={onCancel} type="button">
          {t('actions.cancel')}
        </button>
        <button className="primary-action" disabled={pending} type="submit">
          {t('metricPolicy.create.submit')}
        </button>
      </div>
    </form>
  );
}
