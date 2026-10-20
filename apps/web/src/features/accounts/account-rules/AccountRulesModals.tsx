import {
  recordAccountRuleOverride,
  withdrawAccountRuleOverride,
  type Account,
  type AccountRuleClaim,
  type ProductRuleKind,
  type RecordAccountRuleOverrideRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState, type RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import {
  accountErrorKind,
  accountRequestError,
  requestFailed,
} from '@/features/accounts/accountError';
import { AccountRuleOverrideForm } from './account-rule-override-form/AccountRuleOverrideForm';
import { AccountRulesPanel } from './AccountRulesPanel';
import type { OverrideDraft } from './overrideTarget';
import { WithdrawOverrideDialog } from './withdraw-override-dialog/WithdrawOverrideDialog';

interface AccountRulesModalsProps {
  account: Account;
  close: () => void;
  onOverrideRecorded: () => void;
  onOverrideWithdrawn: () => void;
}

/**
 * The rules of one account, and the override or withdrawal a reader opens
 * from them. Recording and withdrawing a claim both replace the rules modal
 * rather than stacking a second one over it, and closing either returns the
 * reader to the rules they came from.
 *
 * Isolated from its caller so the account list and the account detail page
 * share one mutation path for a claim instead of keeping two: only the toast
 * wording and what else gets invalidated stay with the caller.
 */
export function AccountRulesModals({
  account,
  close,
  onOverrideRecorded,
  onOverrideWithdrawn,
}: AccountRulesModalsProps) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [overriding, setOverriding] = useState<OverrideDraft | null>(null);
  const [withdrawing, setWithdrawing] = useState<{
    claim: AccountRuleClaim;
    description: string;
  } | null>(null);
  // The rule the reader acted on: its action takes focus when the rules modal
  // is mounted again, because the button they pressed left the page with it.
  const [focusKind, setFocusKind] = useState<ProductRuleKind | null>(null);
  // The control that opened the rules. Each replacement modal would otherwise
  // read `document.body` as its opener and drop focus there on the final close.
  const [opener] = useState<RefObject<HTMLElement | null>>(() => ({
    current: document.activeElement instanceof HTMLElement ? document.activeElement : null,
  }));

  async function refreshRules() {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['account-rules'] }),
      queryClient.invalidateQueries({ queryKey: ['account-rule-overrides'] }),
    ]);
  }

  const recordOverride = useMutation({
    mutationFn: async (body: RecordAccountRuleOverrideRequest) => {
      const result = await withCsrfRetry(() =>
        recordAccountRuleOverride({ ...authApiOptions(), path: { id: account.id }, body }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setOverriding(null);
      onOverrideRecorded();
      await refreshRules();
    },
  });

  const withdrawOverride = useMutation({
    mutationFn: async (overrideId: string) => {
      const result = await withCsrfRetry(() =>
        withdrawAccountRuleOverride({
          ...authApiOptions(),
          path: { id: account.id, overrideId },
          body: {},
        }),
      );
      if (requestFailed(result)) {
        throw accountRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setWithdrawing(null);
      onOverrideWithdrawn();
      await refreshRules();
    },
  });

  function closeOverride() {
    setOverriding(null);
    recordOverride.reset();
  }

  function closeWithdraw() {
    setWithdrawing(null);
    withdrawOverride.reset();
  }

  if (overriding) {
    return (
      <Modal
        close={closeOverride}
        key="override"
        returnFocus={opener}
        eyebrow={t('accounts.overrides.eyebrow')}
        title={t('accounts.overrides.title', { label: account.label })}
      >
        <AccountRuleOverrideForm
          accountAsset={account.assetCode}
          initialKind={overriding.kind}
          kinds={overriding.kinds}
          onCancel={closeOverride}
          onSubmit={(body) => recordOverride.mutate(body)}
          pending={recordOverride.isPending}
          submitError={accountErrorKind(recordOverride.error, recordOverride.isError)}
        />
      </Modal>
    );
  }

  if (withdrawing) {
    return (
      <Modal
        close={closeWithdraw}
        key="withdraw"
        returnFocus={opener}
        eyebrow={t('accounts.overrides.eyebrow')}
        title={t('accounts.overrides.withdrawTitle')}
      >
        <WithdrawOverrideDialog
          onCancel={closeWithdraw}
          onConfirm={() => withdrawOverride.mutate(withdrawing.claim.overrideId)}
          pending={withdrawOverride.isPending}
          description={withdrawing.description}
          reason={withdrawing.claim.reason}
          submitError={accountErrorKind(withdrawOverride.error, withdrawOverride.isError)}
        />
      </Modal>
    );
  }

  return (
    <Modal
      close={close}
      eyebrow={t('accounts.rules.eyebrow')}
      key="rules"
      returnFocus={opener}
      title={t('accounts.rules.title', { label: account.label })}
    >
      <AccountRulesPanel
        account={account}
        focusKind={focusKind}
        onOverride={(draft) => {
          setFocusKind(draft.kind);
          setOverriding(draft);
        }}
        onWithdraw={(claim, kind, description) => {
          setFocusKind(kind);
          setWithdrawing({ claim, description });
        }}
      />
    </Modal>
  );
}
