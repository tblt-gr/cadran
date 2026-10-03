import styles from './ValuationsTab.module.css';

/** Explanation and the recording action; disabled while the account or list is pending. */
export function ValuationsToolbar({
  disabled,
  onCreate,
}: {
  disabled: boolean;
  onCreate: () => void;
}) {
  return (
    <div className={styles.toolbar}>
      <p className={styles.note}>
        Une valorisation fige la valeur du compte à une date, d’après un relevé. Elle est publiée
        dans le patrimoine lorsqu’elle est complète.
      </p>
      <button className="secondary-action" disabled={disabled} onClick={onCreate} type="button">
        Enregistrer une valorisation
      </button>
    </div>
  );
}
