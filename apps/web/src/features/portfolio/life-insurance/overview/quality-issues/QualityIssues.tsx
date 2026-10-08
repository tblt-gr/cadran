import { capturePortfolioSource } from '@/features/portfolio/portfolio-account/portfolioDetailLocation';
import type { RefObject } from 'react';
import { SkeletonText } from '@/components/ui/skeleton/Skeleton';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import {
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import styles from './QualityIssues.module.css';
const sentences = {
  INTEREST_BASIS_UNKNOWN: 'La base des intérêts crédités n’est pas précisée.',
  MISSING_EURO_FUND_RATE: 'Un taux servi manque pour les intérêts enregistrés.',
  STALE_VALUATION: 'La valorisation est ancienne.',
  NO_VALUATION: 'Aucune valorisation complète à cette date.',
  NOT_DETAILED: 'La valorisation ne détaille pas les supports.',
  RATE_NOT_UPDATED: 'Le taux servi d’un fonds en euros manque pour la dernière année clôturée.',
};
export function QualityIssues({
  summary,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  return (
    <section className={`card ${styles.card}`} aria-busy={!summary}>
      <h3>Qualité des données</h3>
      {!summary ? (
        <SkeletonText />
      ) : summary.qualityIssues.length === 0 ? (
        <p>Aucun point de qualité signalé.</p>
      ) : (
        <ul>
          {summary.qualityIssues.map((issue) => (
            <li key={issue.code}>
              <p>
                {sentences[issue.code]} ({issue.count})
              </p>
              {issue.operationIds.map((id, index) => {
                const href = sourceHref(accountId, summary.asOf, 'OPERATION', id);
                return (
                  <a
                    key={id}
                    href={href}
                    onClick={(event) => {
                      capturePortfolioSource(event.currentTarget, sourceReturnFocus);
                      handleClientNavigation(event, href);
                    }}
                  >
                    Opération {index + 1}
                  </a>
                );
              })}
              {issue.count > issue.operationIds.length && issue.operationIds.length > 0 ? (
                <p>{issue.count - issue.operationIds.length} autres opérations concernées</p>
              ) : null}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
