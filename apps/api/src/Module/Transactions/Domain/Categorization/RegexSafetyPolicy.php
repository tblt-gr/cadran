<?php

declare(strict_types=1);

namespace App\Module\Transactions\Domain\Categorization;

/**
 * Write-time guard against catastrophic backtracking. A stored pattern is run
 * against every movement of a workspace, so this refuses the constructs that
 * make PCRE explode — lookaround, backreferences, a quantifier applied to a
 * group that already repeats, possessive and atomic forms, mode switches —
 * before the pattern is ever compiled against data. The scan is conservative:
 * it may refuse an exotic but harmless pattern, never accept a listed one.
 * Execution stays bounded independently of this check.
 */
final readonly class RegexSafetyPolicy
{
    public const int MAX_LENGTH = 120;
    public const string TOO_LONG = 'too_long';
    public const string LOOKAROUND = 'lookaround';
    public const string BACKREFERENCE = 'backreference';
    public const string NESTED_QUANTIFIER = 'nested_quantifier';
    public const string POSSESSIVE = 'possessive';
    public const string INLINE_MODIFIER = 'inline_modifier';
    public const string INVALID = 'invalid';

    public static function violation(string $pattern): ?string
    {
        if ('' === $pattern || !mb_check_encoding($pattern, 'UTF-8') || 1 === preg_match('/\p{Cc}/u', $pattern)) {
            return self::INVALID;
        }
        if (mb_strlen($pattern) > self::MAX_LENGTH) {
            return self::TOO_LONG;
        }
        $structural = self::structuralViolation($pattern);
        if (null !== $structural) {
            return $structural;
        }

        return false === @preg_match(self::compile($pattern), '') ? self::INVALID : null;
    }

    /** The delimited, case-insensitive, UTF-8 form every stored pattern is evaluated with. */
    public static function compile(string $pattern): string
    {
        $escaped = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; ++$i) {
            $character = $pattern[$i];
            if ('\\' === $character) {
                $escaped .= $character.($pattern[$i + 1] ?? '');
                ++$i;
            } elseif ('/' === $character) {
                $escaped .= '\/';
            } else {
                $escaped .= $character;
            }
        }

        return '/'.$escaped.'/iuD';
    }

    private static function structuralViolation(string $pattern): ?string
    {
        // One frame per open group, recording whether anything inside it repeats.
        $frames = [false];
        // Whether the previous atom is a group, and if so whether it repeats inside.
        $previousGroupRepeats = null;
        $length = strlen($pattern);
        $i = 0;
        while ($i < $length) {
            $character = $pattern[$i];
            if ('\\' === $character) {
                $next = $pattern[$i + 1] ?? '';
                if (1 === preg_match('/^[1-9gk]$/D', $next)) {
                    return self::BACKREFERENCE;
                }
                $i += 2;
                if (in_array($next, ['x', 'p', 'P', 'N', 'o'], true) && '{' === ($pattern[$i] ?? '')) {
                    $close = strpos($pattern, '}', $i);
                    $i = false === $close ? $length : $close + 1;
                }
                $previousGroupRepeats = null;
                continue;
            }
            if ('[' === $character) {
                $j = $i + 1;
                if ('^' === ($pattern[$j] ?? '')) {
                    ++$j;
                }
                if (']' === ($pattern[$j] ?? '')) {
                    ++$j;
                }
                while ($j < $length && ']' !== $pattern[$j]) {
                    $j += '\\' === $pattern[$j] ? 2 : 1;
                }
                if ($j >= $length) {
                    return self::INVALID;
                }
                $i = $j + 1;
                $previousGroupRepeats = null;
                continue;
            }
            if ('(' === $character) {
                if (0 === $i && str_starts_with($pattern, '(?i)')) {
                    $i += 4;
                    $previousGroupRepeats = null;
                    continue;
                }
                if ('?' === ($pattern[$i + 1] ?? '')) {
                    $construct = substr($pattern, $i + 2, 2);
                    $violation = match (true) {
                        str_starts_with($construct, '='), str_starts_with($construct, '!'),
                        '<=' === $construct, '<!' === $construct => self::LOOKAROUND,
                        'P=' === $construct => self::BACKREFERENCE,
                        str_starts_with($construct, '>') => self::POSSESSIVE,
                        str_starts_with($construct, ':'), 'P<' === $construct, str_starts_with($construct, '<') => null,
                        default => self::INLINE_MODIFIER,
                    };
                    if (null !== $violation) {
                        return $violation;
                    }
                    // Step over the group prefix so its `?` is not read as a quantifier.
                    $close = str_starts_with($construct, ':') ? $i + 2 : strpos($pattern, '>', $i);
                    if (false === $close) {
                        return self::INVALID;
                    }
                    $i = $close;
                }
                $frames[] = false;
                ++$i;
                $previousGroupRepeats = null;
                continue;
            }
            if (')' === $character) {
                if (1 === count($frames)) {
                    return self::INVALID;
                }
                $repeats = array_pop($frames);
                if ($repeats) {
                    $frames[count($frames) - 1] = true;
                }
                $previousGroupRepeats = $repeats;
                ++$i;
                continue;
            }

            $quantifierLength = in_array($character, ['*', '+', '?'], true) ? 1 : 0;
            // PCRE2 10.43 and later also read `{,n}` as a counted quantifier.
            if ('{' === $character && 1 === preg_match('/\G\{(?:[0-9]+(?:,[0-9]*)?|,[0-9]+)\}/', $pattern, $match, 0, $i)) {
                $quantifierLength = strlen($match[0]);
            }
            if ($quantifierLength > 0) {
                if (true === $previousGroupRepeats) {
                    return self::NESTED_QUANTIFIER;
                }
                $frames[count($frames) - 1] = true;
                $i += $quantifierLength;
                if ('+' === ($pattern[$i] ?? '')) {
                    return self::POSSESSIVE;
                }
                if ('?' === ($pattern[$i] ?? '')) {
                    ++$i;
                }
                $previousGroupRepeats = null;
                continue;
            }

            $previousGroupRepeats = null;
            ++$i;
        }

        return 1 === count($frames) ? null : self::INVALID;
    }
}
