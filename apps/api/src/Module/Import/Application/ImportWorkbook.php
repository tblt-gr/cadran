<?php

declare(strict_types=1);

namespace App\Module\Import\Application;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Import\Domain\WorkbookUsageError;
use App\Module\Reference\Application\AssetCatalog;

/**
 * Imports one yearly workbook: assumes the owner, reads the mapping and the
 * workbook, plans the whole year against the workspace and, unless it is a dry
 * run or the plan holds a rejection, writes it in one transaction.
 */
final readonly class ImportWorkbook
{
    private const string ASSET = 'EUR';

    public function __construct(
        private ImportActor $actor,
        private MappingReader $mappings,
        private WorkbookReader $workbooks,
        private ImportPlanner $planner,
        private ImportExecutor $executor,
        private AssetCatalog $assets,
    ) {
    }

    /**
     * @throws WorkbookUsageError an unreadable file, a bad mapping, an unknown owner
     */
    public function __invoke(ImportWorkbookRequest $request): ImportReport
    {
        $this->actor->assume($request->email);
        $mapping = $this->mappings->read($request->mappingPath);
        $asset = $this->assets->findByCode(AssetCode::fromString(self::ASSET)) ?? throw new WorkbookUsageError('The EUR asset is missing from the reference.');
        $workbook = $this->workbooks->read($request->workbookPath, $request->year, $asset->precision->display, $mapping->cellOverrides());

        $plan = $this->planner->plan($workbook, $mapping);
        if ($request->dryRun || $plan->hasRejections() || [] === $plan->steps) {
            return new ImportReport($plan, false, null);
        }

        try {
            ($this->executor)($plan);
        } catch (ImportExecutionFailed $failure) {
            return new ImportReport($plan, false, $failure);
        }

        return new ImportReport($plan, true, null);
    }
}
