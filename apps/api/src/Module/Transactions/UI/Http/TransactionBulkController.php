<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\BulkEditResult;
use App\Module\Transactions\Application\BulkEditTransactions;
use App\Module\Transactions\Application\BulkPreview;
use App\Module\Transactions\Application\IdempotentExecution;
use App\Module\Transactions\Application\IdempotentResponse;
use App\Module\Transactions\Application\PreviewBulkEdit;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Editing the fields of many transactions at once. */
final readonly class TransactionBulkController
{
    public function __construct(
        private TransactionHttpEnvelope $envelope,
        private ProblemMapper $problems,
        private IdempotentExecution $idempotentExecution,
        private BulkEditPayload $payload,
    ) {
    }

    #[Route('/api/v1/transactions/bulk-edit', name: 'api_v1_transactions_bulk_edit', methods: ['POST'])]
    public function edit(Request $request, BulkEditTransactions $bulkEdit, PreviewBulkEdit $previewBulk): Response
    {
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $input = $this->payload->parse($body);
            if ($input->preview) {
                // Writes nothing, so it needs no idempotency key and ignores one if sent.
                return $this->envelope->json(self::previewRepresentation($previewBulk($input)));
            }
            $result = $this->idempotentExecution->execute(
                'transaction.bulk_edit',
                $this->envelope->idempotency($request, true),
                static function () use ($bulkEdit, $input): IdempotentResponse {
                    $outcome = $bulkEdit($input);

                    return new IdempotentResponse(self::representation($outcome), Response::HTTP_OK, null, $outcome->periodDays);
                },
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::bulk());
        }

        return $this->envelope->json($result->body, $result->status, $result->replayed ? ['Idempotency-Replayed' => 'true'] : []);
    }

    /** @return array<string, mixed> */
    private static function representation(BulkEditResult $result): array
    {
        $skipped = [];
        foreach ($result->skipped as $id => $reason) {
            $skipped[] = ['id' => $id, 'reason' => $reason->value];
        }

        return [
            'requestedCount' => $result->requested,
            'changed' => $result->changed,
            'skipped' => $skipped,
            'fieldsChanged' => $result->fieldsChanged,
            'impactedAccountIds' => $result->accountIds,
        ];
    }

    /** @return array<string, mixed> */
    private static function previewRepresentation(BulkPreview $preview): array
    {
        $skipped = [];
        foreach ($preview->skipped as $id => $reason) {
            $skipped[] = ['id' => $id, 'reason' => $reason->value];
        }

        return [
            'eligibleCount' => $preview->eligible,
            'skipped' => $skipped,
            'impacts' => array_map(static fn ($impact): array => [
                'accountId' => $impact->accountId,
                'label' => $impact->label,
                'assetCode' => $impact->assetCode,
                'delta' => $impact->delta,
                'from' => $impact->from,
                'to' => $impact->to,
            ], $preview->impacts),
            'previewToken' => $preview->token,
        ];
    }
}
