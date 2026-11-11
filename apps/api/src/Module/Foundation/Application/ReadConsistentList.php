<?php

declare(strict_types=1);

namespace App\Module\Foundation\Application;

final readonly class ReadConsistentList
{
    public function __construct(private CallerWorkspace $caller, private TransactionBoundary $transaction, private ListRevisionRepository $revisions)
    {
    }

    /**
     * @template T
     *
     * @param \Closure(): T $read
     *
     * @return array{revision: string, value: T}
     */
    public function __invoke(string $expected, string $queryScope, bool $catalogue, \Closure $read): array
    {
        $workspace = $this->caller->resolve();

        return $this->transaction->transactional(function () use ($workspace, $expected, $queryScope, $catalogue, $read): array {
            $version = $this->revisions->lock($workspace, $catalogue);
            $revision = hash('sha256', $workspace->id.':'.$version.':'.$queryScope);
            if ('start' !== $expected && !hash_equals($revision, $expected)) {
                throw new ListRevisionStale();
            }

            return ['revision' => $revision, 'value' => $read()];
        });
    }
}
