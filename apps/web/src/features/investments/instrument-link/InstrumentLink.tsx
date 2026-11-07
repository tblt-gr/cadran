import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './InstrumentLink.module.css';

/** Native anchor semantics preserve modified clicks and keyboard navigation. */
export function InstrumentLink({ instrument }: { instrument: { id: string; name: string } }) {
  const href = `/investments/instruments/${encodeURIComponent(instrument.id)}`;
  return (
    <a
      className={styles.link}
      href={href}
      aria-label={`Voir l'instrument ${instrument.name}`}
      onClick={(event) => handleClientNavigation(event, href)}
    >
      {instrument.name}
    </a>
  );
}
