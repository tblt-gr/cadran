<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Console;

use App\Module\Transactions\Domain\IdempotencyKeyRepository;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'cadran:transactions:purge-idempotency-keys',
    description: 'Remove expired transaction idempotency keys.',
)]
final class PurgeIdempotencyKeysCommand extends Command
{
    private const int BATCH_SIZE = 1000;

    public function __construct(
        private readonly IdempotencyKeyRepository $keys,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $removed = 0;
        do {
            $batch = $this->keys->purgeExpired($this->clock->now(), self::BATCH_SIZE);
            $removed += $batch;
        } while (self::BATCH_SIZE === $batch);

        (new SymfonyStyle($input, $output))->success(sprintf('Purged %d expired transaction idempotency key(s).', $removed));

        return Command::SUCCESS;
    }
}
