import type { ProductModelRuleInput, ProductRuleKind, RateApplication } from '@cadran/api-client';
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

const CANONICAL_UNSIGNED = /^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?$/;
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
    if (!CANONICAL_DECIMAL.test(values.amount.trim())) {
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
      if (!CANONICAL_UNSIGNED.test(bracket.lowerBound)) {
        problems.push('brackets');
        break;
      }
      if (bracket.upperBound !== '' && !CANONICAL_UNSIGNED.test(bracket.upperBound)) {
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
