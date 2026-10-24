import type { CategorizationRuleConditions } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ruleDirection, toSignedBounds, toTypedBounds } from './ruleAmountBounds';
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
  const direction = ruleDirection(conditions.direction);
  // The bounds are typed unsigned on an outflow rule and stored signed.
  const typed = toTypedBounds({ max: amount?.max ?? null, min: amount?.min ?? null }, direction);

  function update(field: 'assetCode' | 'max' | 'min', value: string) {
    if (field === 'assetCode') {
      onChange({ ...conditions, amount: { ...amount!, assetCode: value } });
      return;
    }

    const next = { ...typed, [field]: value === '' ? null : value };
    onChange({
      ...conditions,
      amount: { assetCode: amount?.assetCode ?? 'EUR', ...toSignedBounds(next, direction) },
    });
  }

  return (
    <div className={formStyles.stack}>
      <p className={formStyles.hint}>
        {t(
          direction === 'OUTFLOW'
            ? 'categorizationRules.conditions.amountHintOutflow'
            : 'categorizationRules.conditions.amountHint',
        )}
      </p>
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
              value={typed.min ?? ''}
            />
          </label>
          <label className={formStyles.field}>
            <span>{t('categorizationRules.conditions.maximum')}</span>
            <input
              inputMode="decimal"
              onChange={(event) => update('max', event.target.value)}
              value={typed.max ?? ''}
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
      {enabled && direction === 'OUTFLOW' ? (
        <p aria-live="polite" className={formStyles.hint}>
          {t('categorizationRules.conditions.amountStored', {
            bounds:
              [
                amount.min
                  ? t('categorizationRules.conditions.amountMin', {
                      asset: amount.assetCode,
                      value: amount.min,
                    })
                  : null,
                amount.max
                  ? t('categorizationRules.conditions.amountMax', {
                      asset: amount.assetCode,
                      value: amount.max,
                    })
                  : null,
              ]
                .filter((bound) => bound !== null)
                .join(' · ') || t('categorizationRules.conditions.amountUnbounded'),
          })}
        </p>
      ) : null}
    </div>
  );
}
