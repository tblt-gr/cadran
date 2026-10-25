import { useId, useRef, useState, type KeyboardEvent, type ReactNode } from 'react';
import styles from './Tabs.module.css';

interface TabsItem {
  id: string;
  label: string;
  content: ReactNode;
}

interface TabsProps {
  /** Accessible name of the tab list. */
  label: string;
  tabs: readonly TabsItem[];
}

/**
 * In-page tabs: one tab list with a roving tabindex and arrow keys, and one panel per tab. Every
 * panel stays mounted (hidden when inactive), so form state inside a panel survives a tab change.
 */
export function Tabs({ label, tabs }: TabsProps) {
  const baseId = useId();
  const [activeId, setActiveId] = useState(tabs[0]?.id);
  const refs = useRef<Record<string, HTMLButtonElement | null>>({});

  function move(event: KeyboardEvent, index: number) {
    const last = tabs.length - 1;
    const target =
      event.key === 'ArrowRight'
        ? index === last
          ? 0
          : index + 1
        : event.key === 'ArrowLeft'
          ? index === 0
            ? last
            : index - 1
          : event.key === 'Home'
            ? 0
            : event.key === 'End'
              ? last
              : null;
    if (target === null) return;
    event.preventDefault();
    const next = tabs[target]!;
    setActiveId(next.id);
    refs.current[next.id]?.focus();
  }

  return (
    <div className={styles.tabs}>
      <div aria-label={label} className={styles.list} role="tablist">
        {tabs.map((tab, index) => (
          <button
            aria-controls={`${baseId}-panel-${tab.id}`}
            aria-selected={tab.id === activeId}
            className={styles.tab}
            id={`${baseId}-tab-${tab.id}`}
            key={tab.id}
            onClick={() => setActiveId(tab.id)}
            onKeyDown={(event) => move(event, index)}
            ref={(element) => {
              refs.current[tab.id] = element;
            }}
            role="tab"
            tabIndex={tab.id === activeId ? 0 : -1}
            type="button"
          >
            {tab.label}
          </button>
        ))}
      </div>
      {tabs.map((tab) => (
        <div
          aria-labelledby={`${baseId}-tab-${tab.id}`}
          className={styles.panel}
          hidden={tab.id !== activeId}
          id={`${baseId}-panel-${tab.id}`}
          key={tab.id}
          role="tabpanel"
        >
          {tab.content}
        </div>
      ))}
    </div>
  );
}
