import type { ReactNode, RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { useLifeInsuranceSummary } from '@/features/portfolio/life-insurance/useLifeInsuranceSummary';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import type { Summary } from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './LifeInsuranceSummaryPanel.module.css';
export type InsuranceReportProps = {
  accountId: string;
  asOf: string;
  frozen?: boolean;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
};
/** One dated contract read shared by tab-specific reports and the overview. */
export function LifeInsuranceSummaryPanel({
  accountId,
  asOf,
  frozen = false,
  title,
  children,
  announceLoading = true,
}: InsuranceReportProps & {
  title: string;
  children: (summary: Summary | null) => ReactNode;
  announceLoading?: boolean;
}) {
  const { t } = useTranslation();
  const query = useLifeInsuranceSummary(accountId, asOf, !frozen);
  const error = query.error instanceof OperationRequestError ? query.error : null;
  const href = `/portfolios/${accountId}?${new URLSearchParams({ tab: 'contract', asOf })}`;
  return (
    <div className={styles.panel} aria-busy={query.isPending}>
      {query.isPending && announceLoading ? (
        <SkeletonStatus>{t('portfolio.lifeInsurance.summaryPanel.loading')}</SkeletonStatus>
      ) : null}
      {query.isError ? (
        <section className="card" role="alert">
          <h3>
            {error?.status === 404 || error?.status === 403
              ? t('portfolio.lifeInsurance.summaryPanel.notFound')
              : error?.code === 'invalid_summary_date'
                ? t('portfolio.lifeInsurance.summaryPanel.invalidDate')
                : title}
          </h3>
          {query.data ? <p>{t('portfolio.lifeInsurance.summaryPanel.stillShown')}</p> : null}
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            {t('actions.retry')}
          </button>
        </section>
      ) : null}
      {query.data && !query.data.contract ? (
        <section className="card">
          <h3>{t('portfolio.lifeInsurance.contract.title')}</h3>
          <p>{t('portfolio.lifeInsurance.contract.fill')}</p>
          <a
            className="secondary-action"
            href={href}
            onClick={(event) => handleClientNavigation(event, href)}
          >
            {t('portfolio.lifeInsurance.contract.fillAction')}
          </a>
        </section>
      ) : query.isPending || query.data ? (
        children(query.data ?? null)
      ) : null}
    </div>
  );
}
