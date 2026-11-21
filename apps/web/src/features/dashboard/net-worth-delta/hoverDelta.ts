import { shownAmount } from '@/lib/shownAmount';
import type { NetWorthAmount, NetWorthDelta, NetWorthPoint } from '@cadran/api-client';
import { compareDecimals, decimalRatio, roundDecimal, subtractDecimals } from '@/lib/decimal';
import { ratioToPercent } from '@/lib/formatRatioPercentage';

/**
 * What moved between the compared day of the period and a hovered point, for the card to show
 * while the pointer scrubs the curve. It reuses the shape of the backend delta. Both sides are
 * the figures already on screen and the arithmetic is exact decimal string work, never a `Number`.
 * Without a usable point or base the amount is null with the backend's own reason.
 */
export function hoverDelta(point: NetWorthPoint, base: NetWorthDelta): NetWorthDelta {
  const previous = base.previousTotal;
  if (point.total === null || previous === null) {
    return {
      ...base,
      amount: null,
      amountReason: point.total === null ? point.reason : base.previousReason,
      rate: null,
      ratePercent: null,
      ratePercentDisplay: null,
      rateReason: null,
    };
  }
  const current = shownAmount(point.total);
  const start = shownAmount(previous);
  const change = subtractDecimals(current.value, start.value);
  const amount: NetWorthAmount = {
    value: change,
    assetCode: current.assetCode,
    display: { value: change, assetCode: current.assetCode },
    belowDisplayStep: false,
  };
  const positiveBase = compareDecimals(start.value, '0') > 0;
  const percent = positiveBase
    ? roundDecimal(ratioToPercent(decimalRatio(change, start.value, 6)), 2)
    : null;

  return {
    ...base,
    amount,
    amountReason: null,
    rate: null,
    ratePercent: percent,
    ratePercentDisplay: percent,
    rateReason: positiveBase ? null : (base.rateReason ?? 'ZERO_BASE'),
  };
}
