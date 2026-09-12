import type { CategorizationRuleConditions, RuleTextCondition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from '../RuleForm.module.css';

type TextField = 'counterparty' | 'normalizedLabel' | 'rawLabel';

interface TextConditionFieldsProps {
  conditions: CategorizationRuleConditions;
  onChange: (conditions: CategorizationRuleConditions) => void;
}

const fields: TextField[] = ['rawLabel', 'normalizedLabel', 'counterparty'];

export function TextConditionFields({ conditions, onChange }: TextConditionFieldsProps) {
  const { t } = useTranslation();

  function change(field: TextField, patch: Partial<RuleTextCondition>) {
    const current = conditions[field] ?? { operator: 'CONTAINS' as const, value: '' };
    onChange({ ...conditions, [field]: { ...current, ...patch } });
  }

  function clear(field: TextField) {
    onChange({ ...conditions, [field]: null });
  }

  return (
    <fieldset className={styles.conditions}>
      <legend>{t('categorizationRules.conditions.textTitle')}</legend>
      <p>{t('categorizationRules.conditions.textHint')}</p>
      {fields.map((field) => {
        const condition = conditions[field];
        return (
          <div className={styles.conditionRow} key={field}>
            <label>
              <span>{t(`categorizationRules.conditions.${field}`)}</span>
              <select
                disabled={condition === null || condition === undefined}
                onChange={(event) =>
                  change(field, { operator: event.target.value as RuleTextCondition['operator'] })
                }
                value={condition?.operator ?? 'CONTAINS'}
              >
                <option value="EQUALS">{t('categorizationRules.operators.EQUALS')}</option>
                <option value="CONTAINS">{t('categorizationRules.operators.CONTAINS')}</option>
                <option value="REGEX">{t('categorizationRules.operators.REGEX')}</option>
              </select>
            </label>
            <label>
              <span className="sr-only">{t(`categorizationRules.conditions.${field}`)}</span>
              <input
                disabled={condition === null || condition === undefined}
                maxLength={120}
                onChange={(event) => change(field, { value: event.target.value })}
                value={condition?.value ?? ''}
              />
            </label>
            <label className={styles.conditionToggle}>
              <input
                checked={condition !== null && condition !== undefined}
                onChange={(event) => (event.target.checked ? change(field, {}) : clear(field))}
                type="checkbox"
              />
              <span>{t('categorizationRules.conditions.enabled')}</span>
            </label>
          </div>
        );
      })}
    </fieldset>
  );
}
