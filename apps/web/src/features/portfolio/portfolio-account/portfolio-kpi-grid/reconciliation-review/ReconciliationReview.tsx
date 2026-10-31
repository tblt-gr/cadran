import type { PortfolioOverviewSummary } from '@cadran/api-client';
import { Modal } from '@/components/ui/modal/Modal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { reviewGuide, tabLinkLabels } from './reviewGuide';
import styles from './ReconciliationReview.module.css';

type Warning = PortfolioOverviewSummary['reconciliation']['warnings'][number];

/**
 * The points behind « À vérifier », opened from the Rapprochement pill: each says what is wrong,
 * what to do and links to the tab where it is fixed.
 */
export function ReconciliationReview({
  accountId,
  asOf,
  warnings,
  onClose,
}: {
  accountId: string;
  asOf: string;
  warnings: readonly Warning[];
  onClose: () => void;
}) {
  return (
    <Modal
      close={onClose}
      title={warnings.length === 1 ? '1 point à vérifier' : `${warnings.length} points à vérifier`}
    >
      <ul className={styles.review}>
        {warnings.map((warning) => {
          const guide = reviewGuide(warning.code);
          const href = `/portfolios/${accountId}?tab=${guide.tab}&asOf=${asOf}`;
          return (
            <li key={warning.code}>
              <strong>{guide.title(warning.count)}</strong>
              {guide.hint ? <p>{guide.hint}</p> : null}
              <a
                className="text-action"
                href={href}
                onClick={(event) => {
                  onClose();
                  handleClientNavigation(event, href);
                }}
              >
                {tabLinkLabels[guide.tab]}
              </a>
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}
