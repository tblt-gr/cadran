import { capturePortfolioSource } from '@/features/portfolio/portfolio-account/portfolioDetailLocation';
import type { RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import {
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './QualityIssues.module.css';
export function QualityIssues({
  summary,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const { t } = useTranslation();
  if (!summary || summary.qualityIssues.length === 0) return null;
  return (
    <section
      className={styles.alert}
      role="status"
      aria-label={t('portfolio.lifeInsurance.quality.label')}
    >
      <Icon name="alert" size={18} />
      <ul>
        {summary.qualityIssues.map((issue) => (
          <li key={issue.code}>
            <p>
              {t(`portfolio.lifeInsurance.quality.sentences.${issue.code}`)} ({issue.count})
            </p>
            {issue.operationIds.map((id, index) => {
              const href = sourceHref(accountId, summary.asOf, 'OPERATION', id);
              return (
                <a
                  key={id}
                  href={href}
                  data-quality-source-key={`${issue.code}:${id}`}
                  onClick={(event) => {
                    capturePortfolioSource(event.currentTarget, sourceReturnFocus);
                    handleClientNavigation(event, href);
                  }}
                >
                  {t('portfolio.lifeInsurance.quality.operation', { index: index + 1 })}
                </a>
              );
            })}
            {issue.count > issue.operationIds.length && issue.operationIds.length > 0 ? (
              <p>
                {t('portfolio.lifeInsurance.quality.moreOperations', {
                  count: issue.count - issue.operationIds.length,
                })}
              </p>
            ) : null}
          </li>
        ))}
      </ul>
    </section>
  );
}
