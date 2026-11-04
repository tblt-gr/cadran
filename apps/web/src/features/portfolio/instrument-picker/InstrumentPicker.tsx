import { listInstruments, type Instrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { InstrumentEditorModal } from '@/features/portfolio/instruments/instrument-editor-modal/InstrumentEditorModal';
import { InstrumentPickerSkeleton } from './InstrumentPickerSkeleton';
import styles from './InstrumentPicker.module.css';

interface Props {
  allowCreate?: boolean;
  instrumentType?: Instrument['instrumentType'];
  currencyCode: string;
  label: string;
  onChange: (instrument: Instrument | null) => void;
  value?: Pick<Instrument, 'name'> | null;
}
export function InstrumentPicker({
  allowCreate = false,
  currencyCode,
  instrumentType,
  label,
  onChange,
  value = null,
}: Props) {
  const { t } = useTranslation();
  const id = useId();
  const input = useRef<HTMLInputElement>(null);
  const [creationText, setCreationText] = useState<string | null>(null);
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
  }, [currencyCode, instrumentType, query]);
  const result = useQuery({
    queryKey: ['instrument-picker', currencyCode, instrumentType, query],
    enabled: open,
    queryFn: async ({ signal }) => {
      const response = await listInstruments({
        ...authApiOptions(),
        query: {
          status: 'active',
          pageSize: 20,
          q: query || undefined,
          currencyCode,
          ...(instrumentType ? { instrumentType } : {}),
        },
        signal,
      });
      if (!response.response?.ok || !response.data) throw new Error('Instrument picker failed');
      return response.data.items;
    },
    retry: false,
  });
  const items = (result.data ?? []).filter(
    (item) => !instrumentType || item.instrumentType === instrumentType,
  );
  const trimmed = text.trim();
  const canCreate =
    allowCreate &&
    result.isSuccess &&
    !result.isFetching &&
    query === text &&
    trimmed !== '' &&
    !items.some((item) =>
      [item.name, item.isin, item.ticker].some(
        (value) => value?.toLocaleLowerCase() === trimmed.toLocaleLowerCase(),
      ),
    );
  const optionCount = items.length + (canCreate ? 1 : 0);
  const activeId = result.isError
    ? undefined
    : items[active]
      ? `${id}-${items[active].id}`
      : canCreate && active === items.length
        ? `${id}-create`
        : undefined;
  function create() {
    setOpen(false);
    setCreationText(trimmed);
  }
  const isinText = creationText?.toUpperCase();
  const isIsin = Boolean(isinText && /^[A-Z]{2}[A-Z0-9]{9}[0-9]$/.test(isinText));
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
        aria-activedescendant={open ? activeId : undefined}
        ref={input}
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
        onFocus={() => {
          if (creationText === null) setOpen(true);
        }}
        onKeyDown={(event) => {
          if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((current) => Math.min(current + 1, Math.max(0, optionCount - 1)));
          } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((current) => Math.max(0, current - 1));
          } else if (event.key === 'Enter' && open && activeId) {
            event.preventDefault();
            if (items[active]) choose(items[active]);
            else create();
          } else if (event.key === 'Escape' && open) {
            event.stopPropagation();
            setOpen(false);
          }
        }}
        role="combobox"
        value={text}
      />
      <div aria-live="polite" className={styles.status}>
        {open && result.isPending && result.data === undefined ? (
          <InstrumentPickerSkeleton status={t('portfolio.instrumentPicker.loading')} />
        ) : null}
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
        {open && result.isSuccess && items.length === 0 ? <p>Aucun instrument trouvé.</p> : null}
      </div>
      {open && optionCount > 0 && !result.isError ? (
        <ul className={styles.options} id={id} role="listbox" aria-labelledby={`${id}-label`}>
          {items.map((item, index) => (
            <li
              aria-selected={index === active}
              id={`${id}-${item.id}`}
              key={item.id}
              onMouseDown={(event) => {
                event.preventDefault();
                choose(item);
              }}
              role="option"
            >
              {item.name} · {item.isin ?? 'sans ISIN'} · {item.ticker ?? 'sans ticker'}
            </li>
          ))}
          {canCreate ? (
            <li
              aria-selected={active === items.length}
              id={`${id}-create`}
              role="option"
              onMouseDown={(event) => {
                event.preventDefault();
                create();
              }}
            >
              Créer « {trimmed} »
            </li>
          ) : null}
        </ul>
      ) : null}
      {creationText !== null ? (
        <InstrumentEditorModal
          initial={{
            name: isIsin ? undefined : creationText,
            isin: isIsin ? isinText : undefined,
            currencyCode,
            instrumentType,
          }}
          lock={{ currencyCode: true, instrumentType: Boolean(instrumentType) }}
          returnFocus={input}
          onClose={() => setCreationText(null)}
          onSaved={choose}
        />
      ) : null}
    </div>
  );
}
