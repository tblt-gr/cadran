<?php

declare(strict_types=1);

namespace App\Module\Import\UI\Console;

use App\Module\Import\Application\ImportReport;
use App\Module\Import\Application\ImportWorkbook;
use App\Module\Import\Application\ImportWorkbookRequest;
use App\Module\Import\Application\MappingSkeleton;
use App\Module\Import\Application\WorkbookReader;
use App\Module\Import\Domain\ArchiveRefused;
use App\Module\Import\Domain\Mapping\InvalidMapping;
use App\Module\Import\Domain\Plan\PlannedBalance;
use App\Module\Import\Domain\Plan\PlannedBuy;
use App\Module\Import\Domain\Plan\PlannedDeposit;
use App\Module\Import\Domain\Plan\PlannedRefund;
use App\Module\Import\Domain\Plan\PlannedStep;
use App\Module\Import\Domain\Plan\PlannedTransaction;
use App\Module\Import\Domain\Plan\PlannedTransfer;
use App\Module\Import\Domain\Plan\PlannedValuation;
use App\Module\Import\Domain\ReasonCode;
use App\Module\Import\Domain\Workbook\Warning;
use App\Module\Import\Domain\WorkbookUsageError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-shot migration of a yearly budget workbook (transitional, removed by IMP-007).
 *
 * The output is counts, sheet names, cell coordinates and reason codes. Labels
 * and amounts are printed only with `--dry-run --show-details`, to the terminal
 * and nowhere else; nothing here goes to the logger.
 *
 * Exit codes: 0 no rejection (dry run) or committed, 1 rejected, 2 usage error.
 */
#[AsCommand(
    name: 'app:import:workbook',
    description: 'Import a yearly .xlsx budget workbook with a local mapping file, or write a mapping skeleton (transitional).',
)]
final class ImportWorkbookCommand extends Command
{
    public function __construct(
        private readonly ImportWorkbook $import,
        private readonly WorkbookReader $workbooks,
        private readonly MappingSkeleton $skeleton,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('files', InputArgument::IS_ARRAY, 'The .xlsx workbook to import; with --emit-mapping, every workbook to read.');
        $this->addOption('mapping', null, InputOption::VALUE_REQUIRED, 'The mapping YAML file, kept outside the repository.');
        $this->addOption('emit-mapping', null, InputOption::VALUE_REQUIRED, 'Write a mapping skeleton to this new file instead of importing.');
        $this->addOption('year', null, InputOption::VALUE_REQUIRED, 'The year of the workbook; by default the four leading digits of its file name.');
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Parse, map and plan the whole year without writing any business record.');
        $this->addOption('show-details', null, InputOption::VALUE_NONE, 'With --dry-run, print labels and amounts to the terminal.');
        $this->addOption('email', null, InputOption::VALUE_REQUIRED, 'Owner email of the target workspace; defaults to the provisioned owner.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            if (!class_exists(\ZipArchive::class)) {
                throw new WorkbookUsageError('The zip extension is missing: run the import from the tooling container (make import-workbook).');
            }

            return null !== $input->getOption('emit-mapping') ? $this->emitMapping($input, $io) : $this->importYear($input, $io);
        } catch (WorkbookUsageError $error) {
            $io->error($error->getMessage());

            return Command::INVALID;
        } catch (InvalidMapping $invalid) {
            $io->error('The mapping file is invalid:');
            foreach ($invalid->problems as $problem) {
                $io->writeln(sprintf('  %s %s', $problem->code->value, $problem->location));
            }

            return Command::INVALID;
        } catch (ArchiveRefused $refused) {
            $io->error(sprintf('%s: %s', ReasonCode::ARCHIVE_REFUSED->value, $refused->getMessage()));

            return Command::FAILURE;
        }
    }

    private function emitMapping(InputInterface $input, SymfonyStyle $io): int
    {
        $files = $this->files($input);
        if ([] === $files) {
            throw new WorkbookUsageError('Give at least one workbook to read.');
        }
        $target = $this->outputPath($input);

        $parsed = array_map(fn (string $file) => $this->workbooks->read($file, $this->year($input)), $files);
        $this->writeNew($target, $this->skeleton->render($parsed));
        $io->success(sprintf('Mapping skeleton written for %d workbook(s). Fill every null value in, then import with --mapping.', count($files)));

        return Command::SUCCESS;
    }

    private function importYear(InputInterface $input, SymfonyStyle $io): int
    {
        $files = $this->files($input);
        $mapping = $input->getOption('mapping');
        if (1 !== count($files) || !is_string($mapping) || '' === $mapping) {
            throw new WorkbookUsageError('Give exactly one workbook and --mapping (or --emit-mapping to write a skeleton).');
        }
        $dryRun = true === $input->getOption('dry-run');
        $details = true === $input->getOption('show-details');
        if ($details && !$dryRun) {
            throw new WorkbookUsageError('--show-details prints labels and amounts and is only available with --dry-run.');
        }
        $email = $input->getOption('email');

        $report = ($this->import)(new ImportWorkbookRequest($files[0], $mapping, $this->year($input), $dryRun, is_string($email) && '' !== $email ? $email : null));
        $this->report($io, $report, $dryRun, $details);

        return $report->isRejected() ? Command::FAILURE : Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, ImportReport $report, bool $dryRun, bool $details): void
    {
        $plan = $report->plan;
        $io->title(sprintf('Workbook %d: %s', $plan->year, $dryRun ? 'DRY RUN' : 'IMPORT'));

        $totals = [];
        foreach ($plan->countsByMonth() as $month => $counts) {
            $io->writeln(sprintf('Month %02d: %s', $month, self::counts($counts)));
            foreach ($counts as $kind => $count) {
                $totals[$kind] = ($totals[$kind] ?? 0) + $count;
            }
        }
        $io->writeln('Total: '.([] === $totals ? 'nothing to create' : self::counts($totals)));
        $count = static fn (string $code): int => count(array_filter($plan->warnings, static fn (Warning $warning): bool => $code === $warning->code));
        $io->writeln(sprintf(
            'Ignored sheets: %d; lines ignored by the mapping: %d; float artifacts normalized: %d; values rounded: %d; cached values used: %d',
            count($plan->ignoredSheets), $plan->ignoredLines, $count(Warning::FLOAT_ARTIFACT_NORMALIZED), $count(Warning::VALUE_ROUNDED), $count(Warning::CACHED_VALUE_USED),
        ));

        foreach (self::warningLines($plan->warnings) as $line) {
            $io->writeln($line);
        }
        foreach ($plan->exclusions() as $issue) {
            $io->writeln(sprintf('EXCLUDED %s %s %s', $issue->code->value, $issue->sheet ?? '-', $issue->cell ?? '-'));
        }
        foreach ($plan->rejections() as $issue) {
            $io->writeln(sprintf('REJECTED %s %s %s', $issue->code->value, $issue->sheet ?? '-', $issue->cell ?? '-'));
        }
        if (null !== $report->failure) {
            $failure = $report->failure;
            $io->writeln(sprintf('REJECTED %s %s %s', $failure->reason->value ?? $failure->cause, $failure->sheet ?? '-', $failure->cell ?? '-'));
        }

        if ($details) {
            foreach ($plan->steps as $step) {
                $io->writeln(self::detail($step));
            }
        }

        match (true) {
            $report->committed => $io->success('COMMITTED: the year was written in one transaction.'),
            $report->isRejected() => $io->error('REJECTED: nothing was written.'),
            [] === $plan->steps && !$dryRun => $io->success('NOTHING TO IMPORT: the plan holds no step. Nothing was written.'),
            default => $io->success('DRY RUN: no rejection. Nothing was written.'),
        };
    }

    /**
     * One line per warning code and sheet, naming the cells: a float artifact was normalized or a value rounded there, no amount is shown.
     *
     * @param list<Warning> $warnings
     *
     * @return list<string>
     */
    private static function warningLines(array $warnings): array
    {
        $grouped = [];
        foreach ($warnings as $warning) {
            $grouped[$warning->code.' '.$warning->sheet][] = $warning->cell;
        }

        return array_map(static fn (string $group, array $cells): string => 'WARNING '.$group.' '.implode(' ', $cells), array_keys($grouped), $grouped);
    }

    /**
     * @param array<string, int> $counts
     */
    private static function counts(array $counts): string
    {
        return implode(', ', array_map(static fn (string $kind, int $count): string => $kind.' '.$count, array_keys($counts), $counts));
    }

    /** Labels and amounts: reached only through --dry-run --show-details. */
    private static function detail(PlannedStep $step): string
    {
        $where = sprintf('(%s %s)', $step->sheet(), $step->cell() ?? '-');

        return match (true) {
            $step instanceof PlannedTransaction => sprintf('  %s %s %s %s %s', $step->kind()->name, $step->bookedOn, $step->amount->toString(), $step->rawLabel, $where),
            $step instanceof PlannedRefund => sprintf('  REFUND %s %s %s %s', $step->bookedOn, $step->amount->toString(), $step->rawLabel, $where),
            $step instanceof PlannedTransfer => sprintf('  TRANSFER %s %s %s %s', $step->bookedOn, $step->amount->toString(), $step->label, $where),
            $step instanceof PlannedDeposit => sprintf('  DEPOSIT %s %s %s', $step->bookedOn, $step->amount->toString(), $where),
            $step instanceof PlannedBuy => sprintf('  BUY %s %s x %s = %s %s %s', $step->bookedOn, $step->quantity->toString(), $step->unitPrice->toString(), $step->gross->toString(), $step->instrument, $where),
            $step instanceof PlannedBalance => sprintf('  BALANCE %s %s %s', $step->asOf, $step->amount->toString(), $where),
            $step instanceof PlannedValuation => sprintf('  VALUATION %s total %s cash %s %s', $step->valuedOn, $step->total->toString(), $step->cash->toString(), $where),
            default => '  '.$step->kind()->name.' '.$where,
        };
    }

    /**
     * @return list<string>
     */
    private function files(InputInterface $input): array
    {
        $files = $input->getArgument('files');

        return is_array($files) ? array_values(array_filter($files, 'is_string')) : [];
    }

    private function year(InputInterface $input): ?int
    {
        $year = $input->getOption('year');
        if (null === $year || '' === $year) {
            return null;
        }
        if (!is_string($year) || 1 !== preg_match('/^[0-9]{4}$/D', $year)) {
            throw new WorkbookUsageError('The year is four digits.');
        }

        return (int) $year;
    }

    /** A new file outside the repository: the skeleton lists real labels and must never be committed or overwrite a filled mapping. */
    private function outputPath(InputInterface $input): string
    {
        $target = $input->getOption('emit-mapping');
        if (!is_string($target) || '' === $target) {
            throw new WorkbookUsageError('Give the path of the mapping skeleton to write.');
        }
        $directory = realpath(dirname($target));
        if (false === $directory || !is_dir($directory) || !is_writable($directory)) {
            throw new WorkbookUsageError('The directory of the mapping skeleton does not exist or is not writable.');
        }
        $repository = realpath(dirname($this->projectDir, 2));
        if (false !== $repository && ($directory === $repository || str_starts_with($directory.'/', $repository.'/'))) {
            throw new WorkbookUsageError('The mapping skeleton lists real labels: write it outside the repository.');
        }

        return $directory.'/'.basename($target);
    }

    /** Mode `x` creates the file atomically and refuses any existing entry, a symlink included: a skeleton is never overwritten. */
    private function writeNew(string $path, string $content): void
    {
        $previous = umask(0o177);
        try {
            $handle = @fopen($path, 'x');
        } finally {
            umask($previous);
        }
        if (false === $handle) {
            throw new WorkbookUsageError(file_exists($path) || is_link($path) ? 'The mapping skeleton already exists: it is never overwritten.' : 'The mapping skeleton could not be written.');
        }
        $written = fwrite($handle, $content);
        fclose($handle);
        if (false === $written || $written !== strlen($content)) {
            throw new WorkbookUsageError('The mapping skeleton could not be written.');
        }
    }
}
