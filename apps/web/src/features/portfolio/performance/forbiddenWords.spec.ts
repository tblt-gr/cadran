import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const roots = [__dirname];

function sources(directory: string): string[] {
  return readdirSync(directory).flatMap((entry) => {
    const path = join(directory, entry);
    if (statSync(path).isDirectory()) return sources(path);
    return /\.(tsx?|css)$/.test(entry) && !/\.spec\./.test(entry) ? [path] : [];
  });
}

describe('performance wording', () => {
  it('never promises a return', () => {
    const files = roots.flatMap(sources);
    expect(files.length).toBeGreaterThan(0);
    const offenders = files.filter((file) =>
      /garanti|rendement assuré/i.test(readFileSync(file, 'utf8')),
    );
    expect(offenders).toEqual([]);
  });
});
