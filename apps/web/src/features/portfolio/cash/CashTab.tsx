import { useState } from 'react';
import { useTranslation } from 'react-i18next';
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

const refusalCodes = [
  'cash_range_too_large',
  'cash_range_too_dense',
  'invalid_cash_query',
  'cash_amount_too_large',
] as const;

/** The Cash tab: derived cash balance with its checks, period totals and running movements. */
export function CashTab({
  accountId,
  asOf,
  onAsOfChange,
}: {
  accountId: string;
  asOf?: string;
  onAsOfChange?: (date: string) => void;
}) {
  const { t } = useTranslation();
  const timeZone = useWorkspaceTimeZone();
  const today = workspaceToday(new Date(), timeZone);
  const [range, setRange] = useState(() => ({
    from: startOfYear(asOf ?? today),
    to: asOf ?? today,
  }));
  const from = asOf && range.to !== asOf ? startOfYear(asOf) : range.from;
  const cash = useCash(accountId, from, asOf ?? range.to);
  const refusal =
    cash.error instanceof OperationRequestError &&
    cash.error.code &&
    (refusalCodes as readonly string[]).includes(cash.error.code)
      ? t(`portfolio.cash.refusals.${cash.error.code as (typeof refusalCodes)[number]}`)
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
          title={refusal ? t('portfolio.cash.refused') : t('portfolio.cash.unavailable')}
        >
          {refusal ? <p>{refusal}</p> : null}
          <button className="secondary-action" onClick={() => void cash.refetch()} type="button">
            {t('actions.retry')}
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
