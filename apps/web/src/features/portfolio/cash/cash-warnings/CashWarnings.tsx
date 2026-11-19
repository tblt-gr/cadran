import type { PortfolioCash, PortfolioCashWarning } from '@cadran/api-client';
import { useState } from 'react';
import type { TFunction } from 'i18next';
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

function operationLabel(t: TFunction, cash: PortfolioCash, id: string, index: number): string {
  const movement = cash.movements.find((candidate) => candidate.operationId === id);
  return movement
    ? t('portfolio.account.operations.menu.operationLabel', {
        type: operationTypeLabel(t, movement.type),
        day: formatCalendarNumericDay(movement.tradeDate),
      })
    : t('portfolio.cash.warnings.operationN', { index: index + 1 });
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
  const { i18n, t } = useTranslation();
  const [open, setOpen] = useState(false);
  const shown = (cash?.warnings ?? []).filter(
    (warning) => !isHiddenWarning(warning, i18n.language),
  );
  const warnings = shown.filter((warning) => !informativeCodes.includes(warning.code));
  const infos = shown.filter((warning) => informativeCodes.includes(warning.code));

  function row(warning: PortfolioCashWarning, data: PortfolioCash) {
    return (
      <li className={styles.row} key={warning.code}>
        <span>{warningSentence(t, warning, i18n.language)}</span>
        {sourceKind(warning.code) === 'operation' ? (
          <span className={styles.sources}>
            {warning.sourceIds.map((id, index) => (
              <SourceLink href={`/portfolios/${accountId}?tab=operations&operation=${id}`} key={id}>
                {operationLabel(t, data, id, index)}
              </SourceLink>
            ))}
          </span>
        ) : (
          <SourceLink href={portfolioAccountHref(accountId, data.to.slice(0, 7))}>
            {t('portfolio.cash.warnings.seeMovements')}
          </SourceLink>
        )}
      </li>
    );
  }

  if (cash === null || warnings.length === 0) return null;

  return (
    <>
      <button className={styles.pill} onClick={() => setOpen(true)} type="button">
        <StatusBadge tone="warning">
          {t('portfolio.cash.warnings.toCheck', { count: warnings.length })}
        </StatusBadge>
      </button>
      {open ? (
        <Modal
          intent="detail"
          close={() => setOpen(false)}
          title={t('portfolio.cash.warnings.title')}
        >
          <div className={styles.content}>
            <ul className={styles.list}>{warnings.map((warning) => row(warning, cash))}</ul>
            {infos.length > 0 ? (
              <details className={styles.infos}>
                <summary>{t('portfolio.cash.warnings.infos', { count: infos.length })}</summary>
                <ul className={styles.list}>{infos.map((warning) => row(warning, cash))}</ul>
              </details>
            ) : null}
          </div>
        </Modal>
      ) : null}
    </>
  );
}
