import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import {
  allocationEmpty,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';
import { SupportCards } from './support-cards/SupportCards';
import { supportCells, supportHeaders } from './supportPresentation';
import styles from './SupportsTable.module.css';
export function SupportsTable({ summary }: { summary: Summary | null }) {
  const empty = summary && summary.supports.length === 0;
  return (
    <section className={`card ${styles.card}`} aria-busy={!summary}>
      <h3>Supports du contrat</h3>
      {empty ? (
        <p>
          {summary.allocation.status === 'AVAILABLE'
            ? 'Aucun support détenu à cette date.'
            : allocationEmpty[summary.allocation.status]}
        </p>
      ) : (
        <>
          <div className={styles.desktop}>
            <div
              className={styles.scroll}
              role="region"
              aria-label="Supports détaillés"
              tabIndex={0}
            >
              <table>
                <caption className="sr-only">Supports du contrat</caption>
                <thead>
                  <tr>
                    {supportHeaders.map((label) => (
                      <th key={label} scope="col">
                        {label}
                      </th>
                    ))}
                  </tr>
                </thead>
                {!summary ? (
                  <SkeletonTableRows columns={supportHeaders.length} />
                ) : (
                  <tbody>
                    {summary.supports.map((row) => (
                      <tr key={row.instrumentId}>
                        {supportCells(row, summary.assetCode).map((cell, index) =>
                          index === 0 ? (
                            <th scope="row" key={index}>
                              {cell}
                            </th>
                          ) : (
                            <td className={index >= 2 ? 'money' : undefined} key={index}>
                              {cell}
                            </td>
                          ),
                        )}
                      </tr>
                    ))}
                  </tbody>
                )}
              </table>
            </div>
          </div>
          <SupportCards summary={summary} />
        </>
      )}
    </section>
  );
}
