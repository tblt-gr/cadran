<?php

declare(strict_types=1);

/**
 * Makes a missing workspace filter visible before it ships.
 *
 * Every business record belongs to a workspace, and every query that touches
 * one must constrain it. The guard reads the migrations to learn which tables
 * carry a `workspace_id`, then requires each method that names one of those
 * tables in SQL to also name `workspace_id`, and to live in an Infrastructure
 * layer where persistence belongs. It also restricts WorkspaceScope creation
 * to the explicit membership-resolution and provisioning trust boundaries.
 *
 * The check is deliberately coarse: it proves a workspace predicate was
 * written, not that it is correct. Correctness is the job of the positive and
 * negative isolation tests; this guard exists so a new module cannot forget the
 * predicate entirely.
 */

/**
 * Queries that resolve the caller's own scope, and therefore cannot already be
 * filtered by it. Adding an entry here is a security decision and must stay
 * visible in review.
 */
const SCOPE_RESOLUTION_QUERIES = [
    'App\\Module\\Identity\\Infrastructure\\Persistence\\DbalWorkspaceMembershipReader::findForUser' => 'Turns an authenticated user into the workspace every other query is then filtered by.',
    'App\\Module\\Transactions\\Infrastructure\\Persistence\\DbalIdempotencyKeyRepository::purgeExpired' => 'Retention maintenance run by a console command with no caller workspace; it must retire expired keys across every workspace.',
];

/**
 * WorkspaceScope is intentionally constructible for hydration and initial
 * provisioning, but application code must not turn request data into one.
 * Keeping the only production call sites explicit makes provenance reviewable.
 */
const TRUSTED_SCOPE_CREATION_METHODS = [
    'App\\Module\\Identity\\Application\\ProvisionInitialWorkspace::audit' => 'Creates the scope from the workspace identifier generated during one-time provisioning.',
    'App\\Module\\Identity\\Infrastructure\\Persistence\\DbalWorkspaceMembershipReader::findForUser' => 'Hydrates the scope resolved from the authenticated caller membership.',
];

/**
 * Persistence calls whose first argument is the SQL itself.
 */
const SQL_FIRST_CALLS = [
    'executecachequery', 'executequery', 'executestatement', 'fetchallassociative',
    'fetchallassociativeindexed', 'fetchallkeyvalue', 'fetchallnumeric', 'fetchassociative',
    'fetchfirstcolumn', 'fetchnumeric', 'fetchone', 'iterateassociative', 'iteratecolumn',
    'iteratekeyvalue', 'iteratenumeric', 'prepare', 'query',
];

/**
 * Persistence calls that name a table and express their restriction as data.
 * The value is the argument index holding the criteria, or null for an insert,
 * whose scope travels in the inserted values instead.
 */
const TABLE_FIRST_CALLS = [
    'insert' => null,
    'update' => 2,
    'delete' => 1,
];

// The discovered set is printable on its own so an integration test can hold it
// against information_schema on the migrated database. Reading DDL statically is
// a convenience for a check that must run without a database; that convenience
// is only safe while something proves the two agree.
if ('--list-tables' === ($argv[1] ?? null)) {
    $migrationsDirectory = $argv[2] ?? null;
    if (null === $migrationsDirectory || !is_dir($migrationsDirectory)) {
        fwrite(STDERR, "Usage: php scripts/check-workspace-scope.php --list-tables <migrations-directory>\n");
        exit(2);
    }

    foreach (scopedTables($migrationsDirectory) as $table) {
        fwrite(STDOUT, $table."\n");
    }

    exit(0);
}

$sourceDirectory = $argv[1] ?? null;
$migrationsDirectory = $argv[2] ?? null;

if (null === $sourceDirectory || !is_dir($sourceDirectory) || null === $migrationsDirectory || !is_dir($migrationsDirectory)) {
    fwrite(STDERR, "Usage: php scripts/check-workspace-scope.php <source-directory> <migrations-directory>\n");
    exit(2);
}

$scopedTables = scopedTables($migrationsDirectory);
if ([] === $scopedTables) {
    fwrite(STDERR, "No workspace-scoped table found; the guard would pass vacuously.\n");
    exit(2);
}

$violations = [];

foreach (phpFiles($sourceDirectory) as $file) {
    $path = str_replace('\\', '/', $file);
    $contents = read($file);
    $qualifiedClassName = qualifiedClassName($contents);

    foreach (methods($contents) as $method => $body) {
        $qualifiedMethod = $qualifiedClassName.'::'.$method;

        if ($body['createsWorkspaceScope'] && !array_key_exists($qualifiedMethod, TRUSTED_SCOPE_CREATION_METHODS)) {
            $violations[] = sprintf(
                '%s: %s() creates a WorkspaceScope outside a trusted resolution path.',
                $path,
                $method,
            );
        }

        $mentioned = tablesIn($body['literals'], $scopedTables);
        if ([] === $mentioned) {
            continue;
        }

        if (!str_contains($path, '/Infrastructure/')) {
            $violations[] = sprintf(
                '%s: %s() queries %s outside an Infrastructure layer; persistence does not belong here.',
                $path,
                $method,
                implode(', ', $mentioned),
            );
            continue;
        }

        if (array_key_exists($qualifiedMethod, SCOPE_RESOLUTION_QUERIES)) {
            continue;
        }

        $attributed = [];

        foreach ($body['statements'] as $statement) {
            if ('unrecognised' === $statement['kind']) {
                $tables = tablesIn($statement['sql'], $scopedTables);
                if ([] === $tables) {
                    continue;
                }

                // This occurrence must remain visible even when another call
                // in the same method safely reaches the very same table.
                $attributed = array_merge($attributed, $tables);
                $violations[] = sprintf(
                    '%s: %s() reaches %s through an unrecognised query path.',
                    $path,
                    $method,
                    implode(', ', $tables),
                );

                continue;
            }

            if ('sql' === $statement['kind']) {
                $tables = tablesIn($statement['sql'], $scopedTables);
                if ([] === $tables) {
                    continue;
                }

                $attributed = array_merge($attributed, $tables);
                $unconstrained = unconstrainedWorkspaceReferences($statement['sql'], $tables);
                if ([] !== $unconstrained) {
                    $violations[] = sprintf(
                        '%s: %s() touches %s without a workspace_id predicate.',
                        $path,
                        $method,
                        implode(', ', $unconstrained),
                    );
                }

                continue;
            }

            $table = strtolower(trim($statement['table']));
            if (!in_array($table, $scopedTables, true)) {
                continue;
            }

            $attributed[] = $table;

            // An insert carries the workspace in the values it writes; an
            // update or a delete must name it in its criteria, because a
            // workspace_id among the written values is the column being
            // changed, not a restriction on which rows are reached.
            if (!str_contains(strtolower($statement['criteria']), 'workspace_id')) {
                $violations[] = 'insert' === $statement['kind']
                    ? sprintf('%s: %s() inserts into %s without a workspace_id value.', $path, $method, $table)
                    : sprintf('%s: %s() %ss %s without a workspace_id in its criteria.', $path, $method, $statement['kind'], $table);
            }
        }

        $unattributed = array_values(array_diff($mentioned, $attributed));
        if ([] !== $unattributed) {
            // Fail closed: the table is named somewhere the guard cannot tie to
            // a recognised persistence call, so it cannot claim the query is
            // scoped either.
            $violations[] = sprintf(
                '%s: %s() reaches %s through an unrecognised query path.',
                $path,
                $method,
                implode(', ', $unattributed),
            );
        }
    }
}

if ([] !== $violations) {
    fwrite(STDERR, implode("\n", array_unique($violations))."\n");
    exit(1);
}

fwrite(STDOUT, sprintf(
    "Workspace scope is enforced on %d table(s): %s.\n",
    count($scopedTables),
    implode(', ', $scopedTables),
));

/**
 * @param list<string> $scopedTables
 *
 * @return list<string>
 */
function tablesIn(string $sql, array $scopedTables): array
{
    return array_values(array_filter(
        $scopedTables,
        static fn (string $table): bool => 1 === preg_match('/\b'.preg_quote($table, '/').'\b/i', $sql),
    ));
}

/**
 * Every scoped relation occurrence which is not restricted to a bound
 * workspace. Aliases are retained so one constrained join participant cannot
 * vouch for another occurrence of the same or a different scoped table.
 *
 * An INSERT carries the workspace among its columns. Every other verb needs an
 * equality against a bound value inside a restrictive clause: merely selecting
 * or writing workspace_id must never make an unscoped statement look safe. A
 * statement whose verb cannot even be read is refused rather than trusted.
 *
 * @param list<string> $scopedTables
 *
 * @return list<string>
 */
function unconstrainedWorkspaceReferences(string $sql, array $scopedTables): array
{
    $references = scopedTableReferences($sql, $scopedTables);
    if ([] === $references) {
        // A scoped table was found in SQL but not in a relation form the guard
        // understands. Refuse to let an unparsed shape pass as scoped.
        return $scopedTables;
    }

    $unconstrained = [];
    $qualifiers = array_column($references, 'qualifier');
    $allowUnqualified = 1 === count(array_unique($qualifiers));
    $availablePredicates = [];
    $consumedPredicates = [];

    foreach ($references as $reference) {
        $label = $reference['table'];
        if ($reference['qualifier'] !== $reference['table']) {
            $label .= ' '.$reference['qualifier'];
        }

        if ('into' === $reference['role'] && 1 === preg_match('/\bINSERT\s+INTO\b/i', $sql)) {
            if (!insertCarriesWorkspace($sql, $reference['table'])) {
                $unconstrained[] = $label;
            }
            continue;
        }

        $qualifier = $reference['qualifier'];
        $availablePredicates[$qualifier] ??= countBoundWorkspacePredicates($sql, $qualifier, $allowUnqualified);
        $consumedPredicates[$qualifier] ??= 0;

        if ($consumedPredicates[$qualifier] >= $availablePredicates[$qualifier]) {
            $unconstrained[] = $label;
            continue;
        }

        ++$consumedPredicates[$qualifier];
    }

    return array_values(array_unique($unconstrained));
}

/**
 * @param list<string> $scopedTables
 *
 * @return list<array{table: string, qualifier: string, role: string}>
 */
function scopedTableReferences(string $sql, array $scopedTables): array
{
    $identifier = '"?[a-z_][a-z0-9_]*"?';
    $reserved = 'FROM|WHERE|JOIN|INNER|LEFT|RIGHT|FULL|CROSS|ON|SET|VALUES|RETURNING|GROUP|ORDER|LIMIT|OFFSET|UNION|EXCEPT|INTERSECT|USING';
    preg_match_all(
        '/(?:\b(FROM|JOIN|UPDATE|INTO|USING)\b|(,))\s+(?:'.$identifier.'\s*\.\s*)?('.$identifier.')'.
        '(?:\s+(?:AS\s+)?(?!\b(?:'.$reserved.')\b)('.$identifier.'))?/i',
        $sql,
        $matches,
        PREG_SET_ORDER,
    );

    $references = [];
    foreach ($matches as $match) {
        $table = strtolower(trim($match[3], '"'));
        if (!in_array($table, $scopedTables, true)) {
            continue;
        }

        $alias = isset($match[4]) && '' !== $match[4] ? strtolower(trim($match[4], '"')) : $table;
        $references[] = [
            'table' => $table,
            'qualifier' => $alias,
            'role' => '' !== $match[1] ? strtolower($match[1]) : 'from',
        ];
    }

    return $references;
}

function countBoundWorkspacePredicates(string $sql, string $qualifier, bool $allowUnqualified): int
{
    $clause = '(?:WHERE|ON|HAVING)\b(?:(?!\b(?:GROUP\s+BY|ORDER\s+BY|LIMIT|RETURNING|UNION)\b).)*?';
    $boundValue = '(?:\?|:[a-z_][a-z0-9_]*)';
    $qualifiedColumn = '(?<![a-z0-9_])'.preg_quote($qualifier, '/').'\s*\.\s*workspace_id';
    $workspaceColumn = $allowUnqualified
        ? '(?:'.$qualifiedColumn.'|(?<!\.)\bworkspace_id)'
        : $qualifiedColumn;

    $forward = preg_match_all(
        '/\b'.$clause.$workspaceColumn.'\s*=\s*'.$boundValue.'/is',
        $sql,
    );
    $reverse = preg_match_all(
        '/\b'.$clause.$boundValue.'\s*=\s*'.$workspaceColumn.'/is',
        $sql,
    );

    return (false === $forward ? 0 : $forward) + (false === $reverse ? 0 : $reverse);
}

function insertCarriesWorkspace(string $sql, string $table): bool
{
    return 1 === preg_match(
        '/\bINSERT\s+INTO\s+(?:"?[a-z_][a-z0-9_]*"?\s*\.\s*)?"?'.preg_quote($table, '/').'"?\s*'.
        '\((?:(?!\)).)*\bworkspace_id\b(?:(?!\)).)*\)/is',
        $sql,
    );
}

/**
 * Replays the relevant parts of migration up() methods so a workspace column
 * added or renamed after CREATE TABLE is still visible to the guard.
 *
 * @return list<string>
 */
function scopedTables(string $migrationsDirectory): array
{
    /** @var array<string, bool> $tables */
    $tables = [];

    foreach (phpFiles($migrationsDirectory) as $file) {
        $up = methods(read($file))['up']['literals'] ?? '';

        foreach (schemaOperations($up) as $operation) {
            $table = $operation['table'];

            if ('create' === $operation['kind']) {
                $tables[$table] = $operation['hasWorkspaceColumn'];
                continue;
            }

            if ('add-workspace' === $operation['kind']) {
                $tables[$table] = true;
                continue;
            }

            if ('drop-workspace' === $operation['kind']) {
                $tables[$table] = false;
                continue;
            }

            if ('rename-to-workspace' === $operation['kind']) {
                $tables[$table] = true;
                continue;
            }

            if ('rename-from-workspace' === $operation['kind']) {
                $tables[$table] = false;
                continue;
            }

            if ('rename-table' === $operation['kind']) {
                $tables[$operation['newTable']] = $tables[$table] ?? false;
                unset($tables[$table]);
            }
        }
    }

    $scoped = array_keys(array_filter($tables));
    sort($scoped);

    return $scoped;
}

/**
 * @return list<array{kind: string, table: string, hasWorkspaceColumn: bool, newTable: string}>
 */
function schemaOperations(string $sql): array
{
    $operations = [];

    preg_match_all(
        '/\bCREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:"?[a-z_][a-z0-9_]*"?\.)?"?([a-z_][a-z0-9_]*)"?\s*\(/is',
        $sql,
        $creates,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    foreach ($creates as $match) {
        // The body is read by matching parentheses, not by a lazy regex: a
        // `REFERENCES other (id)` or a `NUMERIC(50, 24)` ending a line would
        // otherwise truncate the column list, and every column after it -
        // workspace_id included - would become invisible to the guard.
        $body = parenthesisedBody($sql, $match[0][1] + strlen($match[0][0]));
        $operations[] = [
            'offset' => $match[0][1],
            'kind' => 'create',
            'table' => strtolower($match[1][0]),
            'hasWorkspaceColumn' => declaresWorkspaceColumn($body),
            'newTable' => '',
        ];
    }

    preg_match_all(
        '/\bALTER\s+TABLE\s+(?:IF\s+EXISTS\s+)?(?:"?[a-z_][a-z0-9_]*"?\.)?"?([a-z_][a-z0-9_]*)"?\s+([^;\n]+)/i',
        $sql,
        $alters,
        PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
    );
    foreach ($alters as $match) {
        $table = strtolower($match[1][0]);
        $change = $match[2][0];
        $operation = null;

        if (1 === preg_match('/^RENAME\s+TO\s+"?([a-z_][a-z0-9_]*)"?/i', $change, $rename)) {
            $operation = ['kind' => 'rename-table', 'newTable' => strtolower($rename[1])];
        } elseif (1 === preg_match('/^RENAME\s+(?:COLUMN\s+)?"?workspace_id"?\s+TO\s+/i', $change)) {
            $operation = ['kind' => 'rename-from-workspace', 'newTable' => ''];
        } elseif (1 === preg_match('/^RENAME\s+(?:COLUMN\s+)?"?[a-z_][a-z0-9_]*"?\s+TO\s+"?workspace_id"?/i', $change)) {
            $operation = ['kind' => 'rename-to-workspace', 'newTable' => ''];
        } elseif (1 === preg_match('/^ADD\s+(?:COLUMN\s+)?(?:IF\s+NOT\s+EXISTS\s+)?"?workspace_id"?\b/i', $change)) {
            $operation = ['kind' => 'add-workspace', 'newTable' => ''];
        } elseif (1 === preg_match('/^DROP\s+(?:COLUMN\s+)?(?:IF\s+EXISTS\s+)?"?workspace_id"?\b/i', $change)) {
            $operation = ['kind' => 'drop-workspace', 'newTable' => ''];
        }

        if (null !== $operation) {
            $operations[] = [
                'offset' => $match[0][1],
                'kind' => $operation['kind'],
                'table' => $table,
                'hasWorkspaceColumn' => false,
                'newTable' => $operation['newTable'],
            ];
        }
    }

    usort($operations, static fn (array $left, array $right): int => $left['offset'] <=> $right['offset']);

    return array_map(static function (array $operation): array {
        unset($operation['offset']);

        return $operation;
    }, $operations);
}

/**
 * Everything from $start up to the parenthesis that closes the one just before
 * it, ignoring parentheses inside single-quoted SQL strings.
 */
function parenthesisedBody(string $sql, int $start): string
{
    $depth = 1;
    $length = strlen($sql);
    $inString = false;

    for ($cursor = $start; $cursor < $length; ++$cursor) {
        $character = $sql[$cursor];

        if ($inString) {
            if ("'" === $character) {
                $inString = false;
            }
            continue;
        }

        if ("'" === $character) {
            $inString = true;
            continue;
        }

        if ('(' === $character) {
            ++$depth;
            continue;
        }

        if (')' === $character && 0 === --$depth) {
            return substr($sql, $start, $cursor - $start);
        }
    }

    return substr($sql, $start);
}

/**
 * True when one of the body's top-level items is a column named workspace_id.
 * Splitting at depth zero is what keeps `FOREIGN KEY (workspace_id)` from
 * passing for a column declaration the table does not have.
 */
function declaresWorkspaceColumn(string $body): bool
{
    foreach (topLevelItems($body) as $item) {
        if (1 === preg_match('/^"?workspace_id"?\s/i', trim($item))) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function topLevelItems(string $body): array
{
    $items = [];
    $current = '';
    $depth = 0;
    $inString = false;
    $length = strlen($body);

    for ($cursor = 0; $cursor < $length; ++$cursor) {
        $character = $body[$cursor];

        if ($inString) {
            $current .= $character;
            if ("'" === $character) {
                $inString = false;
            }
            continue;
        }

        if ("'" === $character) {
            $inString = true;
            $current .= $character;
            continue;
        }

        if ('(' === $character) {
            ++$depth;
        } elseif (')' === $character) {
            --$depth;
        } elseif (',' === $character && 0 === $depth) {
            $items[] = $current;
            $current = '';
            continue;
        }

        $current .= $character;
    }

    $items[] = $current;

    return $items;
}

/**
 * @return list<string>
 */
function phpFiles(string $directory): array
{
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)) as $file) {
        if ($file->isFile() && 'php' === $file->getExtension()) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

function read(string $file): string
{
    $contents = file_get_contents($file);
    if (false === $contents) {
        fwrite(STDERR, sprintf("Cannot read %s\n", $file));
        exit(2);
    }

    return $contents;
}

function className(string $contents): string
{
    return 1 === preg_match('/^(?:final |readonly |abstract )*class\s+(\w+)/m', $contents, $match) ? $match[1] : '';
}

function qualifiedClassName(string $contents): string
{
    $class = className($contents);
    if ('' === $class) {
        return '';
    }

    return 1 === preg_match('/^namespace\s+([^;]+);/m', $contents, $match)
        ? trim($match[1]).'\\'.$class
        : $class;
}

/**
 * Every method of the file, broken into the statements it executes.
 *
 * Analysis is per statement, not per method: one correctly scoped query must
 * never vouch for an unscoped one sitting next to it. String constants and
 * locally built SQL variables are resolved so moving a table name, or a whole
 * query, out of the call site cannot hide it.
 *
 * @return array<string, array{statements: list<array{kind: string, table: string, sql: string, criteria: string}>, literals: string, createsWorkspaceScope: bool}>
 */
function methods(string $contents): array
{
    $tokens = token_get_all($contents);
    $constants = classStringConstants($tokens);
    $scopeAliases = workspaceScopeAliases($contents);
    $class = className($contents);
    $methods = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; ++$index) {
        $token = $tokens[$index];
        if (!is_array($token) || T_FUNCTION !== $token[0]) {
            continue;
        }

        $name = null;
        for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
            $candidate = $tokens[$cursor];
            if (is_array($candidate) && T_STRING === $candidate[0]) {
                $name = $candidate[1];
                break;
            }

            if ('(' === $candidate) {
                break;
            }
        }

        if (null === $name) {
            continue;
        }

        $depth = 0;
        $bodyStart = null;
        $bodyEnd = null;
        for ($cursor = $index; $cursor < $count; ++$cursor) {
            $candidate = $tokens[$cursor];

            if ('{' === $candidate) {
                if (0 === $depth) {
                    $bodyStart = $cursor + 1;
                }
                ++$depth;
                continue;
            }

            if ('}' === $candidate && null !== $bodyStart && 0 === --$depth) {
                $bodyEnd = $cursor;
                break;
            }
        }

        if (null === $bodyStart || null === $bodyEnd) {
            continue;
        }

        $analysis = analyseMethodBody($tokens, $bodyStart, $bodyEnd, $constants, $class, $scopeAliases);
        $methods[$name] = [
            'statements' => array_merge($methods[$name]['statements'] ?? [], $analysis['statements']),
            'literals' => ($methods[$name]['literals'] ?? '').$analysis['literals'],
            'createsWorkspaceScope' => ($methods[$name]['createsWorkspaceScope'] ?? false) || $analysis['createsWorkspaceScope'],
        ];
    }

    return $methods;
}

/**
 * @param list<array|string>    $tokens
 * @param array<string, string> $constants
 * @param list<string>          $aliases
 *
 * @return array{statements: list<array{kind: string, table: string, sql: string, criteria: string}>, literals: string, createsWorkspaceScope: bool}
 */
function analyseMethodBody(array $tokens, int $from, int $to, array $constants, string $class, array $aliases): array
{
    $statements = [];
    $literals = '';
    $createsWorkspaceScope = false;
    /** @var array<string, string> $variables */
    $variables = [];

    foreach (splitStatements($tokens, $from, $to) as $statement) {
        $ownLiterals = literalsOf($tokens, $statement, $constants, $class);
        $literals .= $ownLiterals;

        if (createsWorkspaceScope($tokens, $statement, $aliases)) {
            $createsWorkspaceScope = true;
        }

        foreach (objectCalls($tokens, $statement) as $call) {
            $arguments = array_map(
                static fn (array $range): string => resolvedText($tokens, $range, $constants, $class, $variables),
                $call['arguments'],
            );

            if (!in_array($call['name'], SQL_FIRST_CALLS, true) && !array_key_exists($call['name'], TABLE_FIRST_CALLS)) {
                $statements[] = [
                    'kind' => 'unrecognised',
                    'table' => '',
                    // An unknown API may accept SQL in any position. Retain the
                    // whole occurrence rather than guessing its signature.
                    'sql' => implode("\n", $arguments),
                    'criteria' => '',
                ];
                continue;
            }

            if (in_array($call['name'], SQL_FIRST_CALLS, true)) {
                $statements[] = [
                    'kind' => 'sql',
                    'table' => '',
                    'sql' => $arguments[0] ?? '',
                    'criteria' => '',
                ];
                continue;
            }

            $criteriaIndex = TABLE_FIRST_CALLS[$call['name']];
            $statements[] = [
                'kind' => $call['name'],
                'table' => trim($arguments[0] ?? ''),
                'sql' => $arguments[0] ?? '',
                'criteria' => null === $criteriaIndex ? ($arguments[1] ?? '') : ($arguments[$criteriaIndex] ?? ''),
            ];
        }

        // Recorded after the calls of the same statement, so a query and the
        // variable it is assigned from stay in the right order.
        $assignment = assignedVariable($tokens, $statement);
        if (null !== $assignment) {
            $text = resolvedText($tokens, $statement, $constants, $class, $variables);
            $variables[$assignment['name']] = $assignment['appends']
                ? ($variables[$assignment['name']] ?? '').$text
                : $text;
        }
    }

    return [
        'statements' => $statements,
        'literals' => $literals,
        'createsWorkspaceScope' => $createsWorkspaceScope,
    ];
}

/**
 * @param list<array|string> $tokens
 *
 * @return list<array{0: int, 1: int}> inclusive token ranges, one per statement
 */
function splitStatements(array $tokens, int $from, int $to): array
{
    $statements = [];
    $depth = 0;
    $start = $from;

    for ($cursor = $from; $cursor < $to; ++$cursor) {
        $token = $tokens[$cursor];

        if (in_array($token, ['(', '[', '{'], true)) {
            ++$depth;
            continue;
        }

        if (in_array($token, [')', ']', '}'], true)) {
            --$depth;
            continue;
        }

        if (';' === $token && 0 === $depth) {
            $statements[] = [$start, $cursor];
            $start = $cursor + 1;
        }
    }

    if ($start < $to) {
        $statements[] = [$start, $to];
    }

    return $statements;
}

/**
 * @param list<array|string>  $tokens
 * @param array{0: int, 1: int} $range
 *
 * @return list<array{name: string, arguments: list<array{0: int, 1: int}>}>
 */
function objectCalls(array $tokens, array $range): array
{
    $calls = [];

    for ($cursor = $range[0]; $cursor <= $range[1]; ++$cursor) {
        $token = $tokens[$cursor];
        if (!is_array($token) || !in_array($token[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            continue;
        }

        $member = nextCodeToken($tokens, $cursor);
        if (null === $member || !is_array($member['token']) || T_STRING !== $member['token'][0]) {
            continue;
        }

        $open = nextCodeToken($tokens, $member['index']);
        if (null === $open || '(' !== $open['token']) {
            continue;
        }

        $calls[] = ['name' => strtolower($member['token'][1]), 'arguments' => argumentRanges($tokens, $open['index'])];
    }

    return $calls;
}

/**
 * @param list<array|string> $tokens
 *
 * @return list<array{0: int, 1: int}>
 */
function argumentRanges(array $tokens, int $open): array
{
    $arguments = [];
    $depth = 0;
    $start = $open + 1;
    $count = count($tokens);

    for ($cursor = $open; $cursor < $count; ++$cursor) {
        $token = $tokens[$cursor];

        if (in_array($token, ['(', '[', '{'], true)) {
            ++$depth;
            continue;
        }

        if (in_array($token, [')', ']', '}'], true)) {
            if (0 === --$depth) {
                $arguments[] = [$start, $cursor - 1];

                return $arguments;
            }
            continue;
        }

        if (',' === $token && 1 === $depth) {
            $arguments[] = [$start, $cursor - 1];
            $start = $cursor + 1;
        }
    }

    return $arguments;
}

/**
 * @param list<array|string>    $tokens
 * @param array{0: int, 1: int} $range
 * @param array<string, string> $constants
 */
function literalsOf(array $tokens, array $range, array $constants, string $class): string
{
    $text = '';

    for ($cursor = $range[0]; $cursor <= $range[1]; ++$cursor) {
        $token = $tokens[$cursor];

        if (is_array($token) && T_CONSTANT_ENCAPSED_STRING === $token[0]) {
            $text .= phpStringLiteral($token[1])."\n";
            continue;
        }

        if (is_array($token) && T_ENCAPSED_AND_WHITESPACE === $token[0]) {
            $text .= $token[1]."\n";
            continue;
        }

        if (is_array($token) && T_DOUBLE_COLON === $token[0]) {
            $member = nextCodeToken($tokens, $cursor);
            $owner = previousCodeToken($tokens, $cursor);
            if (null !== $member && is_array($member['token']) && T_STRING === $member['token'][0]
                && isset($constants[$member['token'][1]])
                && isOwnClassReference($owner['token'] ?? null, $class)) {
                $text .= $constants[$member['token'][1]]."\n";
            }
        }
    }

    return $text;
}

/**
 * Literal text of a token range, plus the text of every local variable it
 * mentions, so SQL assembled across several statements is analysed whole.
 *
 * @param list<array|string>    $tokens
 * @param array{0: int, 1: int} $range
 * @param array<string, string> $constants
 * @param array<string, string> $variables
 */
function resolvedText(array $tokens, array $range, array $constants, string $class, array $variables): string
{
    $text = literalsOf($tokens, $range, $constants, $class);

    for ($cursor = $range[0]; $cursor <= $range[1]; ++$cursor) {
        $token = $tokens[$cursor];
        if (is_array($token) && T_VARIABLE === $token[0] && isset($variables[$token[1]])) {
            $text .= $variables[$token[1]]."\n";
        }
    }

    return $text;
}

/**
 * @param list<array|string>    $tokens
 * @param array{0: int, 1: int} $range
 *
 * @return array{name: string, appends: bool}|null
 */
function assignedVariable(array $tokens, array $range): ?array
{
    for ($cursor = $range[0]; $cursor <= $range[1]; ++$cursor) {
        $token = $tokens[$cursor];
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        if (!is_array($token) || T_VARIABLE !== $token[0]) {
            return null;
        }

        $operator = nextCodeToken($tokens, $cursor);
        if (null === $operator) {
            return null;
        }

        if ('=' === $operator['token']) {
            return ['name' => $token[1], 'appends' => false];
        }

        if (is_array($operator['token']) && T_CONCAT_EQUAL === $operator['token'][0]) {
            return ['name' => $token[1], 'appends' => true];
        }

        return null;
    }

    return null;
}

/**
 * @param list<array|string>    $tokens
 * @param array{0: int, 1: int} $range
 * @param list<string>          $aliases
 */
function createsWorkspaceScope(array $tokens, array $range, array $aliases): bool
{
    for ($cursor = $range[0]; $cursor <= $range[1]; ++$cursor) {
        $token = $tokens[$cursor];
        if (!is_array($token) || T_DOUBLE_COLON !== $token[0]) {
            continue;
        }

        $member = nextCodeToken($tokens, $cursor);
        $owner = previousCodeToken($tokens, $cursor);
        if (null !== $member && is_array($member['token']) && T_STRING === $member['token'][0]
            && 'fromString' === $member['token'][1]
            && isWorkspaceScopeReference($owner['token'] ?? null, $aliases)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array|string> $tokens
 *
 * @return array<string, string>
 */
function classStringConstants(array $tokens): array
{
    $constants = [];
    $count = count($tokens);

    for ($index = 0; $index < $count; ++$index) {
        if (!is_array($tokens[$index]) || T_CONST !== $tokens[$index][0]) {
            continue;
        }

        for ($cursor = $index + 1; $cursor < $count && ';' !== $tokens[$cursor]; ++$cursor) {
            if ('=' !== $tokens[$cursor]) {
                continue;
            }

            $name = previousCodeToken($tokens, $cursor);
            $value = nextCodeToken($tokens, $cursor);
            if (null !== $name && null !== $value
                && is_array($name['token']) && T_STRING === $name['token'][0]
                && is_array($value['token']) && T_CONSTANT_ENCAPSED_STRING === $value['token'][0]) {
                $constants[$name['token'][1]] = phpStringLiteral($value['token'][1]);
            }
        }
    }

    return $constants;
}

/**
 * @return list<string>
 */
function workspaceScopeAliases(string $contents): array
{
    // The canonical short name also covers grouped imports. A distinct local
    // class with this security-sensitive name is intentionally rejected.
    $aliases = ['WorkspaceScope'];
    if (1 === preg_match(
        '/^use\s+App\\\\Module\\\\Foundation\\\\Domain\\\\WorkspaceScope(?:\s+as\s+(\w+))?\s*;/mi',
        $contents,
        $match,
    )) {
        $aliases[] = isset($match[1]) && '' !== $match[1] ? $match[1] : 'WorkspaceScope';
    }

    return array_values(array_unique($aliases));
}

/**
 * @param list<array|string> $tokens
 *
 * @return array{token: array|string, index: int}|null
 */
function previousCodeToken(array $tokens, int $index): ?array
{
    for ($cursor = $index - 1; $cursor >= 0; --$cursor) {
        $token = $tokens[$cursor];
        if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return ['token' => $token, 'index' => $cursor];
        }
    }

    return null;
}

/**
 * @param list<array|string> $tokens
 *
 * @return array{token: array|string, index: int}|null
 */
function nextCodeToken(array $tokens, int $index): ?array
{
    $count = count($tokens);
    for ($cursor = $index + 1; $cursor < $count; ++$cursor) {
        $token = $tokens[$cursor];
        if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return ['token' => $token, 'index' => $cursor];
        }
    }

    return null;
}

function isOwnClassReference(array|string|null $token, string $class): bool
{
    if (!is_array($token)) {
        return false;
    }

    return (T_STATIC === $token[0])
        || (T_STRING === $token[0] && in_array(strtolower($token[1]), ['self', strtolower($class)], true));
}

/**
 * @param list<string> $aliases
 */
function isWorkspaceScopeReference(array|string|null $token, array $aliases): bool
{
    if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        return false;
    }

    $name = ltrim($token[1], '\\');
    if ('App\\Module\\Foundation\\Domain\\WorkspaceScope' === $name) {
        return true;
    }

    $separator = strrpos($name, '\\');
    $basename = false === $separator ? $name : substr($name, $separator + 1);

    return in_array($basename, $aliases, true);
}

function phpStringLiteral(string $literal): string
{
    $quote = $literal[0] ?? '';
    $body = substr($literal, 1, -1);

    return "'" === $quote
        ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
        : stripcslashes($body);
}
