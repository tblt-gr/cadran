/**
 * Breaks a caption at spaces into at most `maxLines` lines of `maxChars` characters, so it stays
 * inside the donut hole. A word longer than a line is cut; text beyond the last line ends with
 * an ellipsis.
 */
export function wrapCaption(text: string, maxChars: number, maxLines: number): string[] {
  const width = Math.max(1, Math.floor(maxChars));
  const lines: string[] = [];
  let current = '';

  for (const word of text.split(/\s+/).filter((part) => part !== '')) {
    let rest = word;
    while (rest.length > width) {
      if (current !== '') {
        lines.push(current);
        current = '';
      }
      lines.push(rest.slice(0, width));
      rest = rest.slice(width);
    }
    if (current === '') {
      current = rest;
    } else if (current.length + 1 + rest.length <= width) {
      current = `${current} ${rest}`;
    } else {
      lines.push(current);
      current = rest;
    }
  }
  if (current !== '') {
    lines.push(current);
  }

  if (lines.length <= maxLines) {
    return lines;
  }

  const kept = lines.slice(0, maxLines);
  const last = kept[maxLines - 1] ?? '';
  kept[maxLines - 1] = `${last.slice(0, Math.max(0, width - 1))}…`;

  return kept;
}
