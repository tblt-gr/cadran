import type { CategorizationRule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CategorizationRuleRequestError } from '@/features/categories/rules/categorizationRuleError';
import type { useCategorizationRules } from '@/features/categories/rules/useCategorizationRules';
import { RuleList } from './RuleList';
import { IncrementalList } from '@/components/ui/incremental-list/IncrementalList';
import styles from './RulesSection.module.css';

interface RulesSectionProps {
  canEdit: boolean;
  onAddFirst: () => void;
  onArchive: (rule: CategorizationRule) => void;
  onEdit: (rule: CategorizationRule) => void;
  onPreview: (rule: CategorizationRule) => void;
  onToggle: (rule: CategorizationRule) => void;
  rules: ReturnType<typeof useCategorizationRules>;
}

/**
 * The rule list's own lifecycle: loading, refused, empty, or the table with
 * its pagination. Kept apart from the account-scope notice above it, which
 * answers a different question (can a rule be edited at all).
 */
export function RulesSection({
  canEdit,
  onAddFirst,
  onArchive,
  onEdit,
  onPreview,
  onToggle,
  rules,
}: RulesSectionProps) {
  const { t } = useTranslation();
  const unauthorized =
    rules.query.error instanceof CategorizationRuleRequestError &&
    rules.query.error.kind === 'unauthorized';

  if (rules.query.isError && !rules.query.data) {
    return (
      <section className={`card ${styles.state}`} role="alert">
        <h2>
          {t(
            unauthorized
              ? 'categorizationRules.unauthorized.title'
              : 'categorizationRules.error.title',
          )}
        </h2>
        <p>
          {t(
            unauthorized
              ? 'categorizationRules.unauthorized.description'
              : 'categorizationRules.error.description',
          )}
        </p>
        {!unauthorized ? (
          <button
            className="secondary-action"
            onClick={() => void rules.query.refetch()}
            type="button"
          >
            {t('foundation.retry')}
          </button>
        ) : null}
      </section>
    );
  }

  if (rules.list.length === 0) {
    return (
      <section className={`card ${styles.state}`}>
        <h2>{t('categorizationRules.empty.title')}</h2>
        <p>{t('categorizationRules.empty.description')}</p>
        <button className="secondary-action" disabled={!canEdit} onClick={onAddFirst} type="button">
          {t('categorizationRules.addFirst')}
        </button>
      </section>
    );
  }

  return (
    <IncrementalList items={rules.list} query={rules.query}>
      {(visibleItems) => (
        <RuleList
          busy={rules.query.isFetching}
          canEdit={canEdit}
          onArchive={onArchive}
          onEdit={onEdit}
          onPreview={onPreview}
          onToggle={onToggle}
          rules={visibleItems}
        />
      )}
    </IncrementalList>
  );
}
