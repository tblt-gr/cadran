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

/**
 * The message a balance that cannot be read earns, by verdict. A figure still
 * being typed is deliberately absent: it is not yet wrong, so it is reported
 * as nothing at all rather than as an error the next keystroke would clear.
 */
const BALANCE_ERRORS = {
  negativeBalance: 'productModels.period.scale.previewNegativeBalance',
  paddedBalance: 'productModels.period.scale.previewPaddedBalance',
  invalidBalance: 'productModels.period.scale.previewInvalidBalance',
} as const;

interface ScalePreviewProps {
  brackets: BracketValues[];
  rateApplication: RateApplication;
}

interface Settled {
  balance: string;
  brackets: BracketValues[];
  scale: string;
}

/**
 * The scale's content as one comparable string. Comparing the bracket array by
 * reference instead would make the debounce depend on a caller's render
 * habits: one that rebuilds the array inline on every render would leave the
 * preview permanently mid-debounce and permanently blank, with nothing to
 * show for it — no error, no failing test.
 */
function scaleKey(brackets: BracketValues[]): string {
  return JSON.stringify(
    brackets.map(({ lowerBound, upperBound, percentage }) => [lowerBound, upperBound, percentage]),
  );
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
  const scale = scaleKey(brackets);
  const [settled, setSettled] = useState<Settled>({ balance: '', brackets, scale });

  useEffect(() => {
    const timeout = setTimeout(
      () => setSettled({ balance: previewBalance, brackets, scale }),
      PREVIEW_DEBOUNCE_MS,
    );
    return () => clearTimeout(timeout);
    // `brackets` is read but deliberately not a dependency: `scale` is its
    // content, and rearming on the array's identity would restart the delay
    // on every render of a caller that rebuilds it inline.
  }, [previewBalance, scale]); // eslint-disable-line react-hooks/exhaustive-deps

  // The balance shown in the field or the scale being edited may already
  // have moved past what `settled` still describes, during the debounce
  // window — the message must go quiet rather than answer for an input that
  // is no longer the one on screen.
  const stale = settled.balance !== previewBalance || settled.scale !== scale;
  const preview = matchingBracket(settled.brackets, settled.balance);
  const touched = settled.balance.trim() !== '';
  const balanceError =
    !stale && touched && preview.kind in BALANCE_ERRORS
      ? BALANCE_ERRORS[preview.kind as keyof typeof BALANCE_ERRORS]
      : undefined;

  // Blank before the reader has typed anything reads as "nothing to preview
  // yet", not as a wrong balance — that message only earns its place once
  // there is something to be wrong about.
  function statusMessage(): string {
    if (stale || !touched) {
      return '';
    }

    switch (preview.kind) {
      case 'incompleteBalance':
      case 'negativeBalance':
      case 'paddedBalance':
      case 'invalidBalance':
        // A balance that cannot be read is reported by the field itself,
        // through `FieldRow`, and never repeated here.
        return '';
      case 'incompleteScale':
        return t('productModels.period.scale.previewIncompleteScale');
      case 'missingRate':
        return t('productModels.period.scale.previewMissingRate');
      case 'noMatch':
        return t('productModels.period.scale.previewNoMatch');
      case 'match':
        return t(
          rateApplication === 'FLAT_BY_BRACKET'
            ? 'productModels.period.scale.previewResultFlatByBracket'
            : 'productModels.period.scale.previewResultMarginal',
          {
            value: t('catalog.rules.percentValue', {
              value: formatDecimal(preview.bracket.percentage, i18n.language),
            }),
          },
        );
    }
  }

  const previewMessage = statusMessage();

  return (
    <div className={styles.preview}>
      <FieldRow
        error={balanceError ? t(balanceError) : undefined}
        hint={t('productModels.period.scale.previewBalanceHint')}
        label={t('productModels.period.scale.previewBalance')}
        liveMessage
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={balanceError ? true : undefined}
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
