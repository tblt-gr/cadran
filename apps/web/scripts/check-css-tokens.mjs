import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const webRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const stylesRoot = path.join(webRoot, 'src');
const variablesPath = path.join(stylesRoot, 'styles', 'variables.css');

async function collectCssFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = await Promise.all(
    entries.map(async (entry) => {
      const entryPath = path.join(directory, entry.name);

      if (entry.isDirectory()) {
        return collectCssFiles(entryPath);
      }

      return entry.isFile() && entry.name.endsWith('.css') ? [entryPath] : [];
    }),
  );

  return files.flat();
}

const tokenizedProperties = [
  /^(?:margin|padding)(?:-.+)?$/,
  /^(?:gap|row-gap|column-gap)$/,
  /^(?:color|background|background-color|fill|stroke)$/,
  /^(?:font-size|font-weight|line-height|letter-spacing)$/,
  /^border(?:-(?!collapse).+)?$/,
  /^outline(?:-.+)?$/,
  /^box-shadow$/,
  /^(?:text-decoration-thickness|text-underline-offset)$/,
  /^(?:transition|transition-duration|animation|animation-duration)$/,
];

const allowedKeywords = new Set(['inherit', 'none', 'currentColor']);
const variablesSource = await readFile(variablesPath, 'utf8');
const definedTokens = new Set(
  [...variablesSource.matchAll(/^\s*(--[a-z0-9-]+)\s*:/gim)].map((match) => match[1]),
);
const errors = [];

for (const filePath of await collectCssFiles(stylesRoot)) {
  const source = await readFile(filePath, 'utf8');
  const relativePath = path.relative(webRoot, filePath);

  if (filePath !== variablesPath) {
    for (const match of source.matchAll(/^\s*(--[a-z0-9-]+)\s*:/gim)) {
      errors.push(
        `${relativePath}: custom property ${match[1]} must be declared in src/styles/variables.css`,
      );
    }

    const sourceWithoutComments = source.replace(/\/\*[\s\S]*?\*\//g, '');

    for (const match of sourceWithoutComments.matchAll(/^\s*([a-z-]+)\s*:\s*([^;]+);/gim)) {
      const [, property, rawValue] = match;
      const value = rawValue.trim();

      if (tokenizedProperties.some((pattern) => pattern.test(property))) {
        const usesToken = value.includes('var(');
        const isAllowedKeyword = allowedKeywords.has(value);
        const valueWithoutFunctions = value.replace(/(?:var|env)\([^)]*\)/g, '');
        const containsLiteralNumber = /(?:^|[^a-z-])-?\d*\.?\d+(?:[a-z%]+)?/i.test(
          valueWithoutFunctions,
        );

        if ((!usesToken && !isAllowedKeyword) || containsLiteralNumber) {
          errors.push(
            `${relativePath}: ${property} must use tokens exclusively from src/styles/variables.css`,
          );
        }
      }
    }

    if (/(?:#[0-9a-f]{3,8}\b|\b(?:rgb|hsl)a?\()/i.test(sourceWithoutComments)) {
      errors.push(`${relativePath}: literal colors must be declared in src/styles/variables.css`);
    }
  }

  for (const match of source.matchAll(/var\((--[a-z0-9-]+)\)/gim)) {
    if (!definedTokens.has(match[1])) {
      errors.push(`${relativePath}: ${match[1]} is not declared in src/styles/variables.css`);
    }
  }
}

if (errors.length > 0) {
  console.error(
    ['CSS token validation failed:', ...errors.map((error) => `- ${error}`)].join('\n'),
  );
  process.exitCode = 1;
} else {
  console.log('CSS token validation passed.');
}
