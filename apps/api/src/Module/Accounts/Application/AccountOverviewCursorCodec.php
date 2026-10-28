<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs the position a member page resumes from.
 *
 * The cursor names the group and the grouping mode it was minted for and the
 * order version it was read at, and the signature stops a client from forging a position into a
 * group, or a workspace-wide offset, it was never given.
 */
final readonly class AccountOverviewCursorCodec
{
    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        #[\SensitiveParameter]
        private string $secret,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('APP_SECRET must not be empty.');
        }
    }

    public function encode(string $groupKey, AccountOverviewGrouping $grouping, int $version, int $rank): string
    {
        $payload = rtrim(strtr(base64_encode(sprintf('%s %s %d %d', $groupKey, $grouping->value, $version, $rank)), '+/', '-_'), '=');

        return $payload.'.'.$this->signature($payload);
    }

    /**
     * @return array{version: int, rank: int}
     *
     * @throws InvalidAccountOverviewQuery when the cursor is malformed, forged or minted for another group or grouping
     */
    public function decode(string $cursor, string $groupKey, AccountOverviewGrouping $grouping): array
    {
        $parts = explode('.', $cursor);
        if (2 !== count($parts) || !hash_equals($this->signature($parts[0]), $parts[1])) {
            throw new InvalidAccountOverviewQuery('The member cursor is invalid.');
        }

        $decoded = base64_decode(strtr($parts[0], '-_', '+/'), true);
        $fields = false === $decoded ? [] : explode(' ', $decoded);
        if (4 !== count($fields)
            || $fields[0] !== $groupKey
            || $fields[1] !== $grouping->value
            || 1 !== preg_match('/^[0-9]{1,9}$/D', $fields[2])
            || 1 !== preg_match('/^[0-9]{1,9}$/D', $fields[3])
        ) {
            throw new InvalidAccountOverviewQuery('The member cursor does not belong to this group or grouping.');
        }

        return ['version' => (int) $fields[2], 'rank' => (int) $fields[3]];
    }

    private function signature(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', $payload, $this->secret, true)), '+/', '-_'), '=');
    }
}
