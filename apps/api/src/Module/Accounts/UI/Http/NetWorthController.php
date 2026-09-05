<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\InvalidNetWorthQuery;
use App\Module\Accounts\Application\NetWorthScopeTooLarge;
use App\Module\Accounts\Application\ReadNetWorth;
use App\Module\Accounts\Application\ReadNetWorthHistory;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adapts HTTP to the net-worth read model. The aggregation itself belongs to
 * the domain: nothing here adds, divides or rounds a figure.
 */
final readonly class NetWorthController
{
    public const string TYPE_SCOPE_TOO_LARGE = '/problems/net-worth-scope-too-large';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/net-worth', name: 'api_v1_net_worth_read', methods: ['GET'])]
    public function read(Request $request, ReadNetWorth $readNetWorth): Response
    {
        $asOf = $request->query->getString('asOf');
        $comparedTo = $request->query->getString('comparedTo');
        if (!self::isoDayOrEmpty($asOf) || !self::isoDayOrEmpty($comparedTo)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_net_worth_query');
        }

        try {
            $netWorth = $readNetWorth($asOf ?: null, $comparedTo ?: null);
        } catch (InvalidNetWorthQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_net_worth_query');
        } catch (NetWorthScopeTooLarge) {
            return $this->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.net_worth_scope_too_large',
                self::TYPE_SCOPE_TOO_LARGE,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.net_worth_forbidden');
        }

        return self::json(NetWorthRepresentation::of($netWorth));
    }

    #[Route('/api/v1/net-worth/history', name: 'api_v1_net_worth_history', methods: ['GET'])]
    public function history(Request $request, ReadNetWorthHistory $readHistory): Response
    {
        $asOf = $request->query->getString('asOf');
        $months = $request->query->getString('months');
        if (!self::isoDayOrEmpty($asOf) || !self::unsignedIntegerOrEmpty($months)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_net_worth_query');
        }

        try {
            $history = $readHistory($asOf ?: null, '' === $months ? null : (int) $months);
        } catch (InvalidNetWorthQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_net_worth_query');
        } catch (NetWorthScopeTooLarge) {
            return $this->problem(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                'api.problem.net_worth_scope_too_large',
                self::TYPE_SCOPE_TOO_LARGE,
            );
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.net_worth_forbidden');
        }

        return self::json(NetWorthRepresentation::history($history));
    }

    private static function isoDayOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value);
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,3}$/D', $value);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse($data, Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $translationKey, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
        );
    }
}
