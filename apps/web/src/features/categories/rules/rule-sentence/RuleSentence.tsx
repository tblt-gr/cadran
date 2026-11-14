import type { CategorizationRuleConditions } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { conditionClauses } from './ruleSentence';
import styles from './RuleSentence.module.css';

/** A rule read as one sentence, "Si … alors …": what it matches and what it does to a movement. */
export function RuleSentence({
  conditions,
  targetCategoryLabel,
}: {
  conditions: CategorizationRuleConditions;
  targetCategoryLabel: string | null;
}) {
  const { i18n, t } = useTranslation();
  const clauses = conditionClauses(conditions, t, i18n.language);
  return (
    <p className={styles.sentence}>
      {clauses.length === 0
        ? t('categorizationRules.sentence.noCondition')
        : t('categorizationRules.sentence.if', {
            clauses: clauses.join(t('categorizationRules.sentence.and')),
          })}{' '}
      {targetCategoryLabel
        ? t('categorizationRules.sentence.then', { category: targetCategoryLabel })
        : t('categorizationRules.sentence.thenNone')}
    </p>
  );
}
