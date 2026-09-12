import type { CategorizationRuleConditions } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from '../RuleForm.module.css';

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
    <fieldset className={styles.conditions}>
      <legend>{t('categorizationRules.conditions.amountTitle')}</legend>
      <p>{t('categorizationRules.conditions.amountHint')}</p>
      <div className={styles.amountFields}>
        <label>
          <span>{t('categorizationRules.conditions.minimum')}</span>
          <input
            disabled={!enabled}
            inputMode="decimal"
            onChange={(event) => update('min', event.target.value)}
            value={amount?.min ?? ''}
          />
        </label>
        <label>
          <span>{t('categorizationRules.conditions.maximum')}</span>
          <input
            disabled={!enabled}
            inputMode="decimal"
            onChange={(event) => update('max', event.target.value)}
            value={amount?.max ?? ''}
          />
        </label>
        <label>
          <span>{t('categorizationRules.conditions.assetCode')}</span>
          <input
            disabled={!enabled}
            maxLength={12}
            onChange={(event) => update('assetCode', event.target.value.toUpperCase())}
            value={amount?.assetCode ?? ''}
          />
        </label>
      </div>
      <label className={styles.conditionToggle}>
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
        <span>{t('categorizationRules.conditions.enabled')}</span>
      </label>
    </fieldset>
  );
}
