<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Infrastructure\Persistence;

use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Domain\LifeInsurance\ContractNumberSuffix;
use App\Module\Portfolio\Domain\LifeInsurance\ContractStatus;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use App\Module\Portfolio\Domain\LifeInsurance\ManagementMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(LifeInsuranceContractRepository::class)]
final readonly class DbalLifeInsuranceContractRepository implements LifeInsuranceContractRepository
{
    private const string COLUMNS = 'id, account_id, insurer, distributor, commercial_name, contract_number_suffix, subscribed_on, tax_start_on, management_mode, status, closed_on, created_at, created_by, updated_at, version';

    public function __construct(private Connection $connection)
    {
    }

    public function find(WorkspaceScope $workspace, string $id): ?LifeInsuranceContract
    {
        return $this->one($workspace, 'id = :key', $id, false);
    }

    public function findForUpdate(WorkspaceScope $workspace, string $id): ?LifeInsuranceContract
    {
        return $this->one($workspace, 'id = :key', $id, true);
    }

    public function findByAccount(WorkspaceScope $workspace, string $accountId): ?LifeInsuranceContract
    {
        return $this->one($workspace, 'account_id = :key', $accountId, false);
    }

    public function add(LifeInsuranceContract $contract): void
    {
        $this->connection->insert('portfolio_life_insurance_contracts', [
            'id' => $contract->id,
            'workspace_id' => $contract->workspace->id,
            'account_id' => $contract->accountId,
            'created_at' => $contract->createdAt->format('Y-m-d H:i:s.uP'),
            'created_by' => $contract->createdBy,
            ...self::columns($contract),
        ]);
    }

    public function update(LifeInsuranceContract $contract, int $expectedVersion): bool
    {
        return 1 === (int) $this->connection->update(
            'portfolio_life_insurance_contracts',
            self::columns($contract),
            ['workspace_id' => $contract->workspace->id, 'id' => $contract->id, 'version' => $expectedVersion],
        );
    }

    /** @return array<string, mixed> */
    private static function columns(LifeInsuranceContract $contract): array
    {
        return [
            'insurer' => $contract->insurer,
            'distributor' => $contract->distributor,
            'commercial_name' => $contract->commercialName,
            'contract_number_suffix' => $contract->numberSuffix?->value,
            'subscribed_on' => $contract->subscribedOn->format('Y-m-d'),
            'tax_start_on' => $contract->taxStartOn->format('Y-m-d'),
            'management_mode' => $contract->managementMode->value,
            'status' => $contract->status->value,
            'closed_on' => $contract->closedOn?->format('Y-m-d'),
            'updated_at' => $contract->updatedAt->format('Y-m-d H:i:s.uP'),
            'version' => $contract->version,
        ];
    }

    private function one(WorkspaceScope $workspace, string $where, string $key, bool $forUpdate): ?LifeInsuranceContract
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM portfolio_life_insurance_contracts WHERE workspace_id = :workspace_id AND '.$where.($forUpdate ? ' FOR UPDATE' : ''),
            ['workspace_id' => $workspace->id, 'key' => $key],
        );

        return false === $row ? null : self::hydrate($row, $workspace);
    }

    /** @param array<string, mixed> $row */
    private static function hydrate(array $row, WorkspaceScope $workspace): LifeInsuranceContract
    {
        $text = InstrumentRow::text(...);
        $suffix = $row['contract_number_suffix'];

        return new LifeInsuranceContract(
            id: $text($row['id']),
            workspace: $workspace,
            accountId: $text($row['account_id']),
            insurer: $text($row['insurer']),
            distributor: null === $row['distributor'] ? null : $text($row['distributor']),
            commercialName: null === $row['commercial_name'] ? null : $text($row['commercial_name']),
            numberSuffix: null === $suffix ? null : ContractNumberSuffix::fromStored($text($suffix)),
            subscribedOn: ValuationRow::day($row['subscribed_on']),
            taxStartOn: ValuationRow::day($row['tax_start_on']),
            managementMode: ManagementMode::from($text($row['management_mode'])),
            status: ContractStatus::from($text($row['status'])),
            closedOn: null === $row['closed_on'] ? null : ValuationRow::day($row['closed_on']),
            createdAt: new \DateTimeImmutable($text($row['created_at'])),
            createdBy: $text($row['created_by']),
            updatedAt: new \DateTimeImmutable($text($row['updated_at'])),
            version: InstrumentRow::integer($row['version']),
        );
    }
}
