<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Recurrence;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Reference\Application\AssetCatalog;
use App\Module\Reference\Domain\Asset;
use App\Module\Transactions\Domain\Recurrence\InvalidRecurrence;
use App\Module\Transactions\Domain\Recurrence\RecurrenceIntervalKind;
use App\Module\Transactions\Domain\Recurrence\RecurrenceSchedule;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;

/**
 * Turns a submitted schedule into a recurrence, or refuses it.
 *
 * Amounts are parsed by the account's own asset, so a figure it cannot store is
 * rejected at this boundary instead of being silently shortened on its way to
 * `NUMERIC(50,24)`.
 */
final readonly class RecurrenceFactory
{
    public function __construct(private AccountRepository $accounts, private AssetCatalog $assets)
    {
    }

    public function create(string $id, WorkspaceScope $workspace, RecurrenceInput $input, \DateTimeImmutable $now): TransactionRecurrence
    {
        $account = $this->accounts->find($workspace, $input->accountId);
        if (null === $account || null !== $account->archivedAt || $account->isClosed()) {
            throw new InvalidRecurrenceReference('A recurrence follows an active account of the caller workspace.');
        }
        $asset = $this->assets->findByCode($account->assetCode)
            ?? throw new InvalidRecurrenceReference('The account asset is missing from the reference.');
        $interval = self::interval($input->intervalKind);
        $schedule = self::schedule($interval, $input->dayOfPeriod, self::day($input->firstExpectedOn));

        return self::build(
            id: $id, workspace: $workspace, accountId: $input->accountId, label: $input->label,
            counterparty: $input->counterparty,
            expectedAmount: self::amount($asset, $input->expectedAmount),
            amountTolerance: self::amount($asset, $input->amountTolerance),
            intervalKind: $interval, dayOfPeriod: $input->dayOfPeriod,
            nextExpectedOn: $schedule->first(), confirmedAt: $now, version: 1,
            createdAt: $now, updatedAt: $now, archivedAt: null,
        );
    }

    /**
     * The edited recurrence, with its version already bumped. The pointer is
     * left where it was: the caller recomputes it once the regenerated
     * instalments are written, from the instalments that actually survived.
     */
    public function revise(TransactionRecurrence $current, RecurrenceEditInput $input, \DateTimeImmutable $now): TransactionRecurrence
    {
        $asset = $this->assets->findByCode($current->expectedAmount->asset)
            ?? throw new InvalidRecurrenceReference('The recurrence asset is missing from the reference.');

        return self::build(
            id: $current->id, workspace: $current->workspace, accountId: $current->accountId, label: $input->label,
            counterparty: $input->counterparty,
            expectedAmount: self::amount($asset, $input->expectedAmount),
            amountTolerance: self::amount($asset, $input->amountTolerance),
            intervalKind: self::interval($input->intervalKind), dayOfPeriod: $input->dayOfPeriod,
            nextExpectedOn: $current->nextExpectedOn, confirmedAt: $current->confirmedAt,
            version: $current->version + 1, createdAt: $current->createdAt, updatedAt: $now,
            archivedAt: null,
        );
    }

    public static function archive(TransactionRecurrence $current, \DateTimeImmutable $now): TransactionRecurrence
    {
        return self::build(
            id: $current->id, workspace: $current->workspace, accountId: $current->accountId, label: $current->label,
            counterparty: $current->counterparty, expectedAmount: $current->expectedAmount,
            amountTolerance: $current->amountTolerance, intervalKind: $current->intervalKind,
            dayOfPeriod: $current->dayOfPeriod, nextExpectedOn: $current->nextExpectedOn,
            confirmedAt: $current->confirmedAt, version: $current->version + 1, createdAt: $current->createdAt,
            updatedAt: $now, archivedAt: $now,
        );
    }

    public static function schedule(RecurrenceIntervalKind $interval, int $dayOfPeriod, \DateTimeImmutable $from): RecurrenceSchedule
    {
        try {
            return RecurrenceSchedule::anchoredOn($interval, $dayOfPeriod, $from);
        } catch (InvalidRecurrence $exception) {
            throw new InvalidRecurrenceInput($exception->getMessage(), previous: $exception);
        }
    }

    public static function day(string $literal): \DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $literal, new \DateTimeZone('UTC'));
        if (false === $date || $date->format('Y-m-d') !== $literal) {
            throw new InvalidRecurrenceInput('A recurrence date is an ISO 8601 calendar day.');
        }

        return $date;
    }

    private static function interval(string $literal): RecurrenceIntervalKind
    {
        return RecurrenceIntervalKind::tryFrom($literal)
            ?? throw new InvalidRecurrenceInput('A recurrence is weekly, monthly, quarterly or yearly.');
    }

    private static function amount(Asset $asset, string $literal): AssetAmount
    {
        try {
            return $asset->amount($literal);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidRecurrenceInput('A recurrence amount is a canonical decimal of its asset.', previous: $exception);
        }
    }

    private static function build(
        string $id,
        WorkspaceScope $workspace,
        string $accountId,
        string $label,
        ?string $counterparty,
        AssetAmount $expectedAmount,
        AssetAmount $amountTolerance,
        RecurrenceIntervalKind $intervalKind,
        int $dayOfPeriod,
        \DateTimeImmutable $nextExpectedOn,
        \DateTimeImmutable $confirmedAt,
        int $version,
        \DateTimeImmutable $createdAt,
        \DateTimeImmutable $updatedAt,
        ?\DateTimeImmutable $archivedAt,
    ): TransactionRecurrence {
        try {
            return new TransactionRecurrence(
                id: $id, workspace: $workspace, accountId: $accountId, label: $label, counterparty: $counterparty,
                expectedAmount: $expectedAmount, amountTolerance: $amountTolerance, intervalKind: $intervalKind,
                dayOfPeriod: $dayOfPeriod, nextExpectedOn: $nextExpectedOn, confirmedAt: $confirmedAt,
                version: $version, createdAt: $createdAt, updatedAt: $updatedAt, archivedAt: $archivedAt,
            );
        } catch (InvalidRecurrence $exception) {
            throw new InvalidRecurrenceInput($exception->getMessage(), previous: $exception);
        }
    }
}
