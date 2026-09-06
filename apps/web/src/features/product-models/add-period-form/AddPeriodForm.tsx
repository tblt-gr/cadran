import type { ProductModel, ProductModelRuleInput, ProductRuleKind } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { ProductModelErrorKind } from '@/features/product-models/productModelError';
import { PeriodFields } from '@/features/product-models/period-fields/PeriodFields';
import {
  emptyPeriod,
  periodProblems,
  toRuleInput,
} from '@/features/product-models/period-fields/periodValues';
import { RULE_KINDS, ruleKindAvailable } from '@/features/product-models/ruleKinds';
import styles from './AddPeriodForm.module.css';

interface AddPeriodFormProps {
  model: ProductModel;
  pending: boolean;
  submitError: ProductModelErrorKind | null;
  onCancel: () => void;
  onSubmit: (rule: ProductModelRuleInput, version: number) => void;
}

/**
 * Records one dated period on a model. Recording is adding: the server appends
 * the period and closes the open-ended one of the same kind it supersedes the
 * day before it starts. There is no period edit and no deletion — a mistake is
 * fixed by archiving the model and duplicating it — so this form only ever
 * describes a new period, never rewrites an old one.
 */
export function AddPeriodForm({
  model,
  pending,
  submitError,
  onCancel: _onCancel,
  onSubmit,
}: AddPeriodFormProps) {
  const { t } = useTranslation();
  const firstAvailable =
    RULE_KINDS.find((kind) => ruleKindAvailable(kind, model.yieldKind, model.capabilities)) ??
    ('ELIGIBILITY' as ProductRuleKind);
  const [values, setValues] = useState(() => emptyPeriod(firstAvailable));
  const [showErrors, setShowErrors] = useState(false);

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (periodProblems(values).length > 0) {
      setShowErrors(true);

      return;
    }

    onSubmit(toRuleInput(values), model.version);
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`productModels.errors.${submitError}`)}
        </p>
      ) : null}

      <p className={styles.hint}>{t('productModels.period.appendHint')}</p>

      <PeriodFields
        capabilities={model.capabilities}
        onChange={setValues}
        showErrors={showErrors}
        value={values}
        yieldKind={model.yieldKind}
      />

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'productModels.period.recording' : 'productModels.period.record')}
        </button>
      </div>
    </form>
  );
}
