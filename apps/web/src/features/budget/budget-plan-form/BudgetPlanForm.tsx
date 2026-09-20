import type {
  BudgetPlan,
  CreateBudgetPlanRequest,
  UpdateBudgetPlanRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { BudgetErrorKind } from '@/features/budget/budgetError';
import styles from './BudgetPlanForm.module.css';

interface Props {
  plan?: BudgetPlan;
  pending: boolean;
  submitError: BudgetErrorKind | null;
  onSubmit: (body: CreateBudgetPlanRequest | UpdateBudgetPlanRequest) => void;
}

export function BudgetPlanForm({ plan, pending, submitError, onSubmit }: Props) {
  const { t } = useTranslation();
  const [periodType, setPeriodType] = useState(plan?.periodType ?? 'MONTH');
  const [period, setPeriod] = useState(plan?.period ?? '');
  const [assetCode, setAssetCode] = useState(plan?.assetCode ?? 'EUR');
  const [showErrors, setShowErrors] = useState(false);
  const periodValid =
    periodType === 'MONTH' ? /^\d{4}-(0[1-9]|1[0-2])$/.test(period) : /^\d{4}$/.test(period);
  const assetValid = /^[A-Z][A-Z0-9]{1,11}$/.test(assetCode);
  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!periodValid || !assetValid) {
      setShowErrors(true);
      return;
    }
    const body = { periodType, period, assetCode };
    onSubmit(plan ? { ...body, version: plan.version } : body);
  }
  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`budget.errors.${submitError}`)}
        </p>
      ) : null}
      <label>
        <span>{t('budget.fields.periodType')}</span>
        <select
          aria-label={t('budget.fields.periodType')}
          onChange={(e) => {
            setPeriodType(e.target.value as typeof periodType);
            setPeriod('');
          }}
          value={periodType}
        >
          <option value="MONTH">{t('budget.periodTypes.MONTH')}</option>
          <option value="YEAR">{t('budget.periodTypes.YEAR')}</option>
        </select>
      </label>
      <label>
        <span>{t('budget.fields.period')}</span>
        <input
          aria-invalid={showErrors && !periodValid ? true : undefined}
          data-autofocus
          inputMode="numeric"
          onChange={(e) => setPeriod(e.target.value)}
          placeholder={periodType === 'MONTH' ? '2026-03' : '2026'}
          required
          value={period}
        />
        {showErrors && !periodValid ? <small>{t('budget.validation.period')}</small> : null}
      </label>
      <label>
        <span>{t('budget.fields.assetCode')}</span>
        <input
          aria-invalid={showErrors && !assetValid ? true : undefined}
          maxLength={12}
          onChange={(e) => setAssetCode(e.target.value.toUpperCase())}
          required
          value={assetCode}
        />
        {showErrors && !assetValid ? <small>{t('budget.validation.assetCode')}</small> : null}
      </label>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'budget.form.saving' : 'budget.form.save')}
        </button>
      </div>
    </form>
  );
}
