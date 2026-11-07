import { InstrumentSheetContent } from './InstrumentSheetContent';
/** Mirrors InstrumentSheetContent element for element through the same components. */
export function InstrumentSheetPageSkeleton() {
  return (
    <>
      <span className="sr-only" role="status">
        Chargement de la fiche instrument…
      </span>
      <InstrumentSheetContent data={null} />
    </>
  );
}
