<?php

declare(strict_types=1);

namespace App\Module\Transactions\Infrastructure\Persistence;

use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\IdempotencyKey;
use App\Module\Transactions\Domain\IdempotencyKeyRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(IdempotencyKeyRepository::class)]
final readonly class DbalIdempotencyKeyRepository implements IdempotencyKeyRepository
{
    public function __construct(private Connection $connection, private UuidGenerator $uuidGenerator)
    {
    }

    public function begin(WorkspaceScope $workspace, string $useCase, string $key, string $fingerprint, \DateTimeImmutable $now, \DateTimeImmutable $expiresAt): IdempotencyKey
    {
        $criteria = ['workspace_id' => $workspace->id, 'use_case' => $useCase, 'idempotency_key' => $key];
        $this->connection->executeStatement(
            'DELETE FROM transaction_idempotency_keys WHERE workspace_id = :workspace_id AND use_case = :use_case AND idempotency_key = :idempotency_key AND expires_at <= :now',
            [...$criteria, 'now' => $now->format('Y-m-d H:i:s.uP')],
        );
        $id = $this->newId();
        $written = $this->connection->executeStatement(
            'INSERT INTO transaction_idempotency_keys (id, workspace_id, use_case, idempotency_key, request_fingerprint, status, created_at, expires_at) VALUES (:id, :workspace_id, :use_case, :idempotency_key, :fingerprint, :status, :created_at, :expires_at) ON CONFLICT (workspace_id, use_case, idempotency_key) DO NOTHING',
            [
                'id' => $id, 'workspace_id' => $workspace->id, 'use_case' => $useCase, 'idempotency_key' => $key,
                'fingerprint' => $fingerprint, 'status' => 'IN_FLIGHT', 'created_at' => $now->format('Y-m-d H:i:s.uP'),
                'expires_at' => $expiresAt->format('Y-m-d H:i:s.uP'),
            ],
        );
        if (1 === $written) {
            return new IdempotencyKey($id, $fingerprint, 'IN_FLIGHT', null, null, true);
        }

        $row = $this->connection->fetchAssociative(
            'SELECT id, request_fingerprint, status, response_status, response_body FROM transaction_idempotency_keys WHERE workspace_id = :workspace_id AND use_case = :use_case AND idempotency_key = :idempotency_key FOR UPDATE',
            $criteria,
        );
        if (false === $row) {
            throw new \UnexpectedValueException('An idempotency key disappeared while it was claimed.');
        }

        /** @var array<string, mixed>|null $body */
        $body = null;
        if (null !== ($row['response_body'] ?? null)) {
            $decoded = json_decode(self::text($row['response_body']), true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new \UnexpectedValueException('An idempotency response must be an object.');
            }
            $body = [];
            foreach ($decoded as $field => $value) {
                if (!is_string($field)) {
                    throw new \UnexpectedValueException('An idempotency response field must be a string.');
                }
                $body[$field] = $value;
            }
        }

        return new IdempotencyKey(
            self::text($row['id'] ?? null), self::text($row['request_fingerprint'] ?? null), self::text($row['status'] ?? null),
            null === ($row['response_status'] ?? null) ? null : (int) self::text($row['response_status']), $body, false,
        );
    }

    public function complete(WorkspaceScope $workspace, IdempotencyKey $key, int $responseStatus, array $responseBody, ?string $entityId, \DateTimeImmutable $completedAt): void
    {
        $written = $this->connection->update(
            'transaction_idempotency_keys',
            [
                'status' => 'COMPLETED', 'response_status' => $responseStatus,
                'response_body' => json_encode($responseBody, JSON_THROW_ON_ERROR), 'entity_id' => $entityId,
                'completed_at' => $completedAt->format('Y-m-d H:i:s.uP'),
            ],
            ['workspace_id' => $workspace->id, 'id' => $key->id, 'status' => 'IN_FLIGHT'],
        );
        if (1 !== $written) {
            throw new \UnexpectedValueException('The claimed idempotency key could not be completed.');
        }
    }

    public function purgeExpired(\DateTimeImmutable $now, int $limit): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM transaction_idempotency_keys WHERE ctid IN (SELECT ctid FROM transaction_idempotency_keys WHERE expires_at <= :now ORDER BY expires_at LIMIT :limit)',
            ['now' => $now->format('Y-m-d H:i:s.uP'), 'limit' => $limit],
            ['limit' => ParameterType::INTEGER],
        );
    }

    private function newId(): string
    {
        return $this->uuidGenerator->generate();
    }

    private static function text(mixed $value): string
    {
        if (!is_scalar($value)) {
            throw new \UnexpectedValueException('Expected a scalar database value.');
        }

        return (string) $value;
    }
}
