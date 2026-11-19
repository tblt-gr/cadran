import { useTranslation } from 'react-i18next';
import { InstrumentSheetContent } from './InstrumentSheetContent';
/** Mirrors InstrumentSheetContent element for element through the same components. */
export function InstrumentSheetPageSkeleton() {
  const { t } = useTranslation();
  return (
    <>
      <span className="sr-only" role="status">
        {t('investments.sheet.loadingSheet')}
      </span>
      <InstrumentSheetContent data={null} />
    </>
  );
}
