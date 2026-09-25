#!/usr/bin/env node
// Every state-changing operation under /api/v1 must enforce and document the
// CSRF guard: the server checks it globally (CsrfProtectionListener), but the
// contract is the only place a client or reviewer can see that a POST, PUT,
// PATCH or DELETE actually requires the X-CSRF-TOKEN header. A new endpoint
// that forgets the parameter would silently under-document a live control,
// so this gate fails the build instead of leaving it to a later audit.
import { readFileSync } from 'node:fs';

const MUTATING_METHODS = new Set(['post', 'put', 'patch', 'delete']);
const CSRF_PARAMETER_REF = "$ref: '#/components/parameters/CsrfToken'";

// Operations that intentionally take no CSRF token because the caller cannot
// yet hold a session-bound `csrf_token` cookie signed by this workspace, or
// because they exist to explain that very refusal. Keep this list short and
// name the exact reason inline; it is reviewed by the same PR gate.
const EXEMPT_OPERATION_IDS = new Set([]);

/**
 * @param {string} source raw OpenAPI YAML text
 * @returns {{ path: string, method: string, operationId: string | null }[]} violations
 */
export function findOperationsMissingCsrfToken(source) {
  const lines = source.split('\n');
  const pathLine = /^ {2}(\/\S*):\s*$/;
  const methodLine = /^ {4}(get|post|put|patch|delete):\s*$/;
  const operationIdLine = /^ {6}operationId:\s*(\S+)\s*$/;

  const violations = [];
  let currentPath = null;
  let inOperation = false;
  let operationId = null;
  let operationHasCsrfToken = false;

  const closeOperation = (method) => {
    if (!inOperation || !currentPath || !method) {
      return;
    }
    if (MUTATING_METHODS.has(method) && !operationHasCsrfToken) {
      if (!operationId || !EXEMPT_OPERATION_IDS.has(operationId)) {
        violations.push({ path: currentPath, method, operationId });
      }
    }
  };

  let currentMethod = null;
  for (const line of lines) {
    if (!/^\s/.test(line) && line.trim() !== '') {
      // Back at column zero: `paths:` is over.
      closeOperation(currentMethod);
      currentPath = null;
      inOperation = false;
      currentMethod = null;
      continue;
    }

    const pathMatch = pathLine.exec(line);
    if (pathMatch) {
      closeOperation(currentMethod);
      currentPath = pathMatch[1];
      inOperation = false;
      currentMethod = null;
      continue;
    }

    if (null === currentPath) {
      continue;
    }

    const methodMatch = methodLine.exec(line);
    if (methodMatch) {
      closeOperation(currentMethod);
      currentMethod = methodMatch[1];
      inOperation = true;
      operationId = null;
      operationHasCsrfToken = false;
      continue;
    }

    if (!inOperation) {
      continue;
    }

    const operationIdMatch = operationIdLine.exec(line);
    if (operationIdMatch) {
      operationId = operationIdMatch[1];
    }

    if (line.includes(CSRF_PARAMETER_REF)) {
      operationHasCsrfToken = true;
    }
  }
  closeOperation(currentMethod);

  return violations.filter((violation) => violation.path.startsWith('/api/v1'));
}

function main() {
  const target = process.argv[2] ?? 'apps/api/openapi/openapi.yaml';
  const source = readFileSync(target, 'utf8');
  const violations = findOperationsMissingCsrfToken(source);

  if (violations.length > 0) {
    for (const violation of violations) {
      console.error(
        `${violation.method.toUpperCase()} ${violation.path}` +
          (violation.operationId ? ` (${violation.operationId})` : '') +
          " is missing the '#/components/parameters/CsrfToken' parameter.",
      );
    }
    console.error(
      `\n${violations.length} mutating /api/v1 operation(s) do not document the CSRF guard.`,
    );
    process.exit(1);
  }

  console.log('Every mutating /api/v1 operation documents the CSRF guard.');
}

main();
