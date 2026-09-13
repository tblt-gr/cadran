import type {
  CategorizationRuleConditions,
  RuleTextConditionGroup,
  RuleTextPredicate,
} from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import formStyles from '@/features/categories/rules/rule-form/RuleForm.module.css';
import { TextPredicateRow } from './TextPredicateRow';
import styles from './TextConditionFields.module.css';

const MAX_TEXT_PREDICATES = 20;
const COMBINATORS: RuleTextConditionGroup['combinator'][] = ['AND', 'OR'];

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
  const combinatorName = useId();
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
    <div className={formStyles.stack}>
      <p className={formStyles.hint}>{t('categorizationRules.conditions.textHint')}</p>
      {text && predicates.length > 1 ? (
        <fieldset className={formStyles.group}>
          <legend>{t('categorizationRules.conditions.combinator')}</legend>
          <div className={styles.segmented}>
            {COMBINATORS.map((combinator) => (
              <label key={combinator}>
                <input
                  checked={text.combinator === combinator}
                  className="sr-only"
                  name={combinatorName}
                  onChange={() => updateText({ ...text, combinator })}
                  type="radio"
                  value={combinator}
                />
                <span>{t(`categorizationRules.conditions.combinators.${combinator}`)}</span>
              </label>
            ))}
          </div>
          <small className={formStyles.hint}>
            {t(`categorizationRules.conditions.combinatorHints.${text.combinator}`)}
          </small>
        </fieldset>
      ) : null}
      {predicates.length > 0 ? (
        <ul className={styles.rows}>
          {predicates.map((predicate, index) => (
            <TextPredicateRow
              invalid={
                showErrors && (predicate.value.trim() === '' || predicate.value.length > 120)
              }
              key={index}
              onChange={(patch) => updatePredicate(index, patch)}
              onRemove={() => removePredicate(index)}
              predicate={predicate}
              row={index + 1}
            />
          ))}
        </ul>
      ) : null}
      <button
        className={`secondary-action ${styles.add}`}
        disabled={predicates.length >= MAX_TEXT_PREDICATES}
        onClick={addPredicate}
        type="button"
      >
        <Icon name="add" size={16} />
        {t('categorizationRules.conditions.add')}
      </button>
      {invalid && showErrors ? (
        <p className={formStyles.error} role="alert">
          {t('categorizationRules.validation.textValue')}
        </p>
      ) : null}
      {predicates.length >= MAX_TEXT_PREDICATES ? (
        <p className={formStyles.hint}>{t('categorizationRules.conditions.limit')}</p>
      ) : null}
    </div>
  );
}
