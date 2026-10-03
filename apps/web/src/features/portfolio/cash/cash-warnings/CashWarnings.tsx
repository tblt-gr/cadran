import type { PortfolioCash } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { informativeCodes, sourceKind, warningSentence } from './cashWarningText';
import styles from './CashWarnings.module.css';

function SourceLink({ href, children }: { children: string; href: string }) {
  return (
    <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
      {children}
    </a>
  );
}

function operationLabel(cash: PortfolioCash, id: string, index: number): string {
  const movement = cash.movements.find((candidate) => candidate.operationId === id);
  return movement
    ? `${operationTypeLabel(movement.type)} du ${formatCalendarNumericDay(movement.tradeDate)}`
    : `Opération ${index + 1}`;
}

/** One row per cash warning, never blocking; `null` renders the loading layout. */
export function CashWarnings({
  accountId,
  cash,
}: {
  accountId: string;
  cash: PortfolioCash | null;
}) {
  const { i18n } = useTranslation();
  const titleId = useId();

  return (
    <section aria-labelledby={titleId} className={`card ${styles.panel}`}>
      <h3 id={titleId}>Contrôles d’espèces</h3>
      {cash === null ? (
        <SkeletonBlock className={styles.skeleton} />
      ) : cash.warnings.length === 0 ? (
        <p className={styles.row}>
          <StatusBadge tone="positive">Aucune anomalie</StatusBadge>
          <span>Aucune anomalie d’espèces sur la période</span>
        </p>
      ) : (
        <ul className={styles.list}>
          {cash.warnings.map((warning) => (
            <li className={styles.row} key={warning.code}>
              <StatusBadge tone={informativeCodes.includes(warning.code) ? 'info' : 'warning'}>
                {informativeCodes.includes(warning.code) ? 'Information' : 'À vérifier'}
              </StatusBadge>
              <span>{warningSentence(warning, i18n.language)}</span>
              {sourceKind(warning.code) === 'operation' ? (
                <span className={styles.sources}>
                  {warning.sourceIds.map((id, index) => (
                    <SourceLink
                      href={`/portfolios/${accountId}?tab=operations&operation=${id}`}
                      key={id}
                    >
                      {operationLabel(cash, id, index)}
                    </SourceLink>
                  ))}
                </span>
              ) : (
                <SourceLink href={`/accounts/${accountId}?month=${cash.to.slice(0, 7)}`}>
                  Voir les mouvements du compte
                </SourceLink>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
