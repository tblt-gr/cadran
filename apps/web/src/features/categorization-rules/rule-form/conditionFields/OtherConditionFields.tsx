import type { CategorizationRuleConditions } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from '../RuleForm.module.css';

interface OtherConditionFieldsProps {
  conditions: CategorizationRuleConditions;
  onChange: (conditions: CategorizationRuleConditions) => void;
}

export function OtherConditionFields({ conditions, onChange }: OtherConditionFieldsProps) {
  const { t } = useTranslation();
  return (
    <fieldset className={styles.conditions}>
      <legend>{t('categorizationRules.conditions.otherTitle')}</legend>
      <div className={styles.amountFields}>
        <label>
          <span>{t('categorizationRules.conditions.mcc')}</span>
          <input
            maxLength={4}
            onChange={(event) =>
              onChange({
                ...conditions,
                mcc: event.target.value === '' ? null : event.target.value,
              })
            }
            value={conditions.mcc ?? ''}
          />
        </label>
        <label>
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
      </div>
    </fieldset>
  );
}
