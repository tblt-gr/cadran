import type { ProductModelRuleInput, ProductRuleKind, RateApplication } from '@cadran/api-client';
import { compareUnsignedDecimals, isCanonicalUnsignedDecimal } from '@/lib/decimal';
import { ruleValueType } from '@/features/product-models/ruleKinds';

/** One bracket of a rate scale, as raw field text before validation. */
export interface BracketValues {
  lowerBound: string;
  upperBound: string;
  percentage: string;
}

/**
 * The raw field state of one dated period, before validation. It is not a
 * `ProductModelRuleInput`: a half-filled row has empty dates and an unset
 * amount, which the request shape cannot express.
 */
export interface PeriodValues {
  kind: ProductRuleKind;
  amount: string;
  amountAssetCode: string;
  text: string;
  scaleShape: 'single' | 'tiered';
  rateApplication: RateApplication;
  singleRate: string;
  brackets: BracketValues[];
  validFrom: string;
  validTo: string;
}

const CANONICAL_DECIMAL = /^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/;
const TOKEN = /^[A-Z][A-Z0-9]*(_[A-Z0-9]+)*$/;
const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/;

export function emptyBracket(): BracketValues {
  return { lowerBound: '', upperBound: '', percentage: '' };
}

export function emptyPeriod(kind: ProductRuleKind): PeriodValues {
  return {
    kind,
    amount: '',
    amountAssetCode: 'EUR',
    text: '',
    scaleShape: 'single',
    rateApplication: 'MARGINAL',
    singleRate: '',
    brackets: [
      { lowerBound: '0', upperBound: '', percentage: '' },
      { lowerBound: '', upperBound: '', percentage: '' },
    ],
    validFrom: '',
    validTo: '',
  };
}

/** The bracket list a rate period submits: one open-ended bracket for a single rate. */
function scaleBrackets(values: PeriodValues): BracketValues[] {
  if (values.scaleShape === 'single') {
    return [{ lowerBound: '0', upperBound: '', percentage: values.singleRate.trim() }];
  }

  return values.brackets.map((bracket) => ({
    lowerBound: bracket.lowerBound.trim(),
    upperBound: bracket.upperBound.trim(),
    percentage: bracket.percentage.trim(),
  }));
}

/** The answer {@link matchingBracket} gives for one example balance. */
export type BracketPreview =
  | { kind: 'match'; bracket: BracketValues }
  | { kind: 'noMatch' }
  | { kind: 'incompleteBalance' }
  | { kind: 'negativeBalance' }
  | { kind: 'paddedBalance' }
  | { kind: 'invalidBalance' }
  | { kind: 'incompleteScale' }
  | { kind: 'missingRate' };

/** A figure whose next keystroke is a decimal digit: `1000.`, not yet `1000.5`. */
const BEING_TYPED = /^[0-9]+\.$/;
/** `007`, padded rather than malformed — `0.7` and `0` are not this. */
const LEADING_ZERO = /^0[0-9]/;

/**
 * Why a balance isn't a canonical unsigned decimal, in the reader's terms.
 * `isCanonicalUnsignedDecimal` refuses a sign, a padding zero and a trailing
 * dot as flatly as it refuses a comma or a letter, and telling someone who
 * typed `-500` to use digits and a dot names a rule they already followed.
 */
function balanceProblem(balance: string): BracketPreview {
  if (BEING_TYPED.test(balance)) {
    return { kind: 'incompleteBalance' };
  }
  if (balance.startsWith('-')) {
    return { kind: 'negativeBalance' };
  }
  if (LEADING_ZERO.test(balance)) {
    return { kind: 'paddedBalance' };
  }

  return { kind: 'invalidBalance' };
}

/**
 * The bracket an example balance falls into, for the scale being edited — a
 * preview only, not tied to any account's actual balance and never a computed
 * effective rate: which rate a matched bracket actually pays on the balance
 * still depends on the application mode (`MARGINAL` or `FLAT_BY_BRACKET`),
 * which this function has no opinion on. Comparison is by exact decimal
 * string throughout, never by parsing either side to a `Number`.
 *
 * The balance verdicts — `incompleteBalance`, `negativeBalance`,
 * `paddedBalance` and `invalidBalance` — mean the balance itself cannot be
 * read; the scale may be perfectly fine. `incompleteBalance` is the one that
 * is not a mistake: a figure whose next keystroke would complete it, which a
 * reader pausing mid-number should not be scolded for. `incompleteScale` means
 * the opposite: the balance is fine but the draft scale's *bounds*, read in
 * the row order the holder entered them, aren't the shape the backend
 * requires yet — starting at zero, each row's upper bound equal to the next
 * row's lower bound with no gap or overlap, and the last row open-ended. Rows
 * are never reordered to make a scale fit: `toRuleInput` submits them in row
 * order and `RateScale` reads that same order, so a scale only well-formed
 * once sorted is exactly as incomplete as one with a hole. Rates are judged
 * apart from bounds, and only on the bracket actually reached: a blank rate
 * two tiers above the balance says nothing about where that balance falls, so
 * it is `missingRate` — never a verdict on bounds that are in fact correct.
 * `noMatch` would mean a well-bounded scale failed to cover a balance, which
 * the [0, +∞[ invariant makes unreachable in practice, but a match loop that
 * found nothing should say so rather than being folded into the others.
 */
export function matchingBracket(brackets: BracketValues[], balance: string): BracketPreview {
  const trimmedBalance = balance.trim();
  if (!isCanonicalUnsignedDecimal(trimmedBalance)) {
    return balanceProblem(trimmedBalance);
  }

  const scale = wellBoundedScale(brackets);
  if (scale === null) {
    return { kind: 'incompleteScale' };
  }

  for (const bracket of scale) {
    const atOrAboveLowerBound = compareUnsignedDecimals(trimmedBalance, bracket.lowerBound) >= 0;
    const belowUpperBound =
      bracket.upperBound === '' || compareUnsignedDecimals(trimmedBalance, bracket.upperBound) < 0;

    if (atOrAboveLowerBound && belowUpperBound) {
      return CANONICAL_DECIMAL.test(bracket.percentage)
        ? { kind: 'match', bracket }
        : { kind: 'missingRate' };
    }
  }

  return { kind: 'noMatch' };
}

/**
 * The draft brackets, trimmed, if their bounds — read in the row order they
 * were entered, never sorted — tile [0, +∞[ with no gap and no overlap: the
 * invariant `RateScale::__construct` checks position by position server-side.
 * `null` covers everything short of that: a bound not yet a canonical
 * decimal, a first row not starting at zero, a last row that isn't
 * open-ended, or a hole, overlap or wrong order between two rows. Rates are
 * deliberately out of scope here — a half-filled rate column leaves the
 * bounds as tiled as they were, and {@link matchingBracket} judges the rate
 * of the reached bracket alone.
 */
function wellBoundedScale(brackets: BracketValues[]): BracketValues[] | null {
  if (brackets.length === 0) {
    return null;
  }

  const trimmed = brackets.map((bracket) => ({
    lowerBound: bracket.lowerBound.trim(),
    upperBound: bracket.upperBound.trim(),
    percentage: bracket.percentage.trim(),
  }));

  for (const bracket of trimmed) {
    if (!isCanonicalUnsignedDecimal(bracket.lowerBound)) {
      return null;
    }
    if (bracket.upperBound !== '' && !isCanonicalUnsignedDecimal(bracket.upperBound)) {
      return null;
    }
  }

  if (compareUnsignedDecimals(trimmed[0].lowerBound, '0') !== 0) {
    return null;
  }

  for (const [index, bracket] of trimmed.entries()) {
    const isLast = index === trimmed.length - 1;

    if (isLast) {
      if (bracket.upperBound !== '') {
        return null;
      }
      continue;
    }

    const next = trimmed[index + 1];
    const upperBoundAboveLowerBound =
      bracket.upperBound !== '' &&
      compareUnsignedDecimals(bracket.upperBound, bracket.lowerBound) > 0;
    const meetsNextWithNoGapOrOverlap =
      bracket.upperBound !== '' &&
      compareUnsignedDecimals(bracket.upperBound, next.lowerBound) === 0;

    if (!upperBoundAboveLowerBound || !meetsNextWithNoGapOrOverlap) {
      return null;
    }
  }

  return trimmed;
}

/**
 * The messages that would send the caller back to a field. An empty list means
 * the period is shaped well enough to submit; the backend still holds the
 * tiling and non-overlap invariants and answers 422 with an actionable detail.
 */
export function periodProblems(values: PeriodValues): string[] {
  const problems: string[] = [];
  const type = ruleValueType(values.kind);

  if (values.validFrom === '' || !ISO_DAY.test(values.validFrom)) {
    problems.push('validFrom');
  }
  if (
    values.validTo !== '' &&
    (!ISO_DAY.test(values.validTo) || values.validTo < values.validFrom)
  ) {
    problems.push('validTo');
  }

  if (type === 'AMOUNT') {
    // A ceiling is a bound, never a debt: the contract and the domain both
    // refuse a sign, so a negative one is caught here rather than as an
    // unattributed 422.
    if (!isCanonicalUnsignedDecimal(values.amount.trim())) {
      problems.push('amount');
    }
    if (!/^[A-Z][A-Z0-9]{1,11}$/.test(values.amountAssetCode.trim())) {
      problems.push('amountAssetCode');
    }
  }

  if (type === 'TEXT' && !TOKEN.test(values.text.trim())) {
    problems.push('text');
  }

  if (type === 'PERCENTAGE') {
    const brackets = scaleBrackets(values);
    if (brackets.length === 0) {
      problems.push('brackets');
    }
    for (const bracket of brackets) {
      if (!isCanonicalUnsignedDecimal(bracket.lowerBound)) {
        problems.push('brackets');
        break;
      }
      if (bracket.upperBound !== '' && !isCanonicalUnsignedDecimal(bracket.upperBound)) {
        problems.push('brackets');
        break;
      }
      if (!CANONICAL_DECIMAL.test(bracket.percentage)) {
        problems.push('brackets');
        break;
      }
    }
  }

  return problems;
}

/** Builds the wire body once {@link periodProblems} is empty. */
export function toRuleInput(values: PeriodValues): ProductModelRuleInput {
  const type = ruleValueType(values.kind);
  const brackets =
    type === 'PERCENTAGE'
      ? scaleBrackets(values).map((bracket) => ({
          lowerBound: bracket.lowerBound,
          upperBound: bracket.upperBound === '' ? null : bracket.upperBound,
          percentage: bracket.percentage,
        }))
      : [];

  return {
    kind: values.kind,
    amount: type === 'AMOUNT' ? values.amount.trim() : null,
    amountAssetCode: type === 'AMOUNT' ? values.amountAssetCode.trim() : null,
    text: type === 'TEXT' ? values.text.trim() : null,
    rateApplication: type === 'PERCENTAGE' ? values.rateApplication : null,
    brackets,
    validFrom: values.validFrom,
    validTo: values.validTo === '' ? null : values.validTo,
  };
}
