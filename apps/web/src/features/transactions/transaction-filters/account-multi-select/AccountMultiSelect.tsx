import type { Account } from '@cadran/api-client';
import { useId, useRef, useState, type KeyboardEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './AccountMultiSelect.module.css';

interface AccountMultiSelectProps {
  accounts: Account[];
  label: string;
  onChange: (next: string[]) => void;
  value: string[];
}

/**
 * Picks any number of accounts through a searched, checked list rather than a native
 * `<select multiple>`: a workspace can hold more accounts than fit on screen, and the
 * native control hides its own affordance behind a modifier key the label never explains.
 */
export function AccountMultiSelect({ accounts, label, onChange, value }: AccountMultiSelectProps) {
  const { t } = useTranslation();
  const inputId = useId();
  const listId = useId();
  const blurTimeout = useRef<number | undefined>(undefined);
  const [query, setQuery] = useState('');
  const [expanded, setExpanded] = useState(false);
  const [activeIndex, setActiveIndex] = useState(0);

  const matches = accounts.filter((account) =>
    account.label.toLowerCase().includes(query.trim().toLowerCase()),
  );
  const active = matches[Math.min(activeIndex, Math.max(matches.length - 1, 0))];

  function toggle(accountId: string) {
    onChange(
      value.includes(accountId)
        ? value.filter((candidate) => candidate !== accountId)
        : [...value, accountId],
    );
  }

  function close() {
    setExpanded(false);
    setQuery('');
    setActiveIndex(0);
  }

  function onKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === 'Escape' && expanded) {
      event.preventDefault();
      close();
      return;
    }

    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      event.preventDefault();
      if (!expanded) {
        setExpanded(true);
        return;
      }

      const step = event.key === 'ArrowDown' ? 1 : -1;
      setActiveIndex((current) => {
        const next = current + step;
        return next < 0 ? matches.length - 1 : next >= matches.length ? 0 : next;
      });
      return;
    }

    if (event.key === 'Enter' && expanded && active) {
      event.preventDefault();
      toggle(active.id);
    }
  }

  return (
    <div className={styles.picker}>
      <div className={styles.labelRow}>
        <label htmlFor={inputId}>{label}</label>
        {value.length > 0 ? (
          <span aria-hidden="true" className={styles.count}>
            {value.length}
          </span>
        ) : null}
      </div>

      <div className={styles.control}>
        <input
          aria-activedescendant={expanded && active ? `${listId}-${active.id}` : undefined}
          aria-autocomplete="list"
          aria-controls={listId}
          aria-expanded={expanded}
          autoComplete="off"
          id={inputId}
          maxLength={80}
          onBlur={() => {
            // A pointer selection lands after the blur, so the list stays open long
            // enough for the click on it to register.
            blurTimeout.current = window.setTimeout(close, 120);
          }}
          onChange={(event) => {
            setQuery(event.target.value);
            setExpanded(true);
            setActiveIndex(0);
          }}
          onFocus={() => {
            window.clearTimeout(blurTimeout.current);
            setExpanded(true);
          }}
          onKeyDown={onKeyDown}
          placeholder={t('transactions.filters.accountSearchPlaceholder')}
          role="combobox"
          type="text"
          value={query}
        />

        {expanded && matches.length > 0 ? (
          <ul
            aria-label={label}
            aria-multiselectable="true"
            className={styles.list}
            id={listId}
            role="listbox"
          >
            {matches.map((account, index) => {
              const selected = value.includes(account.id);
              return (
                <li
                  aria-selected={selected}
                  className={index === activeIndex ? styles.active : undefined}
                  id={`${listId}-${account.id}`}
                  key={account.id}
                  onMouseDown={(event) => {
                    // Keeps focus on the search field so the blur timer never races the choice.
                    event.preventDefault();
                    toggle(account.id);
                  }}
                  onMouseEnter={() => setActiveIndex(index)}
                  role="option"
                >
                  <input
                    aria-hidden="true"
                    checked={selected}
                    className={styles.checkbox}
                    readOnly
                    tabIndex={-1}
                    type="checkbox"
                  />
                  <span>{account.label}</span>
                </li>
              );
            })}
          </ul>
        ) : null}

        {expanded && matches.length === 0 ? (
          <p className={styles.empty} role="status">
            {t('transactions.filters.accountEmpty')}
          </p>
        ) : null}
      </div>
    </div>
  );
}
