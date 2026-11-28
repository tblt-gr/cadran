<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonPayload;
use App\Module\Foundation\UI\Http\ProblemMapper;
use App\Module\Transactions\Application\ReplaceTransactionSplits;
use App\Module\Transactions\Application\ReplaceTransactionSplitsInput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Replacing a transaction's splits: the one write specific to that sub-resource. */
final readonly class TransactionSplitsController
{
    public function __construct(private TransactionHttpEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/transactions/{id}/splits', name: 'api_v1_transactions_replace_splits', methods: ['PUT'])]
    public function replaceSplits(string $id, Request $request, ReplaceTransactionSplits $replaceTransactionSplits): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.transaction_not_found');
        }
        $body = $this->envelope->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = JsonPayload::of($body, ['version', 'splits']);
            $input = new ReplaceTransactionSplitsInput(
                splits: TransactionPayload::splitRows($payload, 'splits'),
                version: $payload->integer('version'),
            );
            $transaction = $replaceTransactionSplits($id, $input);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, TransactionProblems::replaceSplits());
        }

        return $this->envelope->json(TransactionRepresentation::one($transaction));
    }
}
