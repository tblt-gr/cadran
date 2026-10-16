#!/bin/sh
set -eu
# A fixture root can be supplied by the checker tests. No documented command is executed.
node --input-type=module - "${1:-.}" <<'JS'
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
const root = process.argv[2];
const makefile = readFileSync(resolve(root, 'Makefile'), 'utf8');
const targets = new Set();
for (const line of makefile.split('\n')) {
  const rule = line.match(/^([A-Za-z0-9_. -]+):(?!=)/);
  if (rule) for (const name of rule[1].trim().split(/\s+/)) {
    if (!name.startsWith('.')) targets.add(name);
  }
}
let failed = false;
for (const file of ['README.md', 'CONTRIBUTING.md']) {
  const source = readFileSync(resolve(root, file), 'utf8');
  // Commands live in fenced examples or inline code, not ordinary prose using the verb make.
  for (const example of source.matchAll(/```[^\n]*\n([\s\S]*?)```|`([^`\n]+)`/g)) {
    const code = example[1] ?? example[2];
    const start = example.index + example[0].indexOf(code);
    // Replace a continuation with spaces of equal length to preserve source line numbers.
    const logical = code.replace(/\\\n[ \t]*/g, (value) => ' '.repeat(value.length));
    for (const command of logical.matchAll(/\bmake[ \t]+([^\n`;&|<>]*)/g)) {
      const line = source.slice(0, start + command.index).split('\n').length;
      const tokens = command[1].match(/(?:[^\s"']+|"[^"\n]*"|'[^'\n]*')+/g) ?? [];
      for (const token of tokens) {
        if (token.startsWith('#')) break;
        if (token.startsWith('-') || /^[A-Za-z_][A-Za-z0-9_]*=/.test(token)) continue;
        if (!targets.has(token)) {
          console.error(`${file}:${line}: unknown make target "${token}"`);
          failed = true;
        }
      }
    }
  }
}
if (failed) process.exit(1);
console.log('Documented make targets exist.');
JS
