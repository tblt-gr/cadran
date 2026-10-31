import { dismissPortfolioDetailSelection } from '@/features/portfolio/portfolio-account/portfolioDetailLocation';
import type { Account, PortfolioValuationSummary } from '@cadran/api-client';
import { useEffect, useState, type RefObject } from 'react';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { lastDayOfMonth, parseMonthEnd } from '@/features/portfolio/month-end/monthEnd';
import { usePortfolioSummary } from '@/features/portfolio/usePortfolioOverview';
import { ValuationDetailModal } from './valuation-detail-modal/ValuationDetailModal';
import { ValuationModal } from './valuation-modal/ValuationModal';
import { ValuationsTable } from './valuations-table/ValuationsTable';
import { WithdrawValuationModal } from './withdraw-valuation-dialog/WithdrawValuationModal';
import { ValuationsTableSkeleton } from './ValuationsTableSkeleton';
import { VALUATIONS_PER_PAGE, useValuations } from './useValuations';
import styles from './ValuationsTab.module.css';

type Dialog =
  | { kind: 'record'; valuedOn?: string }
  | { kind: 'replace'; valuation: PortfolioValuationSummary }
  | { kind: 'withdraw'; valuation: PortfolioValuationSummary }
  | null;

function requestedMonthEnd(): string | null {
  return parseMonthEnd(new URLSearchParams(window.location.search).get('monthEnd'));
}

/**
 * The Valorisations tab: dated valuations of the account and the record, replace and withdraw
 * modals. A new valuation is started from the page header (`recording`) or a due month end link.
 */
export function ValuationsTab({
  account,
  asOf,
  sourceReturnFocus,
  recording = false,
  onRecordClose,
}: {
  account: Account;
  asOf?: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
  recording?: boolean;
  onRecordClose?: () => void;
}) {
  const timeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), timeZone);
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [detailId, setDetailId] = useState<string | null>(() =>
    new URLSearchParams(window.location.search).get('valuation'),
  );
  const [stale, setStale] = useState(false);
  const [monthEnd, setMonthEnd] = useState(requestedMonthEnd);
  const valuations = useValuations(account.id, page, asOf);
  const summary = usePortfolioSummary(account.id, asOf ?? today);
  const dueMonths = summary.isSuccess ? summary.data.dueMonths : null;
  const total = valuations.data?.total ?? 0;
  // The oldest month end still owed is the likeliest statement to type; the date stays editable.
  const oldestDue = dueMonths?.[0] ? lastDayOfMonth(dueMonths[0]) : null;
  const suggestedDate = oldestDue !== null && oldestDue <= today ? oldestDue : today;
  const record =
    dialog?.kind === 'record' ? dialog : recording ? { kind: 'record' as const } : null;
  const closeRecord = () => {
    setDialog(null);
    onRecordClose?.();
  };
  const totalPages = Math.max(1, Math.ceil(total / VALUATIONS_PER_PAGE));

  useEffect(() => {
    const update = () => {
      setDetailId(new URLSearchParams(window.location.search).get('valuation'));
      setMonthEnd(requestedMonthEnd());
    };
    window.addEventListener('popstate', update);
    return () => window.removeEventListener('popstate', update);
  }, []);

  // The deep link is consumed once the due months are known: a due month opens its draft,
  // any other month leaves the tab alone. Clearing it keeps a closed modal from reopening.
  useEffect(() => {
    if (monthEnd === null || dueMonths === null) return;
    const url = new URL(window.location.href);
    url.searchParams.delete('monthEnd');
    window.history.replaceState(
      window.history.state,
      '',
      `${url.pathname}${url.search}${url.hash}`,
    );
    setMonthEnd(null);
    if (dueMonths.includes(monthEnd)) {
      setDialog({ kind: 'record', valuedOn: lastDayOfMonth(monthEnd) });
    }
  }, [dueMonths, monthEnd]);

  useEffect(() => {
    if (total > 0 && page > totalPages) setPage(totalPages);
  }, [page, total, totalPages]);

  return (
    <div className={styles.tab}>
      {stale ? (
        <p className={styles.notice} role="status">
          Cette valorisation a déjà été modifiée. La liste a été actualisée.
        </p>
      ) : null}
      {valuations.isPending ? (
        <ValuationsTableSkeleton status="Chargement des valorisations…" />
      ) : valuations.isError ? (
        <OperationsState error title="Les valorisations sont indisponibles">
          <button
            className="secondary-action"
            onClick={() => void valuations.refetch()}
            type="button"
          >
            Réessayer
          </button>
        </OperationsState>
      ) : total === 0 ? (
        <OperationsState title="Aucune valorisation">
          <p>Saisissez la valeur du compte à partir d’un relevé avec Nouvelle valorisation.</p>
        </OperationsState>
      ) : (
        <ValuationsTable
          items={valuations.data.items}
          onDetail={(item) => setDetailId(item.id)}
          onReplace={(valuation) => setDialog({ kind: 'replace', valuation })}
          onWithdraw={(valuation) => setDialog({ kind: 'withdraw', valuation })}
        />
      )}
      {valuations.isSuccess && total > VALUATIONS_PER_PAGE ? (
        <nav aria-label="Pagination des valorisations" className={styles.pagination}>
          <button
            className="secondary-action"
            disabled={page === 1}
            onClick={() => setPage((value) => value - 1)}
            type="button"
          >
            Précédent
          </button>
          <span>
            Page {page} sur {totalPages}
          </span>
          <button
            className="secondary-action"
            disabled={page === totalPages}
            onClick={() => setPage((value) => value + 1)}
            type="button"
          >
            Suivant
          </button>
        </nav>
      ) : null}
      {record || dialog?.kind === 'replace' ? (
        <ValuationModal
          account={account}
          defaultDate={suggestedDate}
          initialDate={record?.valuedOn}
          onClose={closeRecord}
          onSaved={(saved) => {
            closeRecord();
            setStale(false);
            setDetailId(saved.id);
          }}
          onStale={() => {
            closeRecord();
            setStale(true);
          }}
          replacing={
            dialog?.kind === 'replace'
              ? { valuedOn: dialog.valuation.valuedOn, version: dialog.valuation.version }
              : undefined
          }
          today={today}
        />
      ) : null}
      {dialog?.kind === 'withdraw' ? (
        <WithdrawValuationModal
          onClose={() => setDialog(null)}
          onStale={() => {
            setDialog(null);
            setStale(true);
          }}
          valuation={dialog.valuation}
        />
      ) : null}
      {detailId ? (
        <ValuationDetailModal
          returnFocus={
            detailId === new URLSearchParams(window.location.search).get('valuation')
              ? sourceReturnFocus
              : undefined
          }
          id={detailId}
          onClose={() => {
            dismissPortfolioDetailSelection(sourceReturnFocus);
            setDetailId(null);
          }}
          onOpen={setDetailId}
        />
      ) : null}
    </div>
  );
}
