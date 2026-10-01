import { listInstruments, type Instrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useId, useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './InstrumentPicker.module.css';

interface Props {
  currencyCode: string;
  label: string;
  onChange: (instrument: Instrument | null) => void;
  value?: Instrument | null;
}
export function InstrumentPicker({ currencyCode, label, onChange, value = null }: Props) {
  const id = useId();
  const [text, setText] = useState(value?.name ?? '');
  const [query, setQuery] = useState('');
  const [open, setOpen] = useState(false);
  const [active, setActive] = useState(0);
  useEffect(() => {
    const timer = window.setTimeout(() => setQuery(text), 250);
    return () => window.clearTimeout(timer);
  }, [text]);
  useEffect(() => {
    setActive(0);
  }, [currencyCode, query]);
  const result = useQuery({
    queryKey: ['instrument-picker', currencyCode, query],
    enabled: open,
    queryFn: async ({ signal }) => {
      const response = await listInstruments({
        ...authApiOptions(),
        query: { status: 'active', perPage: 20, q: query || undefined, currencyCode },
        signal,
      });
      if (!response.response?.ok || !response.data) throw new Error('Instrument picker failed');
      return response.data.items;
    },
    retry: false,
  });
  const items = result.data ?? [];
  function choose(item: Instrument) {
    setText(item.name);
    setOpen(false);
    onChange(item);
  }
  return (
    <div className={styles.field}>
      <span id={`${id}-label`}>{label}</span>
      <input
        aria-labelledby={`${id}-label`}
        aria-activedescendant={open && items[active] ? `${id}-${items[active].id}` : undefined}
        aria-autocomplete="list"
        aria-controls={id}
        aria-expanded={open}
        autoComplete="off"
        onChange={(e) => {
          setText(e.target.value);
          setOpen(true);
          onChange(null);
        }}
        onBlur={() => setOpen(false)}
        onFocus={() => setOpen(true)}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((current) => Math.min(current + 1, Math.max(0, items.length - 1)));
          } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((current) => Math.max(0, current - 1));
          } else if (event.key === 'Enter' && items[active]) {
            event.preventDefault();
            choose(items[active]);
          } else if (event.key === 'Escape' && open) {
            event.stopPropagation();
            setOpen(false);
          }
        }}
        role="combobox"
        value={text}
      />
      <div aria-live="polite">
        {open && result.isPending ? <p>Chargement…</p> : null}
        {open && result.isError ? (
          <p role="alert">
            La recherche est indisponible.{' '}
            <button
              className="secondary-action"
              onClick={() => void result.refetch()}
              type="button"
            >
              Réessayer
            </button>
          </p>
        ) : null}
        {open && result.isSuccess && items.length === 0 ? (
          <p>
            Aucun instrument trouvé.{' '}
            <a
              href="/portfolios/instruments"
              onClick={(event) => handleClientNavigation(event, '/portfolios/instruments')}
            >
              Gérer les instruments
            </a>
          </p>
        ) : null}
      </div>
      {open && items.length ? (
        <ul className={styles.options} id={id} role="listbox" aria-labelledby={`${id}-label`}>
          {items.map((item, index) => (
            <li
              aria-selected={index === active}
              id={`${id}-${item.id}`}
              key={item.id}
              onMouseDown={() => choose(item)}
              role="option"
            >
              {item.name} · {item.isin ?? 'sans ISIN'} · {item.ticker ?? 'sans ticker'}
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  );
}
