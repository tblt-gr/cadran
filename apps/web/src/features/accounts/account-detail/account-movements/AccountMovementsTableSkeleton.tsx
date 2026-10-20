import { AccountMovementsTable } from './AccountMovementsTable';

/** Mirrors AccountMovementsTable element for element through its nullable rows. */
export function AccountMovementsTableSkeleton({ announce = true }: { announce?: boolean }) {
  return <AccountMovementsTable transactions={null} announce={announce} />;
}
