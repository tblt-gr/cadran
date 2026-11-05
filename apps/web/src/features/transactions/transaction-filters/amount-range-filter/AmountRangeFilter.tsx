import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  isValidAmountBound,
  normalizeAmountInput,
} from '@/features/transactions/transaction-filters/filterState';
import { AmountSlider } from './AmountSlider';
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

/**
 * Unsigned amount range filter: bounds are compared with the absolute value of the amount. A
 * slider over fixed thresholds sets them roughly, the two fields set them exactly. Its bounds
 * only apply once an asset code names their unit; with a single asset among the accounts that
 * unit is implicit, so the selector is hidden and the code follows the bounds.
 */
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
  const implicitAsset = assetCodes.length === 1 ? assetCodes[0] : null;
  const assetMissing =
    implicitAsset === null && assetCode === '' && (minAmount !== '' || maxAmount !== '');

  function commit(bounds: { maxAmount: string; minAmount: string }) {
    const asset =
      implicitAsset === null
        ? assetCode
        : bounds.minAmount !== '' || bounds.maxAmount !== ''
          ? implicitAsset
          : '';
    onChange({ assetCode: asset, ...bounds });
  }

  return (
    <fieldset className={styles.range}>
      <legend className="sr-only">{t('transactions.filters.amountLabel')}</legend>
      <AmountSlider
        assetCode={implicitAsset ?? (assetCode || null)}
        maxAmount={maxAmount}
        minAmount={minAmount}
        onChange={commit}
      />
      <div className={styles.bounds}>
        <AmountBoundField
          committed={minAmount}
          label={t('transactions.filters.minAmount')}
          onCommit={(value) => commit({ maxAmount, minAmount: value })}
          revision={revision}
        />
        <AmountBoundField
          committed={maxAmount}
          label={t('transactions.filters.maxAmount')}
          onCommit={(value) => commit({ maxAmount: value, minAmount })}
          revision={revision}
        />
      </div>
      <p className={styles.hint}>{t('transactions.filters.amountHint')}</p>
      {implicitAsset === null ? (
        <div className={styles.bound}>
          <label className={styles.bound}>
            <span>{t('transactions.filters.assetCode')}</span>
            <select
              aria-describedby={assetMissing ? assetMissingId : undefined}
              aria-invalid={assetMissing ? true : undefined}
              onChange={(event) =>
                onChange({ assetCode: event.target.value, maxAmount, minAmount })
              }
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
      ) : null}
    </fieldset>
  );
}
