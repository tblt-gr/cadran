import { Modal } from '@/components/ui/modal/Modal';
import {
  figureLabels,
  methodSentences,
  type FigureKey,
} from '@/features/portfolio/performance/performanceFigures';
import styles from './SinceOpeningFigures.module.css';

/** The since-opening figures to show under the summary; each change applies at once. */
export function SinceOpeningSettings({
  offered,
  value,
  onChange,
  onClose,
}: {
  offered: readonly FigureKey[];
  value: readonly FigureKey[];
  onChange: (value: readonly FigureKey[]) => void;
  onClose: () => void;
}) {
  return (
    <Modal close={onClose} title="Indicateurs depuis le premier relevé">
      <fieldset className={styles.choices}>
        <legend>Afficher sous les indicateurs du compte</legend>
        {offered.map((key) => (
          <label key={key}>
            <input
              checked={value.includes(key)}
              onChange={(event) =>
                onChange(
                  event.target.checked ? [...value, key] : value.filter((item) => item !== key),
                )
              }
              type="checkbox"
            />
            <span>
              <strong>{figureLabels[key]}</strong>
              <small>{methodSentences[key]}</small>
            </span>
          </label>
        ))}
      </fieldset>
    </Modal>
  );
}
