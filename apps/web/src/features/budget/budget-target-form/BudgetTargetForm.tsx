import type {
  BudgetScopeType,
  BudgetTargetDetail,
  CreateBudgetTargetRequest,
  UpdateBudgetTargetRequest,
} from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { isCanonicalUnsignedDecimal } from '@/lib/decimal';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import type { BudgetErrorKind } from '@/features/budget/budgetError';
import styles from './BudgetTargetForm.module.css';

const AXES = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
] as const;
interface Props {
  target?: BudgetTargetDetail;
  pending: boolean;
  submitError: BudgetErrorKind | null;
  onSubmit: (body: CreateBudgetTargetRequest | UpdateBudgetTargetRequest) => void;
}
export function BudgetTargetForm({ target, pending, submitError, onSubmit }: Props) {
  const { t } = useTranslation();
  const [scopeType, setScopeType] = useState<BudgetScopeType>(target?.scopeType ?? 'CATEGORY');
  const [scopeId, setScopeId] = useState(target?.scopeId ?? '');
  const [valueType, setValueType] = useState(target?.valueType ?? 'AMOUNT');
  const [value, setValue] = useState(target?.storedAmount ?? target?.storedRatio ?? '');
  const [showErrors, setShowErrors] = useState(false);
  const ratioHintId = useId();
  const valueErrorId = useId();
  const scopeValid =
    target !== undefined ||
    (scopeType === 'AXIS'
      ? AXES.includes(scopeId as (typeof AXES)[number])
      : scopeId.trim().length > 0);
  const valueValid = isCanonicalUnsignedDecimal(value) && value.length <= 75;
  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!scopeValid || !valueValid) {
      setShowErrors(true);
      return;
    }
    const common = {
      valueType,
      amount: valueType === 'AMOUNT' ? value : null,
      ratio: valueType === 'RATIO' ? value : null,
    };
    onSubmit(target ? { ...common, version: target.version } : { ...common, scopeType, scopeId });
  }
  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`budget.errors.${submitError}`)}
        </p>
      ) : null}
      {!target ? (
        <>
          <label>
            <span>{t('budget.fields.scopeType')}</span>
            <select
              aria-label={t('budget.fields.scopeType')}
              onChange={(e) => {
                setScopeType(e.target.value as BudgetScopeType);
                setScopeId('');
              }}
              value={scopeType}
            >
              <option value="CATEGORY">{t('budget.scopeTypes.CATEGORY')}</option>
              <option value="GROUP">{t('budget.scopeTypes.GROUP')}</option>
              <option value="AXIS">{t('budget.scopeTypes.AXIS')}</option>
            </select>
          </label>
          <div>
            {scopeType === 'AXIS' ? (
              <label>
                <span>{t('budget.fields.scope')}</span>
                <select
                  aria-label={t('budget.fields.scope')}
                  onChange={(e) => setScopeId(e.target.value)}
                  value={scopeId}
                >
                  <option value="">{t('budget.form.chooseAxis')}</option>
                  {AXES.map((axis) => (
                    <option key={axis} value={axis}>
                      {t(`categories.axes.${axis}`)}
                    </option>
                  ))}
                </select>
              </label>
            ) : (
              <CategoryPicker
                error={showErrors && !scopeValid ? t('budget.validation.scope') : null}
                label={t('budget.fields.scope')}
                onChange={(id) => setScopeId(id)}
                placeholder={t('categories.picker.placeholder')}
                preferredType="EXPENSE"
                type="EXPENSE"
                value={scopeId}
              />
            )}
            {showErrors && !scopeValid && scopeType === 'AXIS' ? (
              <small>{t('budget.validation.scope')}</small>
            ) : null}
          </div>
        </>
      ) : null}
      <label>
        <span>{t('budget.fields.valueType')}</span>
        <select
          aria-label={t('budget.fields.valueType')}
          onChange={(e) => {
            setValueType(e.target.value as typeof valueType);
            setValue('');
          }}
          value={valueType}
        >
          <option value="AMOUNT">{t('budget.valueTypes.AMOUNT')}</option>
          <option value="RATIO">{t('budget.valueTypes.RATIO')}</option>
        </select>
      </label>
      <div className={styles.valueField}>
        <label>
          <span>{t(valueType === 'AMOUNT' ? 'budget.fields.amount' : 'budget.fields.ratio')}</span>
          <input
            aria-describedby={
              [
                valueType === 'RATIO' ? ratioHintId : null,
                showErrors && !valueValid ? valueErrorId : null,
              ]
                .filter(Boolean)
                .join(' ') || undefined
            }
            aria-invalid={showErrors && !valueValid ? true : undefined}
            inputMode="decimal"
            onChange={(e) => setValue(e.target.value)}
            required
            value={value}
          />
        </label>
        {valueType === 'RATIO' ? (
          <small className={styles.hint} id={ratioHintId}>
            {t('budget.hints.ratio')}
          </small>
        ) : null}
        {showErrors && !valueValid ? (
          <small id={valueErrorId}>{t('budget.validation.value')}</small>
        ) : null}
      </div>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'budget.form.saving' : 'budget.form.save')}
        </button>
      </div>
    </form>
  );
}
