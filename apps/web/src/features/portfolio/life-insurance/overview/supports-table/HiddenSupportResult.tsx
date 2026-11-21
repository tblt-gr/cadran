import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { AMOUNT_MASK } from '@/lib/amountPrivacy';

/** Missing gain and missing return remain distinct from confidential calculated figures. */
export function HiddenSupportResult({
  gain,
  performance,
  missing,
}: {
  gain: string | null;
  performance: string | null;
  missing: string;
}) {
  return (
    <span>
      {gain === null ? <EmptyValue label={missing} /> : <MoneyValue value={AMOUNT_MASK} />}
      {' · '}
      {performance === null ? <EmptyValue label={missing} /> : <MoneyValue value={AMOUNT_MASK} />}
    </span>
  );
}
