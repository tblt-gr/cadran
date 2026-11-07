import type { InstrumentHolding } from '@cadran/api-client';
import { InstrumentSheetSummary } from './instrument-sheet-summary/InstrumentSheetSummary';
import { InstrumentAccountsTable } from './instrument-accounts-table/InstrumentAccountsTable';
export function InstrumentSheetContent({ data }: { data: InstrumentHolding | null }) {
  return (
    <>
      <InstrumentSheetSummary data={data} />
      {data && !data.holding && data.accounts.length === 0 ? (
        <p>Cet instrument n'est détenu sur aucun compte.</p>
      ) : (
        <InstrumentAccountsTable data={data} />
      )}
      {data?.truncated ? <p role="status">Seuls les 50 premiers comptes sont affichés.</p> : null}
    </>
  );
}
