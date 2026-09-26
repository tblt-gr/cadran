import { useEffect, useRef, useState } from 'react';

/** Rounded-down pixel size of an element, kept up to date while it is resized. */
export function useBoxSize() {
  const ref = useRef<HTMLDivElement>(null);
  const [size, setSize] = useState({ height: 0, width: 0 });

  useEffect(() => {
    const node = ref.current;
    if (node === null) {
      return;
    }

    function measure() {
      const box = node?.getBoundingClientRect();
      if (box === undefined) {
        return;
      }

      setSize({ height: Math.floor(box.height), width: Math.floor(box.width) });
    }

    measure();
    if (typeof ResizeObserver === 'undefined') {
      return;
    }

    const observer = new ResizeObserver(measure);
    observer.observe(node);
    return () => observer.disconnect();
  }, []);

  return { ref, size };
}
