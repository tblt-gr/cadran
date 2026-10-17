# Import module (transitional)

One-shot migration of the owner's yearly `.xlsx` budget workbooks into Cadran (ticket `IMP-006`).
The module exists to load about four workbooks once, and it is removed by `IMP-007` once the
history is imported and reconciled. Keep it minimal: no migration, no HTTP, no screen.

It depends only on the public application services of `Accounts`, `Categories`, `Transactions`
and `Portfolio` (plus `Foundation` and `Identity` for the owner token). Nothing depends on it.

## What is here

The parsing half (no database):

- `Domain`: label normalization (`LabelKey`), the constant formula grammar and its exact evaluator
  (`Formula\ConstantFormula`, one `FormulaTerm` per top-level term), the amount reader that checks
  a movement against the cached spreadsheet value (`Amount\AmountCellReader`), reason codes and
  located issues, source references (`SourceReference`), the parsed workbook model
  (`Workbook\ParsedWorkbook`) and the validated mapping (`Mapping\ImportMapping`).
- `Application`: the ports `WorkbookReader` and `MappingReader`, and `MappingSkeleton`, which writes
  the mapping skeleton for `--emit-mapping`.
- `Infrastructure`: the streaming `.xlsx` reader (`Xlsx\XlsxPackage`, ZipArchive and XMLReader, with
  every archive and XML control) and the YAML loader (`Mapping\YamlMappingReader`).

## Planning and execution

The second half reads the workspace and writes it, only through the public application services of
`Accounts`, `Categories`, `Transactions` and `Portfolio`:

- `Application\ImportWorkbook` runs one year: it assumes the owner (`ImportActor`, implemented by
  `Infrastructure\ConsoleOwnerActor` exactly as the demo seed does, so the normal caller context
  resolves the workspace), reads the mapping and the workbook, plans the year and, unless it is a
  dry run or the plan holds a rejection, writes it. It returns an `ImportReport`.
- `Application\ImportPlanner` turns a `ParsedWorkbook` and an `ImportMapping` into an
  `Domain\Plan\ImportPlan` (ordered `PlannedStep`s, instruments to create, every issue) without
  writing anything. Workspace facts come through the `ImportReferences` port, implemented by
  `Infrastructure\Workspace\ServiceImportReferences`. The planners of each block live in
  `Application\Planning`. Within a month the order is: opening records (first month only), income,
  fixed and variable expenses, refunds, savings transfers (income-block transfers first), portfolio deposits, portfolio purchases,
  balance snapshots, portfolio valuations; every date is the last day of the sheet's month, and the
  opening records are dated on the last day of the month before the first sheet.
- `Application\ImportExecutor` writes a plan in one PostgreSQL transaction. It first checks the year
  again, then creates the missing instruments (`source: IMPORT`) and each step through `CreateTransaction`,
  `CreateRefund`, `CreateTransfer`, `RecordAccountBalance`, `RecordPortfolioOperation`,
  `RecordPortfolioValuation` and `CreateInstrument`. A refusal of any use case rolls the whole year back and
  surfaces as `ImportExecutionFailed`, which carries the sheet, the cell and a reason code (or the
  short class name of the refusal), never the message of the cause.
- A plan with no step (for instance every month is `MONTH_NOT_ENDED`) never reaches the executor: the command
  prints `NOTHING TO IMPORT` and exits 0.
- `UI\Console\ImportWorkbookCommand` (`app:import:workbook`) is the only entry point; see the Make
  target `import-workbook` (`scripts/import-workbook.sh`) for the paths it accepts.

The module needed two additive changes outside itself: `Transactions\Application\ReadImportedWorkbookYears`
(the years of the `wb:` source references of a workspace, for the replay checks), `HasAccountTransactionBefore` (the planner's "history before the opening day" check) and the optional
`CreateInstrumentInput::$source` (`IMPORT`), which the HTTP API never sets.

A dry run does not predict an instrument refusal at write time: the planner only sees the names of the
instruments the workspace lists, while `CreateInstrument` also refuses an ISIN that already exists or a
duplicate candidate, archived instruments included. A dry run that reports no rejection can therefore still end
in a real run that rolls back, printed as `REJECTED` followed by the short class name of the refusal in place of a
reason code (and the sheet and cell of the instrument column, when known), with `REJECTED: nothing was written.`

Rules the planner settles where the ticket leaves a choice:

- A refund links to the largest planned expense of the same Cadran category and month that can still
  absorb it (same line first, then any line; the earliest on a tie); none means `UNLINKABLE_REFUND`.
- A negative purchase term in `PEA` or `CTO_TR`, a negative term that would leave a portfolio account
  and a negative opening deposit are `FORMULA_NOT_SUPPORTED`: the workbook never describes a sale, and a
  negative buy is never imported.
- Months against the clock: a sheet whose first day is after today is left out whole, with its PEA and
  CTO_TR rows and its reading issues, and reported once as `MONTH_NOT_ENDED` (an exclusion, not a
  rejection). The month in progress is imported with every record dated today instead of the last day of
  the month; the opening records keep the last day of the previous month. A booking that would still fall
  after today is a `MONTH_NOT_ENDED` rejection; `ACCOUNT_OPENED_AFTER_DATE` only means an account opened
  after the record.
- The raw label of a record is cut to the 140 characters a transaction accepts. A source reference longer
  than the 128 characters of its column keeps the start of its label key and ends it with `-` and the first
  eight hex characters of the SHA-256 of the whole key, so it fits and stays unique. The opening records of
  the portfolio sheets use sheet-qualified keys (`OPEN:pea-d:1`, `OPEN:cto-tr-d:1`).
- An instrument the workspace lacks must carry an ISIN or a ticker in the mapping (the portfolio refuses
  an anonymous instrument); otherwise its column is `UNMAPPED_LABEL`.
- A portfolio valuation needs the cash of its row: a securities value without cash is excluded with
  `LABEL_WITHOUT_AMOUNT`, never valued with a zero cash.
- An income line mapped `{ transfer_from: "Livret A" }` (instead of `category`; `account` is the destination, the main
  account by default) is not income: each positive movement becomes one `CreateTransfer` from that account into the
  destination, planned in the savings stage before the SAV transfers, with the usual `wb:{year}:{mm}:INC:{labelKey}:{ordinal}`
  reference in the transfer note. A negative movement stays `NEGATIVE_INCOME`; an unknown, non-EUR, portfolio or
  identical source is `UNMAPPED_ACCOUNT`. An income entry names exactly one of `category` and `transfer_from` (an
  explicit `category: null` stays a label not mapped yet), and the source must differ from an explicit destination or the
  `main_account`; otherwise the mapping is refused with `INVALID_VALUE` at the entry.
- A portfolio sheet is anchored on its first `Janvier` row below the headers (row 2): the row just above it is the
  opening state (previous December, and it must lie below the headers, otherwise `UNKNOWN_LAYOUT`), the twelve rows from
  `Janvier` are the months matched by name, and later rows (the recap) are ignored. The standard sheet (opening on row 3,
  months on rows 4 to 15) and a sheet shifted by a row or more read alike. No `Janvier` while data rows exist, or values
  between the headers and the opening row, are `UNKNOWN_LAYOUT`.
- A `PEA` or `CTO_TR` purchase product `q*p` whose exact value has more decimals than the cash asset (`=12*25.1234`) is one
  buy: quantity and unit price stay exact (instrument precisions 8 and 4, else `PRECISION_EXCEEDED`) and the gross is the
  exact product rounded half-even to the cash precision (301.48). The cached value of the cell is still compared with the
  exact sum of the terms. A plain number or a sum beyond the cash precision stays `PRECISION_EXCEEDED`.
- Valuations with more decimals than the asset precision are rounded half-even to it and reported as the warning
  `VALUE_ROUNDED` (sheet and cell, never the value): the securities value and the cash of the portfolio sheets, and the K/L
  balance of a `SNAPSHOTS` account. A `TRANSACTIONS` account stays strict (`PRECISION_EXCEEDED`), and deposits keep their own
  rules. The reading problems of the account table K/L cells are recorded on the row and raised by the planner only for a row
  mapped to a non-portfolio account; a row mapped to a `PORTFOLIO` account never yields a finding from K/L.
- Per-month cell overrides sit under the mapping's `overrides` key, next to `pea_quantity_first`, keyed `"YYYY-MM"`.
  Cells are coordinates on the month sheet, only in the movement amount columns `B`, `D`, `F`, `H` (row 2 or below),
  otherwise the mapping is refused with `INVALID_VALUE` located by position. `cached_cells: ["B2"]`: the formula of the
  cell is not parsed (a reference is allowed) and the cell is read as one movement equal to its cached value, with the
  rules of a plain number (float-artifact tolerance, `PRECISION_EXCEEDED`, `ZERO_AMOUNT`, the usual sign rules, and
  `CACHED_VALUE_MISMATCH` without a cached value); each one reports the warning `CACHED_VALUE_USED` (sheet and cell).
  `skip_cells: { "H4": "reason" }`: the whole movement is left out and reported as the exclusion `SKIPPED_BY_MAPPING`
  (sheet and cell); the reason is required, at most 200 characters, and is never printed. A skipped row consumes no
  ordinal and does not change which labels must be mapped. A cell both cached and skipped, or an override of the
  workbook's year for a month that has no sheet in it, is a mapping problem (exit 2). Overrides of other years are
  ignored.
- The opening row of a portfolio sheet (previous December) is read lazily: its reading problems wait on the row and reach
  the plan only when the planner builds the opening state from it (the first month sheet comes after the first month the
  portfolio sheet holds data for).
- Two account rows of one month that land on the same Cadran account are `UNMAPPED_ACCOUNT`.
- Source references: transactions carry theirs as `sourceRef`; transfers, refunds, deposits, purchases,
  snapshots and valuations carry it in their note or reference field.

## Entry points of the parsing half

- `WorkbookReader::read($path, $year)` gives a `ParsedWorkbook`: month sheets, PEA and CTO_TR rows,
  ignored sheet names, every `Issue` (rejections and exclusions, located by sheet and cell) and
  warnings (`FLOAT_ARTIFACT_NORMALIZED`, `VALUE_ROUNDED`, `CACHED_VALUE_USED`). Amounts are exact decimals at the asset precision (2 for EUR).
  `ParsedMovement::referenceIn()` builds the source reference of the record a movement becomes.
- `MappingReader::read($path)` gives an `ImportMapping`. Lookups take normalized label keys and
  return null for an absent or still-null entry, which the planner reports as `UNMAPPED_LABEL` or
  `UNMAPPED_ACCOUNT`.
- Errors: `WorkbookUsageError` and `InvalidMapping` are usage errors (exit 2); `ArchiveRefused`
  carries `ARCHIVE_REFUSED` (exit 1).

## Security notes

The archive controls (20 MB file, 512 entries, 50 MB per part, compression ratio 100, no
`<!DOCTYPE`/`<!ENTITY`, safe entry names, parts resolved from relationships only) live in
`Infrastructure\Xlsx`. Formulas are parsed character by character against the grammar and are
never passed to an evaluator. Nothing in this module logs; errors carry codes, sheet names and cell
coordinates, never a label or an amount.

The console prints counts, sheet names, cell coordinates and reason codes. Labels and amounts appear only
with `--dry-run --show-details` (`make import-workbook ... DRY_RUN=1 VERBOSE=1`), on the terminal only;
nothing in the module logs. Every input path must lie outside the repository, and a skeleton is never
overwritten.

`ext-zip` is installed in the tooling image only, not in the hardened runtime image: the import is
run from the tooling container.
