import type { Account } from '@cadran/api-client';
import { SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import { AccountForm } from '@/features/accounts/account-form/AccountForm';
import styles from './AccountEditorSkeleton.module.css';

const noop = () => undefined;

/**
 * The account's own form, frozen while its product or template resolves: the
 * values are already known, only the constraints are pending, so the final
 * layout is drawn by the form itself rather than by a copy of it.
 */
export function AccountEditorSkeleton({ account, status }: { account: Account; status: string }) {
  return (
    <div aria-busy="true" className={styles.frozen} data-testid="account-editor-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      {/* Hidden from assistive technology so the status above stays the one announcement. */}
      <fieldset aria-hidden="true" className={styles.frozen} disabled inert>
        <AccountForm
          account={account}
          onCancel={noop}
          onSubmit={noop}
          origin={null}
          pending
          submitError={null}
        />
      </fieldset>
    </div>
  );
}
