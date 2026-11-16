import type { Account, AccountValuation, RecordAccountBalanceRequest } from '@cadran/api-client';

/** The request recording a balance on `asOf`, replacing the same day's snapshot when one is shown. */
export function newSnapshotRequest(
  account: Account,
  valuation: AccountValuation,
  asOf: string,
  amount: string,
  comment: string,
): RecordAccountBalanceRequest {
  // A derived balance reports today's date, but its version belongs to the
  // anchor snapshot. Sending that version for a new day is a false conflict.
  const snapshotDay = valuation.derivation?.anchorAsOf ?? valuation.asOf;
  const replacingSameDay =
    valuation.quality !== 'MISSING' &&
    valuation.source === 'MANUAL' &&
    snapshotDay === asOf &&
    valuation.version !== null;

  return {
    asOf,
    amount,
    amountAssetCode: account.assetCode,
    comment: comment === '' ? null : comment,
    version: replacingSameDay ? valuation.version : null,
  };
}

/** A balance cannot be dated after the day it is requested for, nor after the account closed. */
export function latestBalanceDay(account: Account, valuation: AccountValuation): string {
  return account.closedOn !== null && account.closedOn < valuation.requestedOn
    ? account.closedOn
    : valuation.requestedOn;
}
