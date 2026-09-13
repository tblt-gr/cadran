import type { CategorizationRuleConditions } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import formStyles from '@/features/categories/rules/rule-form/RuleForm.module.css';
import styles from './AmountConditionFields.module.css';

interface AmountConditionFieldsProps {
  conditions: CategorizationRuleConditions;
  onChange: (conditions: CategorizationRuleConditions) => void;
}

export function AmountConditionFields({ conditions, onChange }: AmountConditionFieldsProps) {
  const { t } = useTranslation();
  const amount = conditions.amount;
  const enabled = amount !== null && amount !== undefined;

  function update(field: 'assetCode' | 'max' | 'min', value: string) {
    onChange({
      ...conditions,
      amount: {
        assetCode: amount?.assetCode ?? 'EUR',
        max: amount?.max ?? null,
        min: amount?.min ?? null,
        [field]: value === '' && field !== 'assetCode' ? null : value,
      },
    });
  }

  return (
    <div className={formStyles.stack}>
      <p className={formStyles.hint}>{t('categorizationRules.conditions.amountHint')}</p>
      <label className={formStyles.choice}>
        <input
          checked={enabled}
          onChange={(event) =>
            onChange({
              ...conditions,
              amount: event.target.checked ? { assetCode: 'EUR', max: null, min: null } : null,
            })
          }
          type="checkbox"
        />
        <span>{t('categorizationRules.conditions.amountEnabled')}</span>
      </label>
      {enabled ? (
        <div className={styles.fields}>
          <label className={formStyles.field}>
            <span>{t('categorizationRules.conditions.minimum')}</span>
            <input
              inputMode="decimal"
              onChange={(event) => update('min', event.target.value)}
              value={amount.min ?? ''}
            />
          </label>
          <label className={formStyles.field}>
            <span>{t('categorizationRules.conditions.maximum')}</span>
            <input
              inputMode="decimal"
              onChange={(event) => update('max', event.target.value)}
              value={amount.max ?? ''}
            />
          </label>
          <label className={formStyles.field}>
            <span>{t('categorizationRules.conditions.assetCode')}</span>
            <input
              aria-invalid={amount.assetCode === '' ? true : undefined}
              maxLength={12}
              onChange={(event) => update('assetCode', event.target.value.toUpperCase())}
              value={amount.assetCode}
            />
          </label>
        </div>
      ) : null}
    </div>
  );
}
