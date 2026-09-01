<?php

declare(strict_types=1);

namespace App\Module\Reference\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Reference\Application\AssetNotFound;
use App\Module\Reference\Application\AssetPage;
use App\Module\Reference\Application\InvalidAssetQuery;
use App\Module\Reference\Application\ListAssets;
use App\Module\Reference\Application\ReadAsset;
use App\Module\Reference\Domain\Asset;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Read-only view of the system asset reference. The catalogue is global and
 * identical for every session, so no workspace is resolved here; the firewall
 * still denies an anonymous request under /api/, because the precisions an
 * install runs on are not public information.
 */
final class AssetCatalogController
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/assets', name: 'api_v1_assets_list', methods: ['GET'])]
    public function list(Request $request, ListAssets $listAssets): Response
    {
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');

        foreach ([$page, $perPage] as $parameter) {
            if ('' !== $parameter && 1 !== preg_match('/^[0-9]{1,4}$/D', $parameter)) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_asset_query');
            }
        }

        try {
            $catalogue = $listAssets(
                '' === $page ? null : (int) $page,
                '' === $perPage ? null : (int) $perPage,
            );
        } catch (InvalidAssetQuery) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_asset_query');
        }

        return self::jsonPage($catalogue);
    }

    #[Route('/api/v1/assets/{code}', name: 'api_v1_assets_read', methods: ['GET'])]
    public function read(string $code, ReadAsset $readAsset): Response
    {
        try {
            $asset = $readAsset($code);
        } catch (AssetNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.asset_not_found');
        }

        return self::json(self::represent($asset));
    }

    private static function jsonPage(AssetPage $catalogue): JsonResponse
    {
        return self::json([
            'items' => array_map(self::represent(...), $catalogue->assets),
            'page' => $catalogue->page,
            'perPage' => $catalogue->perPage,
            'total' => $catalogue->total,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function represent(Asset $asset): array
    {
        $step = $asset->displayStep();

        return [
            'code' => $asset->code->toString(),
            'kind' => $asset->kind->value,
            'displayName' => $asset->displayName,
            'storagePrecision' => $asset->precision->storage,
            'displayPrecision' => $asset->precision->display,
            'roundingMode' => $asset->roundingMode->value,
            // A decimal always leaves as a canonical string paired with its
            // asset. A JSON number would hand the client a binary float.
            'displayStep' => [
                'value' => $step->value->toString(),
                'assetCode' => $step->asset->toString(),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse(data: $data, headers: ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $translationKey): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
        );
    }
}
