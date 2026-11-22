/** The dial: Cadran's mark, drawn in the current text colour at whatever size its box gives it. */
export function BrandMark({ className }: { className?: string }) {
  return (
    <svg aria-hidden="true" className={className} viewBox="127 172 681 681">
      <path
        d="M727 297A304 304 0 1 0 727 727"
        fill="none"
        stroke="currentColor"
        strokeLinecap="round"
        strokeWidth="72"
      />
      <path
        d="M512 512L672 378"
        fill="none"
        stroke="currentColor"
        strokeLinecap="round"
        strokeWidth="60"
      />
      <circle cx="512" cy="512" fill="currentColor" r="40" />
    </svg>
  );
}
