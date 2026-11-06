<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Domain\WorkspaceScope;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Signs what a bulk move preview showed. The token binds the workspace, the scope, the requested changes and
 * the identifier and version of every eligible row, so any edit of one of those rows since the preview, or
 * any change of the request, makes the apply refuse it. It expires after ten minutes.
 */
final readonly class BulkPreviewTokenCodec
{
    public const int TTL_SECONDS = 600;

    public function __construct(
        #[Autowire('%env(APP_SECRET)%')]
        #[\SensitiveParameter]
        private string $secret,
        private ClockInterface $clock,
    ) {
        if ('' === $secret) {
            throw new \InvalidArgumentException('APP_SECRET must not be empty.');
        }
    }

    /**
     * @param list<string>       $scopeIds
     * @param array<string, int> $eligibleVersions
     */
    public function issue(WorkspaceScope $workspace, array $scopeIds, BulkEditChanges $changes, array $eligibleVersions): string
    {
        $expiresAt = (string) ($this->clock->now()->getTimestamp() + self::TTL_SECONDS);

        return self::encode($expiresAt).'.'.$this->signature($expiresAt, $workspace, $scopeIds, $changes, $eligibleVersions);
    }

    /**
     * @param list<string>       $scopeIds
     * @param array<string, int> $eligibleVersions
     */
    public function accepts(?string $token, WorkspaceScope $workspace, array $scopeIds, BulkEditChanges $changes, array $eligibleVersions): bool
    {
        $parts = null === $token ? [] : explode('.', $token);
        if (2 !== count($parts)) {
            return false;
        }
        $expiresAt = base64_decode(strtr($parts[0], '-_', '+/'), true);
        if (false === $expiresAt || 1 !== preg_match('/^[0-9]{1,12}$/D', $expiresAt) || (int) $expiresAt < $this->clock->now()->getTimestamp()) {
            return false;
        }

        return hash_equals($this->signature($expiresAt, $workspace, $scopeIds, $changes, $eligibleVersions), $parts[1]);
    }

    /**
     * @param list<string>       $scopeIds
     * @param array<string, int> $eligibleVersions
     */
    private function signature(string $expiresAt, WorkspaceScope $workspace, array $scopeIds, BulkEditChanges $changes, array $eligibleVersions): string
    {
        ksort($eligibleVersions);
        $rows = [];
        foreach ($eligibleVersions as $id => $version) {
            $rows[] = $id.':'.$version;
        }
        $payload = implode("\n", [$expiresAt, $workspace->id, implode(',', $scopeIds), $changes->canonical(), implode(',', $rows)]);

        return self::encode(hash_hmac('sha256', $payload, $this->secret, true));
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
