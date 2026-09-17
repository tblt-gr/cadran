import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  isValidAmountBound,
  normalizeAmountInput,
} from '@/features/transactions/transaction-filters/filterState';
import styles from './AmountRangeFilter.module.css';

interface AmountRange {
  assetCode: string;
  maxAmount: string;
  minAmount: string;
}

interface AmountRangeFilterProps extends AmountRange {
  assetCodes: string[];
  onChange: (next: AmountRange) => void;
  /** Changes when the filters are replaced from outside the bar, discarding any draft. */
  revision: number;
}

interface AmountBoundFieldProps {
  committed: string;
  label: string;
  onCommit: (value: string) => void;
  revision: number;
}

/**
 * Keeps what the user types apart from the committed bound: a half-typed value such as `-` or
 * `12.` stays in the field with an inline message and never reaches the filters, so it cannot
 * turn the whole list into an API error while the user is still typing.
 */
function AmountBoundField({ committed, label, onCommit, revision }: AmountBoundFieldProps) {
  const { t } = useTranslation();
  const errorId = useId();
  const [draft, setDraft] = useState(committed);
  const [synced, setSynced] = useState({ committed, revision });

  if (synced.committed !== committed || synced.revision !== revision) {
    setSynced({ committed, revision });
    setDraft(committed);
  }

  const invalid = draft !== '' && !isValidAmountBound(draft);

  return (
    <div className={styles.bound}>
      <label className={styles.bound}>
        <span>{label}</span>
        <input
          aria-describedby={invalid ? errorId : undefined}
          aria-invalid={invalid ? true : undefined}
          inputMode="decimal"
          onChange={(event) => {
            const next = normalizeAmountInput(event.target.value);
            setDraft(next);
            if (next === '' || isValidAmountBound(next)) {
              onCommit(next);
            }
          }}
          type="text"
          value={draft}
        />
      </label>
      {invalid ? (
        <span className={styles.error} id={errorId}>
          {t('transactions.filters.amountInvalid')}
        </span>
      ) : null}
    </div>
  );
}

/** Signed amount range filter; its bounds only apply once an asset code names their unit. */
export function AmountRangeFilter({
  assetCode,
  assetCodes,
  maxAmount,
  minAmount,
  onChange,
  revision,
}: AmountRangeFilterProps) {
  const { t } = useTranslation();
  const assetMissingId = useId();
  const assetMissing = assetCode === '' && (minAmount !== '' || maxAmount !== '');

  return (
    <fieldset className={styles.range}>
      <legend>{t('transactions.filters.amountLabel')}</legend>
      <p className={styles.hint}>{t('transactions.filters.amountHint')}</p>
      <AmountBoundField
        committed={minAmount}
        label={t('transactions.filters.minAmount')}
        onCommit={(value) => onChange({ assetCode, maxAmount, minAmount: value })}
        revision={revision}
      />
      <AmountBoundField
        committed={maxAmount}
        label={t('transactions.filters.maxAmount')}
        onCommit={(value) => onChange({ assetCode, maxAmount: value, minAmount })}
        revision={revision}
      />
      <div className={styles.bound}>
        <label className={styles.bound}>
          <span>{t('transactions.filters.assetCode')}</span>
          <select
            aria-describedby={assetMissing ? assetMissingId : undefined}
            aria-invalid={assetMissing ? true : undefined}
            onChange={(event) => onChange({ assetCode: event.target.value, maxAmount, minAmount })}
            value={assetCode}
          >
            <option value="">{t('transactions.filters.assetCodePlaceholder')}</option>
            {assetCodes.map((code) => (
              <option key={code} value={code}>
                {code}
              </option>
            ))}
          </select>
        </label>
        {assetMissing ? (
          <span className={styles.error} id={assetMissingId}>
            {t('transactions.filters.amountAssetMissing')}
          </span>
        ) : null}
      </div>
    </fieldset>
  );
}
