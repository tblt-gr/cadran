import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './rule-list/RuleList.module.css';

/** Mirrors RuleList element for element: only the rows are pending. */
export function CategorizationRulesPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div
      aria-busy="true"
      className={`card ${styles.panel}`}
      data-testid="categorization-rules-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">{t('categorizationRules.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('categorizationRules.list.priority')}</th>
              <th scope="col">{t('categorizationRules.list.label')}</th>
              <th scope="col">{t('categorizationRules.list.period')}</th>
              <th scope="col">{t('categorizationRules.list.scope')}</th>
              <th scope="col">{t('categorizationRules.list.applied')}</th>
              <th scope="col">{t('categorizationRules.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('categorizationRules.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={7} rows={5} />
        </table>
      </div>
    </div>
  );
}
