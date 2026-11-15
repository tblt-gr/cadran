<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Keeps the problem types the API returns and the `ProblemType` schema of the
 * contract in sync, in both directions.
 */
final class ProblemTypeCatalogContractTest extends TestCase
{
    private const string PREFIX = '/problems/';

    public function testEveryProblemTypeConstantInTheCodeIsDeclaredInTheContract(): void
    {
        $declared = self::declaredTypes();
        $missing = [];
        foreach (self::literalsInSource() as $type) {
            // A literal ending in a dot or a slash is the stem of a computed type.
            if (!str_ends_with($type, '.') && !str_ends_with($type, '/') && !in_array($type, $declared, true)) {
                $missing[] = $type;
            }
        }

        self::assertSame([], $missing, 'Problem types returned by the code but missing from components/schemas/ProblemType.');
    }

    public function testEveryDeclaredProblemTypeIsReturnedByTheCode(): void
    {
        $literals = self::literalsInSource();
        $tokens = self::quotedTokensInSource();
        $orphans = [];
        foreach (self::declaredTypes() as $type) {
            if ('about:blank' === $type || in_array($type, $literals, true)) {
                continue;
            }
            // Computed types: the rule or error code the code builds them from
            // exists as a string, with `_` or `-` separators and an optional
            // `family.` prefix.
            $code = substr($type, strlen(self::PREFIX));
            $rule = str_contains($code, '.') ? substr($code, (int) strpos($code, '.') + 1) : $code;
            $candidates = [$code, str_replace('-', '_', $code), $rule, str_replace('-', '_', $rule)];
            if ([] === array_intersect($candidates, $tokens)) {
                $orphans[] = $type;
            }
        }

        self::assertSame([], $orphans, 'ProblemType lists values no code returns.');
    }

    public function testEveryCodedPortfolioProblemIsDeclaredInTheContract(): void
    {
        $declared = self::declaredTypes();
        $missing = [];
        foreach (self::sourceFiles() as $file) {
            preg_match_all("/self::coded\\([^,]+,\\s*'([a-z_]+)'/", (string) file_get_contents($file), $matches);
            foreach ($matches[1] as $code) {
                $type = self::PREFIX.str_replace('_', '-', $code);
                if (!in_array($type, $declared, true)) {
                    $missing[] = $type;
                }
            }
        }

        self::assertSame([], $missing);
    }

    public function testEveryProblemTypeHasADescriptionAndIsListedOnce(): void
    {
        $entries = self::entries();
        $values = array_column($entries, 'const');

        self::assertSame(array_values(array_unique($values)), $values, 'A problem type is listed twice.');
        foreach ($entries as $entry) {
            self::assertIsString($entry['description'] ?? null, 'A problem type has no description.');
            self::assertNotSame('', trim($entry['description']));
        }
        self::assertContains('about:blank', $values);
    }

    /** @return list<array{const: string, description: mixed}> */
    private static function entries(): array
    {
        $document = Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml');
        self::assertIsArray($document);
        $components = $document['components'] ?? null;
        self::assertIsArray($components);
        $schemas = $components['schemas'] ?? null;
        self::assertIsArray($schemas);
        $problemType = $schemas['ProblemType'] ?? null;
        self::assertIsArray($problemType);
        $oneOf = $problemType['oneOf'] ?? null;
        self::assertIsArray($oneOf);

        $entries = [];
        foreach ($oneOf as $entry) {
            self::assertIsArray($entry);
            self::assertIsString($entry['const'] ?? null);
            $entries[] = ['const' => $entry['const'], 'description' => $entry['description'] ?? null];
        }

        return $entries;
    }

    /** @return list<string> */
    private static function declaredTypes(): array
    {
        return array_column(self::entries(), 'const');
    }

    /** @return list<string> */
    private static function sourceFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && 'php' === $file->getExtension()) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return list<string> every `'/problems/...'` string constant under src */
    private static function literalsInSource(): array
    {
        $literals = [];
        foreach (self::sourceFiles() as $file) {
            foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $token) {
                if (T_CONSTANT_ENCAPSED_STRING === $token->id) {
                    $value = substr($token->text, 1, -1);
                    if (str_starts_with($value, self::PREFIX)) {
                        $literals[$value] = true;
                    }
                }
            }
        }

        return array_keys($literals);
    }

    /** @return list<string> every other plain string constant under src */
    private static function quotedTokensInSource(): array
    {
        $tokens = [];
        foreach (self::sourceFiles() as $file) {
            foreach (\PhpToken::tokenize((string) file_get_contents($file)) as $token) {
                if (T_CONSTANT_ENCAPSED_STRING === $token->id) {
                    $tokens[substr($token->text, 1, -1)] = true;
                }
            }
        }

        return array_map('strval', array_keys($tokens));
    }
}
