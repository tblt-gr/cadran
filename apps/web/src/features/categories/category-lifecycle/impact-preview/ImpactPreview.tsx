import type { CategoryImpact } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './ImpactPreview.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface ImpactPreviewProps {
  impact: CategoryImpact | undefined;
  status: 'error' | 'idle' | 'pending' | 'ready';
}

/**
 * What the operation would change, shown before it is confirmed.
 *
 * Counts the backend could not establish are reported as such rather than as a
 * reassuring zero, and every refusal reason is listed together so the operation
 * is not rejected one hidden rule at a time.
 */
export function ImpactPreview({ impact, status }: ImpactPreviewProps) {
  const { t } = useTranslation();

  if (status === 'idle') {
    return <p className={styles.hint}>{t('categories.lifecycle.impact.idle')}</p>;
  }

  if (status === 'pending') {
    return (
      <p aria-busy="true" className={styles.hint} role="status">
        {t('categories.lifecycle.impact.loading')}
      </p>
    );
  }

  if (status === 'error' || !impact) {
    return (
      <p className={styles.alert} role="alert">
        {t('categories.lifecycle.impact.error')}
      </p>
    );
  }

  return (
    <section aria-labelledby="category-impact-title" className={styles.preview}>
      <h3 id="category-impact-title">{t('categories.lifecycle.impact.title')}</h3>
      <dl className={styles.figures}>
        <div>
          <dt>{t('categories.lifecycle.impact.descendants')}</dt>
          <dd>{impact.descendantCount}</dd>
        </div>
        {impact.archivedDescendantCount > 0 ? (
          <div>
            <dt>{t('categories.lifecycle.impact.archivedDescendants')}</dt>
            <dd>{impact.archivedDescendantCount}</dd>
          </div>
        ) : null}
        {impact.incomingRedirectionCount > 0 ? (
          <div>
            <dt>{t('categories.lifecycle.impact.incomingRedirections')}</dt>
            <dd>{impact.incomingRedirectionCount}</dd>
          </div>
        ) : null}
        {impact.operation === 'MERGE' ? (
          <div>
            <dt>{t('categories.lifecycle.impact.reparented')}</dt>
            <dd>{impact.reparentedChildCount}</dd>
          </div>
        ) : null}
        <div>
          <dt>{t('categories.lifecycle.impact.depth')}</dt>
          <dd>
            {t('categories.lifecycle.impact.depthValue', {
              depth: impact.resultingDepth,
              maximum: impact.maximumDepth,
            })}
          </dd>
        </div>
        <div>
          <dt>{t('categories.lifecycle.impact.classifications')}</dt>
          <dd>
            {impact.affectedClassifications === null ? (
              <EmptyValue
                label={t('states.notCalculable.label')}
                reason={
                  impact.affectedClassificationsReason === null
                    ? null
                    : t(
                        `categories.lifecycle.impact.classificationsReason.${impact.affectedClassificationsReason}`,
                      )
                }
              />
            ) : (
              impact.affectedClassifications
            )}
          </dd>
        </div>
      </dl>

      <ul className={styles.consequences}>
        {impact.archivesSource ? <li>{t('categories.lifecycle.impact.archivesSource')}</li> : null}
        {impact.redirectsHistory ? (
          <li>{t('categories.lifecycle.impact.redirectsHistory')}</li>
        ) : null}
        {impact.operation === 'MERGE' && impact.incomingRedirectionCount > 0 ? (
          <li>{t('categories.lifecycle.impact.repointsRedirections')}</li>
        ) : null}
      </ul>

      {impact.blockers.length > 0 ? (
        <div className={styles.blockers} role="alert">
          <p>{t('categories.lifecycle.impact.blocked')}</p>
          <ul>
            {impact.blockers.map((blocker) => (
              <li key={blocker}>{t(`categories.lifecycle.blockers.${blocker}`)}</li>
            ))}
          </ul>
        </div>
      ) : null}
    </section>
  );
}
