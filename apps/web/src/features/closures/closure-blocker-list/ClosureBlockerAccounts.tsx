import type { PeriodClosingBlocker } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { lastDayOfMonth } from '@/lib/workspaceTime';
import { ClosureBlockerItemRow } from './ClosureBlockerItemRow';
import styles from './ClosureBlockerAccounts.module.css';

/** A longer list starts folded so the closing modal stays readable. */
const OPEN_BY_DEFAULT_LIMIT = 5;

interface ClosureBlockerAccountsProps {
  blocker: PeriodClosingBlocker;
  onNavigate?: () => void;
  /** Closure month, `YYYY-MM`. */
  period: string;
}

/** The accounts behind one blocking condition, folded under its summary. */
export function ClosureBlockerAccounts({
  blocker,
  onNavigate,
  period,
}: ClosureBlockerAccountsProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(blocker.items.length <= OPEN_BY_DEFAULT_LIMIT);
  const asOf = lastDayOfMonth(`${period}-01`);

  return (
    <div className={styles.accounts}>
      <Disclosure
        onToggle={setOpen}
        open={open}
        title={t(`closures.conditions.${blocker.condition}.summary`, { count: blocker.count })}
      >
        <ul className={styles.list}>
          {blocker.items.map((item) => (
            <ClosureBlockerItemRow
              asOf={asOf}
              item={item}
              key={`${item.accountId}-${item.reason}`}
              onNavigate={onNavigate}
            />
          ))}
        </ul>
      </Disclosure>
    </div>
  );
}
