import { useTranslation } from 'react-i18next';
import { formatAmount, isCanonicalDecimal, isZeroDecimal } from '@/lib/decimal';
import { toSignedAmount, type AmountDirection } from '@/lib/signedAmount';
import styles from './SignedAmountHint.module.css';

interface SignedAmountHintProps {
  assetCode: string;
  direction: AmountDirection | null;
  id: string;
  /** The amount as typed, before the direction is applied. */
  typed: string;
}

/**
 * Tells the user what an amount field will record: the signed figure once the
 * entry is exact, otherwise the convention that applies. The direction is
 * named in words, so the outcome never relies on a colour or a minus alone.
 * Wire it to the field with `aria-describedby`.
 */
export function SignedAmountHint({ assetCode, direction, id, typed }: SignedAmountHintProps) {
  const { i18n, t } = useTranslation();
  const calculable = isCanonicalDecimal(typed) && !isZeroDecimal(typed);

  if (!calculable) {
    return (
      <span className={styles.hint} id={id}>
        {t(`amountEntry.convention.${direction ?? 'UNKNOWN'}`)}
      </span>
    );
  }

  const signed = toSignedAmount(typed, direction);
  const figure = formatAmount(signed, assetCode, i18n.language);

  return (
    <span className={styles.hint} id={id}>
      {t(`amountEntry.recorded.${signed.startsWith('-') ? 'OUTFLOW' : 'INFLOW'}`, {
        amount: figure,
      })}
    </span>
  );
}
