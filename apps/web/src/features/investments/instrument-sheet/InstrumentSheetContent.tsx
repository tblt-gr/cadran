import type { InstrumentHolding } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { InstrumentSheetSummary } from './instrument-sheet-summary/InstrumentSheetSummary';
import { InstrumentAccountsTable } from './instrument-accounts-table/InstrumentAccountsTable';
export function InstrumentSheetContent({ data }: { data: InstrumentHolding | null }) {
  const { t } = useTranslation();
  return (
    <>
      <InstrumentSheetSummary data={data} />
      {data && !data.holding && data.accounts.length === 0 ? (
        <p>{t('investments.sheet.notHeld')}</p>
      ) : (
        <InstrumentAccountsTable data={data} />
      )}
      {data?.truncated ? <p role="status">{t('investments.sheet.truncated')}</p> : null}
    </>
  );
}
