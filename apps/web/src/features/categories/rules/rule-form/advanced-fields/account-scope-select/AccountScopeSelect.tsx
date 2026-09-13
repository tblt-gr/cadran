import type { Account } from '@cadran/api-client';
import { useEffect, useId, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './AccountScopeSelect.module.css';

interface AccountScopeSelectProps {
  accounts: Account[];
  onClear: () => void;
  onToggle: (id: string) => void;
  selected: string[];
}

/**
 * A multiple selection of the accounts a rule is limited to. An empty
 * selection means every eligible account, so the trigger says so instead of
 * reading as "nothing". The options stay native checkboxes for keyboard use,
 * and Escape folds the list without closing the hosting modal.
 */
export function AccountScopeSelect({
  accounts,
  onClear,
  onToggle,
  selected,
}: AccountScopeSelectProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const root = useRef<HTMLDivElement>(null);
  const trigger = useRef<HTMLButtonElement>(null);
  const labelId = useId();
  const valueId = useId();
  const panelId = useId();
  const hintId = useId();

  const selectedAccounts = accounts.filter((account) => selected.includes(account.id));
  const summary =
    selected.length === 0
      ? t('categorizationRules.list.allAccounts')
      : selected.length === 1 && selectedAccounts[0]
        ? selectedAccounts[0].label
        : t('categorizationRules.list.accountCount', { count: selected.length });

  useEffect(() => {
    if (!open) {
      return;
    }
    function onPointerDown(event: PointerEvent) {
      if (event.target instanceof Node && !root.current?.contains(event.target)) {
        setOpen(false);
      }
    }
    document.addEventListener('pointerdown', onPointerDown);
    return () => document.removeEventListener('pointerdown', onPointerDown);
  }, [open]);

  function onKeyDown(event: KeyboardEvent<HTMLDivElement>) {
    if (event.key === 'Escape' && open) {
      event.stopPropagation();
      setOpen(false);
      trigger.current?.focus();
    }
  }

  return (
    <div className={styles.field} onKeyDown={onKeyDown} ref={root}>
      <span id={labelId}>{t('categorizationRules.fields.accountScope')}</span>
      <div className={styles.control}>
        <button
          aria-controls={panelId}
          aria-describedby={hintId}
          aria-expanded={open}
          aria-labelledby={`${labelId} ${valueId}`}
          className={styles.trigger}
          onClick={() => setOpen((current) => !current)}
          ref={trigger}
          type="button"
        >
          <span id={valueId}>{summary}</span>
          <span aria-hidden="true" className={styles.chevron}>
            <Icon name="chevron-right" size={14} />
          </span>
        </button>
        <div
          aria-labelledby={labelId}
          className={styles.panel}
          hidden={!open}
          id={panelId}
          role="group"
        >
          {accounts.map((account) => (
            <label className={styles.option} key={account.id}>
              <input
                checked={selected.includes(account.id)}
                onChange={() => onToggle(account.id)}
                type="checkbox"
              />
              <span>
                {account.label} · {account.assetCode}
              </span>
            </label>
          ))}
          {selected.length > 0 ? (
            <button className={styles.clear} onClick={onClear} type="button">
              {t('categorizationRules.fields.accountScopeClear')}
            </button>
          ) : null}
        </div>
      </div>
      <small className={styles.hint} id={hintId}>
        {t('categorizationRules.fields.accountScopeHint')}
      </small>
    </div>
  );
}
