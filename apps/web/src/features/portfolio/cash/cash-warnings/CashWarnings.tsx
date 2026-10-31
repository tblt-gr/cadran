import type { PortfolioCash, PortfolioCashWarning } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import { portfolioAccountHref } from '@/features/portfolio/portfolioAccountHref';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { informativeCodes, isHiddenWarning, sourceKind, warningSentence } from './cashWarningText';
import styles from './CashWarnings.module.css';

function SourceLink({ href, children }: { children: string; href: string }) {
  return (
    <a className="text-action" href={href} onClick={(event) => handleClientNavigation(event, href)}>
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

/**
 * The cash checks as one pill beside the balance: how many points to review, opening the list
 * with the operations or movements behind each, informative points folded below. Nothing shows
 * while loading or when only informative points remain.
 */
export function CashWarnings({
  accountId,
  cash,
}: {
  accountId: string;
  cash: PortfolioCash | null;
}) {
  const { i18n } = useTranslation();
  const [open, setOpen] = useState(false);
  const shown = (cash?.warnings ?? []).filter(
    (warning) => !isHiddenWarning(warning, i18n.language),
  );
  const warnings = shown.filter((warning) => !informativeCodes.includes(warning.code));
  const infos = shown.filter((warning) => informativeCodes.includes(warning.code));

  function row(warning: PortfolioCashWarning, data: PortfolioCash) {
    return (
      <li className={styles.row} key={warning.code}>
        <span>{warningSentence(warning, i18n.language)}</span>
        {sourceKind(warning.code) === 'operation' ? (
          <span className={styles.sources}>
            {warning.sourceIds.map((id, index) => (
              <SourceLink href={`/portfolios/${accountId}?tab=operations&operation=${id}`} key={id}>
                {operationLabel(data, id, index)}
              </SourceLink>
            ))}
          </span>
        ) : (
          <SourceLink href={portfolioAccountHref(accountId, data.to.slice(0, 7))}>
            Voir les mouvements du compte
          </SourceLink>
        )}
      </li>
    );
  }

  if (cash === null || warnings.length === 0) return null;

  return (
    <>
      <button className={styles.pill} onClick={() => setOpen(true)} type="button">
        <StatusBadge tone="warning">{`À vérifier (${warnings.length})`}</StatusBadge>
      </button>
      {open ? (
        <Modal close={() => setOpen(false)} title="Contrôle des espèces">
          <div className={styles.content}>
            <ul className={styles.list}>{warnings.map((warning) => row(warning, cash))}</ul>
            {infos.length > 0 ? (
              <details className={styles.infos}>
                <summary>{`Informations (${infos.length})`}</summary>
                <ul className={styles.list}>{infos.map((warning) => row(warning, cash))}</ul>
              </details>
            ) : null}
          </div>
        </Modal>
      ) : null}
    </>
  );
}
