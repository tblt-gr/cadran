import type { RateApplication } from '@cadran/api-client';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FieldRow } from '@/features/product-models/field-row/FieldRow';
import {
  matchingBracket,
  type BracketValues,
} from '@/features/product-models/period-fields/periodValues';
import { formatDecimal } from '@/lib/decimal';
import styles from './ScalePreview.module.css';

// DecimalValue caps a canonical figure at 26 integer digits, a dot and 24
// fraction digits; nothing longer could be a valid balance, so the field is
// bounded at that length rather than left open (DoD: bound input volumes).
const MAX_BALANCE_LENGTH = 51;
// Long enough that typing "15000" digit by digit doesn't announce four wrong
// readings first (for "1", "15", "150", "1500") before the fifth settles;
// short enough that the announcement still feels immediate once a reader
// pauses. Editing a bracket rearms the same delay: the scale being read is as
// much an input to the preview as the balance is.
const PREVIEW_DEBOUNCE_MS = 300;

interface ScalePreviewProps {
  brackets: BracketValues[];
  rateApplication: RateApplication;
}

interface Settled {
  balance: string;
  brackets: BracketValues[];
}

/**
 * A configuration-time preview: which bracket of the scale being edited an
 * example balance falls into, and what that bracket pays under the chosen
 * application mode. It answers no question about any real account — nothing
 * here is submitted with the period, and an account's actual balance isn't
 * modelled anywhere yet.
 */
export function ScalePreview({ brackets, rateApplication }: ScalePreviewProps) {
  const { i18n, t } = useTranslation();
  const [previewBalance, setPreviewBalance] = useState('');
  const [settled, setSettled] = useState<Settled>({ balance: '', brackets });

  useEffect(() => {
    const timeout = setTimeout(
      () => setSettled({ balance: previewBalance, brackets }),
      PREVIEW_DEBOUNCE_MS,
    );
    return () => clearTimeout(timeout);
  }, [previewBalance, brackets]);

  // The balance shown in the field or the scale being edited may already
  // have moved past what `settled` still describes, during the debounce
  // window — the message must go quiet rather than answer for an input that
  // is no longer the one on screen. Comparing `brackets` by reference relies
  // on every caller treating it as immutable and building a new array on
  // each edit — true of `RateScaleEditor`'s `map`/`filter`/spread — never
  // mutating a row in place.
  const stale = settled.balance !== previewBalance || settled.brackets !== brackets;
  const preview = matchingBracket(settled.brackets, settled.balance);
  const touched = settled.balance.trim() !== '';
  const invalidBalance = !stale && touched && preview.kind === 'invalidBalance';

  // Blank before the reader has typed anything reads as "nothing to preview
  // yet", not as a wrong balance — that message only earns its place once
  // there is something to be wrong about. An invalid balance is reported by
  // the field itself, through `FieldRow`, not repeated here.
  const previewMessage =
    stale || !touched || preview.kind === 'invalidBalance'
      ? ''
      : preview.kind === 'incompleteScale'
        ? t('productModels.period.scale.previewIncompleteScale')
        : preview.kind === 'noMatch'
          ? t('productModels.period.scale.previewNoMatch')
          : t(
              rateApplication === 'FLAT_BY_BRACKET'
                ? 'productModels.period.scale.previewResultFlatByBracket'
                : 'productModels.period.scale.previewResultMarginal',
              {
                value: t('catalog.rules.percentValue', {
                  value: formatDecimal(preview.bracket.percentage, i18n.language),
                }),
              },
            );

  return (
    <div className={styles.preview}>
      <FieldRow
        error={invalidBalance ? t('productModels.period.scale.previewInvalidBalance') : undefined}
        hint={t('productModels.period.scale.previewBalanceHint')}
        label={t('productModels.period.scale.previewBalance')}
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={invalidBalance ? true : undefined}
            id={fieldId}
            inputMode="decimal"
            maxLength={MAX_BALANCE_LENGTH}
            onChange={(event) => setPreviewBalance(event.target.value)}
            onKeyDown={(event) => {
              // This field previews the scale and carries nothing to submit;
              // the form around it saves a dated period on Enter, so Enter
              // here must not record one.
              if (event.key === 'Enter') {
                event.preventDefault();
              }
            }}
            value={previewBalance}
          />
        )}
      </FieldRow>
      <p className={styles.previewResult} role="status">
        {previewMessage}
      </p>
    </div>
  );
}
