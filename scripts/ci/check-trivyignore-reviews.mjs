// Review-date gate for .trivyignore. Malformed or missing metadata always fails (fail closed); a
// well-formed date outside [today, today + 90 days] is a warning, and a failure in strict mode
// (nightly scan), so an unchanged file never breaks an ordinary pull request.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const WINDOW_DAYS = 90;
const DAY_MS = 86_400_000;

function utcDay(date) {
  return Math.floor(date.getTime() / DAY_MS);
}

// Day number of a real Gregorian YYYY-MM-DD date, or null.
function parseReviewDate(text) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(text);
  if (!match) {
    return null;
  }
  const [year, month, day] = match.slice(1).map(Number);
  const date = new Date(Date.UTC(year, month - 1, day));
  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== month - 1 ||
    date.getUTCDate() !== day
  ) {
    return null;
  }
  return utcDay(date);
}

// A block is a comment run followed by CVE lines, closed by a blank line or by a new comment.
function parseBlocks(content) {
  const blocks = [];
  let current = { reviews: [], cves: [] };
  const close = () => {
    if (current.cves.length > 0) {
      blocks.push(current);
    }
    current = { reviews: [], cves: [] };
  };
  for (const raw of content.split('\n')) {
    const line = raw.trim();
    if (line === '') {
      close();
    } else if (line.startsWith('#')) {
      if (current.cves.length > 0) {
        close();
      }
      const review = /^# review:\s*(.*)$/.exec(line);
      if (review) {
        current.reviews.push(review[1].trim());
      }
    } else {
      current.cves.push(line);
    }
  }
  close();
  return blocks;
}

export function checkTrivyIgnore(content, { today, strict = false }) {
  const errors = [];
  const warnings = [];
  const todayDay = utcDay(today);

  for (const block of parseBlocks(content)) {
    const cves = block.cves.join(', ');
    if (block.reviews.length !== 1) {
      errors.push(
        `${cves}: .trivyignore needs exactly one "# review: YYYY-MM-DD" line in its block (found ${block.reviews.length})`,
      );
      continue;
    }
    const day = parseReviewDate(block.reviews[0]);
    if (day === null) {
      errors.push(`${cves}: review date "${block.reviews[0]}" is not a valid YYYY-MM-DD date`);
      continue;
    }
    if (day < todayDay || day - todayDay > WINDOW_DAYS) {
      const message = `${cves}: review ${block.reviews[0]} is outside the window from today to ${WINDOW_DAYS} days ahead`;
      (strict ? errors : warnings).push(message);
    }
  }
  return { errors, warnings };
}

export function reportTrivyIgnore(content, options) {
  const { errors, warnings } = checkTrivyIgnore(content, options);
  warnings.forEach((message) => console.warn(`::warning::${message}`));
  errors.forEach((message) => console.error(message));
  return errors.length === 0;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const strict = process.argv.includes('--strict');
  const path =
    process.argv.slice(2).find((argument) => !argument.startsWith('--')) ??
    fileURLToPath(new URL('../../.trivyignore', import.meta.url));
  const valid = reportTrivyIgnore(readFileSync(path, 'utf8'), { today: new Date(), strict });
  process.exit(valid ? 0 : 1);
}
