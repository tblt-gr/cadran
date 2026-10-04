import { clearPortfolioDetailSelection } from '@/features/portfolio/portfolio-account/portfolioDetailLocation';
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
import { ValuationsToolbar } from './ValuationsToolbar';
import { VALUATIONS_PER_PAGE, useValuations } from './useValuations';
import styles from './ValuationsTab.module.css';

type Dialog =
  | { kind: 'record'; valuedOn?: string }
  | { kind: 'replace'; valuation: PortfolioValuationSummary }
  | { kind: 'withdraw'; valuation: PortfolioValuationSummary }
  | null;

/** The Valorisations tab: dated valuations of the account and the record, replace and withdraw modals. */
function requestedMonthEnd(): string | null {
  return parseMonthEnd(new URLSearchParams(window.location.search).get('monthEnd'));
}

export function ValuationsTab({
  account,
  asOf,
  sourceReturnFocus,
}: {
  account: Account;
  asOf?: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
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
      <ValuationsToolbar
        disabled={account.status !== 'ACTIVE'}
        dueMonths={dueMonths}
        onCreate={() => setDialog({ kind: 'record' })}
        onMonthEnd={(month) => setDialog({ kind: 'record', valuedOn: lastDayOfMonth(month) })}
      />
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
          <p>Aucune valorisation. Enregistrez la valeur de vos titres à partir d’un relevé.</p>
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
      {dialog?.kind === 'record' || dialog?.kind === 'replace' ? (
        <ValuationModal
          account={account}
          initialDate={dialog.kind === 'record' ? dialog.valuedOn : undefined}
          onClose={() => setDialog(null)}
          onSaved={(saved) => {
            setDialog(null);
            setStale(false);
            setDetailId(saved.id);
          }}
          onStale={() => {
            setDialog(null);
            setStale(true);
          }}
          replacing={
            dialog.kind === 'replace'
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
            clearPortfolioDetailSelection();
            setDetailId(null);
          }}
          onOpen={setDetailId}
        />
      ) : null}
    </div>
  );
}
