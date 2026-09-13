import type {
  CategorizationRuleConditions,
  RuleTextConditionGroup,
  RuleTextPredicate,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from '@/features/categorization-rules/rule-form/RuleForm.module.css';

const MAX_TEXT_PREDICATES = 20;

interface TextConditionFieldsProps {
  conditions: CategorizationRuleConditions;
  invalid?: boolean;
  onChange: (conditions: CategorizationRuleConditions) => void;
  showErrors?: boolean;
}

function newPredicate(): RuleTextPredicate {
  return { source: 'RAW_LABEL', operator: 'CONTAINS', value: '', negated: false };
}

export function TextConditionFields({
  conditions,
  invalid = false,
  onChange,
  showErrors = false,
}: TextConditionFieldsProps) {
  const { t } = useTranslation();
  const text = conditions.text;
  const predicates = text?.predicates ?? [];

  function updateText(next: RuleTextConditionGroup | null) {
    onChange({ ...conditions, text: next });
  }

  function updatePredicate(index: number, patch: Partial<RuleTextPredicate>) {
    if (text === null || text === undefined) {
      return;
    }
    updateText({
      ...text,
      predicates: text.predicates.map((predicate, predicateIndex) =>
        predicateIndex === index ? { ...predicate, ...patch } : predicate,
      ),
    });
  }

  function addPredicate() {
    if (predicates.length >= MAX_TEXT_PREDICATES) {
      return;
    }
    updateText({
      combinator: text?.combinator ?? 'AND',
      predicates: [...predicates, newPredicate()],
    });
  }

  function removePredicate(index: number) {
    if (text === null || text === undefined) {
      return;
    }
    const nextPredicates = text.predicates.filter((_, predicateIndex) => predicateIndex !== index);
    updateText(nextPredicates.length === 0 ? null : { ...text, predicates: nextPredicates });
  }

  return (
    <fieldset className={styles.conditions}>
      <legend>{t('categorizationRules.conditions.textTitle')}</legend>
      <p>{t('categorizationRules.conditions.textHint')}</p>
      {text ? (
        <label className={styles.combinator}>
          <span>{t('categorizationRules.conditions.combinator')}</span>
          <select
            aria-describedby="text-condition-combinator-hint"
            aria-label={t('categorizationRules.conditions.combinator')}
            onChange={(event) =>
              updateText({
                ...text,
                combinator: event.target.value as RuleTextConditionGroup['combinator'],
              })
            }
            value={text.combinator}
          >
            <option value="AND">{t('categorizationRules.conditions.combinators.AND')}</option>
            <option value="OR">{t('categorizationRules.conditions.combinators.OR')}</option>
          </select>
          <small id="text-condition-combinator-hint">
            {t(`categorizationRules.conditions.combinatorHints.${text.combinator}`)}
          </small>
        </label>
      ) : null}
      {predicates.map((predicate, index) => {
        const row = index + 1;
        const valueInvalid =
          showErrors && (predicate.value.trim() === '' || predicate.value.length > 120);
        return (
          <div className={styles.textConditionRow} key={index}>
            <label>
              <span>{t('categorizationRules.conditions.source', { row })}</span>
              <select
                aria-label={t('categorizationRules.conditions.source', { row })}
                onChange={(event) =>
                  updatePredicate(index, {
                    source: event.target.value as RuleTextPredicate['source'],
                  })
                }
                value={predicate.source}
              >
                <option value="RAW_LABEL">
                  {t('categorizationRules.conditions.sources.RAW_LABEL')}
                </option>
                <option value="NORMALIZED_LABEL">
                  {t('categorizationRules.conditions.sources.NORMALIZED_LABEL')}
                </option>
                <option value="COUNTERPARTY">
                  {t('categorizationRules.conditions.sources.COUNTERPARTY')}
                </option>
              </select>
            </label>
            <label>
              <span>{t('categorizationRules.conditions.operator', { row })}</span>
              <select
                aria-label={t('categorizationRules.conditions.operator', { row })}
                onChange={(event) =>
                  updatePredicate(index, {
                    operator: event.target.value as RuleTextPredicate['operator'],
                  })
                }
                value={predicate.operator}
              >
                <option value="EQUALS">{t('categorizationRules.operators.EQUALS')}</option>
                <option value="CONTAINS">{t('categorizationRules.operators.CONTAINS')}</option>
                <option value="REGEX">{t('categorizationRules.operators.REGEX')}</option>
              </select>
            </label>
            <label>
              <span>{t('categorizationRules.conditions.value', { row })}</span>
              <input
                aria-invalid={valueInvalid ? true : undefined}
                aria-label={t('categorizationRules.conditions.value', { row })}
                maxLength={120}
                onChange={(event) => updatePredicate(index, { value: event.target.value })}
                value={predicate.value}
              />
              {valueInvalid ? <small>{t('categorizationRules.validation.textValue')}</small> : null}
            </label>
            <label className={styles.conditionToggle}>
              <input
                aria-label={t('categorizationRules.conditions.negated', { row })}
                checked={predicate.negated}
                onChange={(event) => updatePredicate(index, { negated: event.target.checked })}
                type="checkbox"
              />
              <span>{t('categorizationRules.conditions.negated', { row })}</span>
            </label>
            <button
              aria-label={t('categorizationRules.conditions.remove', { row })}
              className={styles.removeCondition}
              onClick={() => removePredicate(index)}
              type="button"
            >
              {t('categorizationRules.conditions.remove', { row })}
            </button>
          </div>
        );
      })}
      <button
        className={styles.addCondition}
        disabled={predicates.length >= MAX_TEXT_PREDICATES}
        onClick={addPredicate}
        type="button"
      >
        {t('categorizationRules.conditions.add')}
      </button>
      {invalid && showErrors ? (
        <p className={styles.textValidation} role="alert">
          {t('categorizationRules.validation.textValue')}
        </p>
      ) : null}
      {predicates.length >= MAX_TEXT_PREDICATES ? (
        <p className={styles.limit}>{t('categorizationRules.conditions.limit')}</p>
      ) : null}
    </fieldset>
  );
}
