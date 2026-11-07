import type { Holdings } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { HoldingsByAccountRow } from './HoldingsByAccountRow';
import styles from './HoldingsByAccountTable.module.css';

const PENDING_ROWS = 3;

/** One line per account, in the server order: no header sorts here. */
export function HoldingsByAccountTable({ holdings }: { holdings: Holdings | null }) {
  const { t } = useTranslation();
  const [opened, setOpened] = useState<ReadonlySet<string>>(new Set());
  const toggle = (id: string) =>
    setOpened((current) => {
      const next = new Set(current);
      if (!next.delete(id)) next.add(id);
      return next;
    });
  const labels = [
    t('investments.byAccount.securities'),
    t('investments.byAccount.gain'),
    t('investments.byAccount.share'),
    t('investments.byAccount.valuedOn'),
  ];

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">{t('investments.byAccount.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('investments.byAccount.account')}</th>
              {labels.map((label) => (
                <th key={label} scope="col">
                  {label}
                </th>
              ))}
              <th scope="col">
                <span className="sr-only">{t('investments.byAccount.detail')}</span>
              </th>
            </tr>
          </thead>
          <tbody aria-hidden={holdings ? undefined : true}>
            {holdings
              ? holdings.accounts.map((account) => (
                  <HoldingsByAccountRow
                    account={account}
                    assetCode={holdings.assetCode}
                    key={account.accountId}
                    onToggle={() => toggle(account.accountId)}
                    open={opened.has(account.accountId)}
                  />
                ))
              : Array.from({ length: PENDING_ROWS }, (_, row) => (
                  <tr key={row}>
                    <th scope="row">
                      <a className={styles.account}>
                        <SkeletonBlock className={styles.nameBlock} />
                      </a>
                      <span className={styles.identifier}>
                        <SkeletonBlock className={styles.cellBlock} />
                      </span>
                    </th>
                    {labels.map((label) => (
                      <td className={styles.number} data-label={label} key={label}>
                        <SkeletonBlock className={styles.cellBlock} />
                      </td>
                    ))}
                    <td className={styles.toggle}>
                      <button className={`icon-button ${styles.chevron}`} disabled type="button">
                        <Icon name="chevron-right" size={14} />
                      </button>
                    </td>
                  </tr>
                ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
