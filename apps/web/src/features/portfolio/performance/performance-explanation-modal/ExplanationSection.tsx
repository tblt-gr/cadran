import { useId, type ReactNode } from 'react';

/** A titled region of the explanation, so each section is reachable by name. */
export function ExplanationSection({ title, children }: { title: string; children: ReactNode }) {
  const id = useId();
  return (
    <section aria-labelledby={id}>
      <h3 id={id}>{title}</h3>
      {children}
    </section>
  );
}
