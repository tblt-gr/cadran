import type { CategorizationRuleConditions } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import formStyles from '@/features/categories/rules/rule-form/RuleForm.module.css';

interface OtherConditionFieldsProps {
  conditions: CategorizationRuleConditions;
  onChange: (conditions: CategorizationRuleConditions) => void;
}

export function OtherConditionFields({ conditions, onChange }: OtherConditionFieldsProps) {
  const { t } = useTranslation();
  return (
    <div className={formStyles.fieldGrid}>
      <label className={formStyles.field}>
        <span>{t('categorizationRules.conditions.direction')}</span>
        <select
          onChange={(event) =>
            onChange({
              ...conditions,
              direction: event.target.value === '' ? null : (event.target.value as 'IN' | 'OUT'),
            })
          }
          value={conditions.direction ?? ''}
        >
          <option value="">{t('categorizationRules.conditions.anyDirection')}</option>
          <option value="IN">{t('categorizationRules.directions.IN')}</option>
          <option value="OUT">{t('categorizationRules.directions.OUT')}</option>
        </select>
      </label>
      <label className={formStyles.field}>
        <span>{t('categorizationRules.conditions.mcc')}</span>
        <input
          inputMode="numeric"
          maxLength={4}
          onChange={(event) =>
            onChange({
              ...conditions,
              mcc: event.target.value === '' ? null : event.target.value,
            })
          }
          placeholder="5411"
          value={conditions.mcc ?? ''}
        />
        <small className={formStyles.hint}>{t('categorizationRules.conditions.mccHint')}</small>
      </label>
    </div>
  );
}
