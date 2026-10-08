import { useState, type RefObject } from 'react';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { capturePortfolioSource } from '@/features/portfolio/portfolio-account/portfolioDetailLocation';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatCalendarNumericDay } from '@/lib/decimal';
import {
  sourceHref,
  type Summary,
} from '@/features/portfolio/life-insurance/overview/summaryPresentation';

export function CumulativeSources({
  summary,
  accountId,
  sourceReturnFocus,
}: {
  summary: Summary | null;
  accountId: string;
  sourceReturnFocus?: RefObject<HTMLElement | null>;
}) {
  const [open, setOpen] = useState(false);
  const cumulative = summary?.cumulative;

  return (
    <Disclosure title="Sources des montants cumulés" open={open} onToggle={setOpen}>
      {cumulative && summary ? (
        <>
          {cumulative.sources.length ? (
            <ul>
              {cumulative.sources.map((source) => {
                const href = sourceHref(accountId, summary.asOf, source.kind, source.id);
                return (
                  <li key={`${source.kind}-${source.id}`}>
                    <a
                      href={href}
                      onClick={(event) => {
                        capturePortfolioSource(event.currentTarget, sourceReturnFocus);
                        handleClientNavigation(event, href);
                      }}
                    >
                      {source.kind === 'OPERATION' ? 'Opération' : 'Valorisation'} du{' '}
                      {formatCalendarNumericDay(source.date)}
                    </a>
                  </li>
                );
              })}
            </ul>
          ) : (
            <p>Aucune source à cette date.</p>
          )}
          {cumulative.sourceCount > cumulative.sources.length ? (
            <p>{cumulative.sourceCount - cumulative.sources.length} autres sources</p>
          ) : null}
        </>
      ) : null}
    </Disclosure>
  );
}
