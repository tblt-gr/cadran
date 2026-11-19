import type { PortfolioOverviewSummary } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { reviewGuide, tabLinkLabel } from './reviewGuide';
import styles from './ReconciliationReview.module.css';

type Warning = PortfolioOverviewSummary['reconciliation']['warnings'][number];

/**
 * The points behind “To check”, opened from the Reconciliation pill: each says what is wrong,
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
  const { t } = useTranslation();
  return (
    <Modal
      intent="detail"
      close={onClose}
      title={t('portfolio.account.review.title', { count: warnings.length })}
    >
      <ul className={styles.review}>
        {warnings.map((warning) => {
          const guide = reviewGuide(t, warning.code, warning.count);
          const href = `/portfolios/${accountId}?tab=${guide.tab}&asOf=${asOf}`;
          return (
            <li key={warning.code}>
              <strong>{guide.title}</strong>
              {guide.hint ? <p>{guide.hint}</p> : null}
              <a
                className="text-action"
                href={href}
                onClick={(event) => {
                  onClose();
                  handleClientNavigation(event, href);
                }}
              >
                {tabLinkLabel(t, guide.tab)}
              </a>
            </li>
          );
        })}
      </ul>
    </Modal>
  );
}
