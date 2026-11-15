<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A migration that rewrites rows is riskier to upgrade through than one that
 * only changes the schema, and nothing in the version list says which is
 * which. The convention: its description starts with "[data] ", and no schema
 * only migration carries that prefix.
 */
final class MigrationConventionTest extends TestCase
{
    private const MARKER = '[data] ';

    public function testEveryMigrationDescriptionMatchesWhatItsUpMethodDoes(): void
    {
        $files = glob(__DIR__.'/../../migrations/*.php');
        self::assertNotFalse($files);
        self::assertNotEmpty($files);

        $offenders = [];
        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            $changesData = self::changesData($source);
            $marked = str_starts_with(self::description($source), self::MARKER);

            if ($changesData && !$marked) {
                $offenders[] = basename($file).' issues INSERT, UPDATE or DELETE in up() but its description does not start with "[data] "';
            } elseif (!$changesData && $marked) {
                $offenders[] = basename($file).' only changes the schema but its description starts with "[data] "';
            }
        }

        self::assertSame([], $offenders);
    }

    #[DataProvider('detectionCases')]
    public function testDetectionRecognisesDataStatementsOnly(string $upBody, bool $expected): void
    {
        $source = "<?php\nfinal class V extends M {\n public function up(Schema \$schema): void {\n".$upBody."\n }\n public function down(Schema \$schema): void {\n \$this->addSql('DELETE FROM t');\n }\n}\n";

        self::assertSame($expected, self::changesData($source));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function detectionCases(): iterable
    {
        yield 'insert' => ["\$this->addSql('INSERT INTO t (a) VALUES (1)');", true];
        yield 'lowercase update' => ["\$this->addSql('  update t set a = 1');", true];
        yield 'delete' => ["\$this->addSql(\"DELETE FROM t WHERE a = 1\");", true];
        yield 'with update' => ["\$this->addSql('WITH x AS (SELECT 1) UPDATE t SET a = 1');", true];
        yield 'with insert' => ["\$this->addSql('WITH x AS (SELECT 1) INSERT INTO t SELECT * FROM x');", true];
        yield 'leading comment' => ["\$this->addSql('-- fix\nUPDATE t SET a = 1');", true];
        yield 'heredoc' => ["\$this->addSql(<<<'SQL'\n  /* c */\n  INSERT INTO t VALUES (1)\n  SQL);", true];
        yield 'ddl' => ["\$this->addSql('CREATE TABLE t (a INT)');", false];
        yield 'ddl mentioning update' => ["\$this->addSql(\"ALTER TABLE t ADD CONSTRAINT c CHECK (a <> 'UPDATE')\");", false];
        yield 'select only' => ["\$this->addSql('WITH x AS (SELECT 1) SELECT * FROM x');", false];
    }

    private static function description(string $source): string
    {
        $inMethod = false;
        foreach (\PhpToken::tokenize($source) as $token) {
            if ($token->is(T_STRING) && 'getDescription' === $token->text) {
                $inMethod = true;
            }
            if ($inMethod && $token->is(T_CONSTANT_ENCAPSED_STRING)) {
                return stripcslashes(substr($token->text, 1, -1));
            }
        }

        return '';
    }

    private static function changesData(string $source): bool
    {
        foreach (self::upStatements($source) as $sql) {
            $sql = trim((string) preg_replace('~(--[^\n]*|/\*.*?\*/)~s', ' ', $sql));
            if (1 === preg_match('~^(INSERT|UPDATE|DELETE)\b~i', $sql)) {
                return true;
            }
            if (1 === preg_match('~^WITH\b~i', $sql)) {
                $withoutLiterals = (string) preg_replace("~'(?:[^']|'')*'~", "''", $sql);
                if (1 === preg_match('~\b(INSERT\s+INTO|UPDATE|DELETE\s+FROM)\b~i', $withoutLiterals)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> string literals passed to addSql() inside up() */
    private static function upStatements(string $source): array
    {
        $tokens = array_values(array_filter(
            \PhpToken::tokenize($source),
            static fn (\PhpToken $t): bool => !$t->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT]),
        ));
        $count = count($tokens);
        $statements = [];

        for ($i = 0; $i < $count; ++$i) {
            if (!($tokens[$i]->is(T_FUNCTION) && isset($tokens[$i + 1]) && 'up' === $tokens[$i + 1]->text)) {
                continue;
            }
            while ($i < $count && '{' !== $tokens[$i]->text) {
                ++$i;
            }
            $depth = 0;
            for (; $i < $count; ++$i) {
                $text = $tokens[$i]->text;
                if ('{' === $text || $tokens[$i]->is(T_CURLY_OPEN)) {
                    ++$depth;
                } elseif ('}' === $text) {
                    if (0 === --$depth) {
                        break;
                    }
                } elseif ($tokens[$i]->is(T_STRING) && 'addSql' === $text && '(' === ($tokens[$i + 1]->text ?? '')) {
                    $first = $tokens[$i + 2] ?? null;
                    if ($first?->is(T_CONSTANT_ENCAPSED_STRING)) {
                        $statements[] = stripcslashes(substr($first->text, 1, -1));
                    } elseif ($first?->is(T_START_HEREDOC)) {
                        $body = '';
                        for ($j = $i + 3; $j < $count && !$tokens[$j]->is(T_END_HEREDOC); ++$j) {
                            $body .= $tokens[$j]->text;
                        }
                        $statements[] = $body;
                    } else {
                        $statements[] = 'UNKNOWN';
                    }
                }
            }
        }

        return $statements;
    }
}
