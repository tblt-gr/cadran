import { useState } from 'react';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { OperationsState } from '@/features/portfolio/portfolio-account/operations/OperationsState';
import { OperationRequestError } from '@/features/portfolio/portfolio-account/operations/operationError';
import { workspaceToday } from '@/lib/workspaceTime';
import { startOfYear } from './cashPeriod';
import { CashMovementsTable } from './cash-movements-table/CashMovementsTable';
import { CashSummary } from './cash-summary/CashSummary';
import { CashWarnings } from './cash-warnings/CashWarnings';
import { CashToolbar } from './CashToolbar';
import { useCash } from './useCash';
import styles from './CashTab.module.css';

const refusals: Record<string, string> = {
  cash_range_too_large: 'La période ne peut pas dépasser 366 jours : réduisez-la.',
  cash_range_too_dense: 'La période compte plus de 2 000 mouvements : réduisez-la.',
  invalid_cash_query:
    'La période est invalide : la fin ne précède pas le début et ne peut pas être dans le futur.',
  cash_amount_too_large:
    'Les montants d’espèces sont trop grands pour être additionnés exactement.',
};

/** The Espèces tab: derived cash balance with its checks, period totals and running movements. */
export function CashTab({
  accountId,
  asOf,
  onAsOfChange,
}: {
  accountId: string;
  asOf?: string;
  onAsOfChange?: (date: string) => void;
}) {
  const timeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), timeZone);
  const [range, setRange] = useState(() => ({
    from: startOfYear(asOf ?? today),
    to: asOf ?? today,
  }));
  const from = asOf && range.to !== asOf ? startOfYear(asOf) : range.from;
  const cash = useCash(accountId, from, asOf ?? range.to);
  const refusal =
    cash.error instanceof OperationRequestError && cash.error.code
      ? refusals[cash.error.code]
      : undefined;

  return (
    <div className={styles.tab}>
      <CashToolbar
        from={from}
        to={asOf ?? range.to}
        today={today}
        onChange={(next) => {
          setRange(next);
          if (asOf && next.to !== asOf) onAsOfChange?.(next.to);
        }}
      />
      {cash.isError ? (
        <OperationsState
          error
          title={refusal ? 'Période refusée' : 'Les espèces sont indisponibles'}
        >
          {refusal ? <p>{refusal}</p> : null}
          <button className="secondary-action" onClick={() => void cash.refetch()} type="button">
            Réessayer
          </button>
        </OperationsState>
      ) : (
        <div
          aria-busy={cash.isPending}
          className={styles.tab}
          data-testid={cash.isPending ? 'cash-skeleton' : undefined}
        >
          <CashSummary
            cash={cash.data ?? null}
            checks={<CashWarnings accountId={accountId} cash={cash.data ?? null} />}
          />
          <CashMovementsTable cash={cash.data ?? null} />
        </div>
      )}
    </div>
  );
}
