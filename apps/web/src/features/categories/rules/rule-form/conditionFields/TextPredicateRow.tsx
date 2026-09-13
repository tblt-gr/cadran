import type { RuleTextPredicate } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import formStyles from '@/features/categories/rules/rule-form/RuleForm.module.css';
import styles from './TextPredicateRow.module.css';

type Operator = RuleTextPredicate['operator'];

const SOURCES: RuleTextPredicate['source'][] = ['RAW_LABEL', 'NORMALIZED_LABEL', 'COUNTERPARTY'];
const OPERATORS: Operator[] = ['CONTAINS', 'EQUALS', 'REGEX'];
const NEGATED_PREFIX = 'NOT_';

interface TextPredicateRowProps {
  invalid: boolean;
  onChange: (patch: Partial<RuleTextPredicate>) => void;
  onRemove: () => void;
  predicate: RuleTextPredicate;
  row: number;
}

/**
 * One text predicate read as a sentence: source, comparison, value. The
 * negation is folded into the comparison ("does not contain") rather than a
 * separate checkbox, so the row states exactly what it matches.
 */
export function TextPredicateRow({
  invalid,
  onChange,
  onRemove,
  predicate,
  row,
}: TextPredicateRowProps) {
  const { t } = useTranslation();
  const errorId = useId();
  const comparison = `${predicate.negated ? NEGATED_PREFIX : ''}${predicate.operator}`;

  return (
    <li className={styles.row}>
      <select
        aria-label={t('categorizationRules.conditions.source', { row })}
        className={styles.source}
        onChange={(event) =>
          onChange({ source: event.target.value as RuleTextPredicate['source'] })
        }
        value={predicate.source}
      >
        {SOURCES.map((source) => (
          <option key={source} value={source}>
            {t(`categorizationRules.conditions.sources.${source}`)}
          </option>
        ))}
      </select>
      <select
        aria-label={t('categorizationRules.conditions.operator', { row })}
        className={styles.operator}
        onChange={(event) => {
          const negated = event.target.value.startsWith(NEGATED_PREFIX);
          const operator = (
            negated ? event.target.value.slice(NEGATED_PREFIX.length) : event.target.value
          ) as Operator;
          onChange({ negated, operator });
        }}
        value={comparison}
      >
        <optgroup label={t('categorizationRules.conditions.operatorGroups.include')}>
          {OPERATORS.map((operator) => (
            <option key={operator} value={operator}>
              {t(`categorizationRules.operators.${operator}`)}
            </option>
          ))}
        </optgroup>
        <optgroup label={t('categorizationRules.conditions.operatorGroups.exclude')}>
          {OPERATORS.map((operator) => (
            <option key={operator} value={`${NEGATED_PREFIX}${operator}`}>
              {t(`categorizationRules.negatedOperators.${operator}`)}
            </option>
          ))}
        </optgroup>
      </select>
      <div className={styles.value}>
        <input
          aria-describedby={invalid ? errorId : undefined}
          aria-invalid={invalid ? true : undefined}
          aria-label={t('categorizationRules.conditions.value', { row })}
          autoComplete="off"
          maxLength={120}
          onChange={(event) => onChange({ value: event.target.value })}
          placeholder={t(
            predicate.operator === 'REGEX'
              ? 'categorizationRules.conditions.regexPlaceholder'
              : 'categorizationRules.conditions.valuePlaceholder',
          )}
          value={predicate.value}
        />
        {invalid ? (
          <small className={formStyles.error} id={errorId}>
            {t('categorizationRules.validation.textValue')}
          </small>
        ) : null}
      </div>
      <button
        aria-label={t('categorizationRules.conditions.remove', { row })}
        className={`icon-button ${styles.remove}`}
        onClick={onRemove}
        type="button"
      >
        <Icon name="delete" size={16} />
      </button>
    </li>
  );
}
