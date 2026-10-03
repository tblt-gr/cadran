import type { Account, PortfolioValuationSummary } from '@cadran/api-client';
import { useEffect, useState } from 'react';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { workspaceToday } from '@/lib/workspaceTime';
import { ValuationDetailModal } from './valuation-detail-modal/ValuationDetailModal';
import { ValuationModal } from './valuation-modal/ValuationModal';
import { ValuationsTable } from './valuations-table/ValuationsTable';
import { WithdrawValuationModal } from './withdraw-valuation-dialog/WithdrawValuationModal';
import { ValuationsTableSkeleton } from './ValuationsTableSkeleton';
import { ValuationsToolbar } from './ValuationsToolbar';
import { VALUATIONS_PER_PAGE, useValuations } from './useValuations';
import styles from './ValuationsTab.module.css';

type Dialog =
  | { kind: 'record' }
  | { kind: 'replace'; valuation: PortfolioValuationSummary }
  | { kind: 'withdraw'; valuation: PortfolioValuationSummary }
  | null;

/** The Valorisations tab: dated valuations of the account and the record, replace and withdraw modals. */
export function ValuationsTab({ account }: { account: Account }) {
  const timeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), timeZone);
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState<Dialog>(null);
  const [detailId, setDetailId] = useState<string | null>(null);
  const [stale, setStale] = useState(false);
  const valuations = useValuations(account.id, page);
  const total = valuations.data?.total ?? 0;
  const totalPages = Math.max(1, Math.ceil(total / VALUATIONS_PER_PAGE));

  useEffect(() => {
    if (total > 0 && page > totalPages) setPage(totalPages);
  }, [page, total, totalPages]);

  return (
    <div className={styles.tab}>
      <ValuationsToolbar
        disabled={account.status !== 'ACTIVE'}
        onCreate={() => setDialog({ kind: 'record' })}
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
          id={detailId}
          onClose={() => setDetailId(null)}
          onOpen={setDetailId}
        />
      ) : null}
    </div>
  );
}
