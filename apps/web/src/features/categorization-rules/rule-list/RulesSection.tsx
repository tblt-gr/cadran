import type { CategorizationRule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { CategorizationRuleRequestError } from '@/features/categorization-rules/categorizationRuleError';
import type { useCategorizationRules } from '@/features/categorization-rules/useCategorizationRules';
import { RuleList } from './RuleList';
import { RulePagination } from './RulePagination';
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

  if (rules.query.isPending) {
    return (
      <section className={`card ${styles.state}`} aria-busy="true" role="status">
        <h2>{t('categorizationRules.loading')}</h2>
      </section>
    );
  }

  if (rules.query.isError) {
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

  // A concurrent archive can shrink the total below the page the caller is
  // sitting on before the pagination hook clamps it back. Falling through
  // to the list below (rendered busy, with zero rows) rather than the empty
  // state avoids flashing "no rules yet" and losing focus for one render.
  if (rules.list.length === 0 && rules.page <= rules.totalPages) {
    return (
      <section className={`card ${styles.state}`}>
        <h2>{t('categorizationRules.empty.title')}</h2>
        <p>{t('categorizationRules.empty.description')}</p>
        <button className="primary-action" disabled={!canEdit} onClick={onAddFirst} type="button">
          {t('categorizationRules.addFirst')}
        </button>
      </section>
    );
  }

  return (
    <>
      <RuleList
        busy={rules.query.isFetching}
        canEdit={canEdit}
        onArchive={onArchive}
        onEdit={onEdit}
        onPreview={onPreview}
        onToggle={onToggle}
        rules={rules.list}
      />
      {rules.totalPages > 1 ? (
        <RulePagination
          onPageChange={(next) => {
            if (rules.query.isFetching) return;
            rules.goToPage(next);
          }}
          page={rules.page}
          pending={rules.query.isFetching}
          totalPages={rules.totalPages}
        />
      ) : null}
    </>
  );
}
