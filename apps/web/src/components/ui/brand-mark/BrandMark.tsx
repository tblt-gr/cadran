/** The dial: Cadran's mark, drawn in the current text colour at whatever size its box gives it. */
export function BrandMark({ className }: { className?: string }) {
  return (
    <svg aria-hidden="true" className={className} viewBox="0 0 40 40">
      <circle cx="20" cy="20" fill="none" r="15" stroke="currentColor" strokeWidth="2" />
      <path
        d="M20 8v5M20 27v5M8 20h5M27 20h5M20 20l7-7"
        stroke="currentColor"
        strokeLinecap="round"
        strokeWidth="2"
      />
      <circle cx="20" cy="20" fill="currentColor" r="2.5" />
    </svg>
  );
}
