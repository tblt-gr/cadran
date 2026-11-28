# Changelog

All notable changes to Cadran Budget are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed

- Open annual reports and goal previews faster by loading months in one batch.
- Show a metric policy that cannot be loaded as unavailable instead of version 1, and translate
  the built-in policy name.

### Fixed

- Stop recurrence edits, refunds and transaction edits from failing when they run at the same
  time, and refuse a duplicate recurrence.
- Show the right error message when a request is refused instead of a server error.
- Refuse a dated category replacement that would change a closed month, and explain the refusal.
- Read performance of portfolio accounts holding more than 5 000 operations, and translate
  allocation labels in English.
- Report an internal data inconsistency as a server error instead of an invalid request.

### Security

- Symfony components updated to patch the advisories published on 2026-11-25 (redirects and
  generated URLs reaching another host, access control rules, exception output).

## [1.0.1] - 2026-11-22

### Added

- The installed version number is shown in Settings > Interface.

### Changed

- New Cadran dial logo in the sidebar, sign-in screen, favicon, installed app icons and README.

## [1.0.0] - 2026-11-21

The first MVP release. It builds on the accounts, transactions, budgets, closing, reports, goals,
portfolios and multi-support life insurance features of the releases below and adds the
operational side: backups, restoration, safe upgrades and an installable PWA.

### Added

- A write that would put a current, savings or cash account below zero is refused with the first
  negative day, and the amount input of the transaction, transfer and declared-balance forms
  shows the error; recurrences and automatic booking are not refused.
- English as a second interface language, chosen in the Interface settings and kept in the
  browser; amounts, rates, dates and month names follow the language (#709).
- Weekly mutation testing (Infection with PCOV, `make mutation`) of the decimal, split, refund and
  transfer invariants, with a failing-run issue and a seven-day HTML report.
- Privacy toggle in the Synthese that hides every amount, chart axis, tooltip and table figure
  behind a fixed-length mask, stored per device in the browser.
- Encrypted PostgreSQL backups, daily/weekly/monthly retention, isolated restore verification,
  guarded restoration and retained recovery databases.
- Each release attaches a CycloneDX SBOM, a production license report and a schema-only database
  dump; `make audit` fails on a production dependency outside the license allowlist.
- Safe upgrades with a verified encrypted pre-upgrade backup, version checks, migration and
  health checks, plus rollback that restores the matching schema before starting older code.
- Installable responsive PWA with application-asset caching, update notification and explicit
  offline handling; API responses and financial data are never cached for offline use.
- Installation, operations and contribution guides, a reproducible release checklist and a
  quality guard against undocumented Makefile targets.
- Unify budget plans, targets and savings goals into one goal model: net worth and account
  balance targets, optional tolerance, a side panel in the monthly budget and the annual report,
  per-month or per-year evaluation, stop and delete, details and revision history, and goal marks
  in report charts (#486, #487, #488, #493, #495, #497, #498, #499, #518).
- Merge the reports into the budget with annual and multi-year views, report columns reordered
  by dragging, category columns shown as a tree and all account kinds included by default (#382,
  #506, #517, #519).
- Chart monthly expenses by category and the value history of every account, compose a parent
  group, and choose the period of the net worth chart (#332, #392, #406, #461).
- Organise accounts in a group tree with inherited and customizable colors, sub-group membership
  instead of tags, reordering, allocation by group, a primary transaction account, and an
  accounts overview with one ordered table per group (#350, #353, #383, #400, #401, #402, #403,
  #404, #405).
- Record, correct and withdraw the balance snapshots that anchor an account, with a Solde tab and
  a stepped savings balance history (#345, #351, #374, #377, #439).
- Group transactions by day, select and recategorize or edit several at once, move transactions
  to another account or date with a balance preview, and accept unsigned expense amounts (#379,
  #381, #398, #479, #480).
- Create recurring transactions automatically and plan upcoming transactions within the current
  month (#433, #437).
- Show every holding across accounts on an Investments page, open instrument sheets from
  operation names, create instruments from pickers, take over existing positions when opening a
  securities account, and refresh the valuation after each cash or trade operation (#466, #481,
  #482, #484, #485).
- Delete a category while transferring or keeping its history, and merge categories without
  keeping the merged source in the list (#369, #393).
- Add an opt-in "remember me" to the sign-in and end every session 30 days after sign-in; return
  to the sign-in when any request finds the session expired (#409, #571, #581).
- Reset the owner password from the console, log failed and throttled sign-ins without
  identifying data, and write structured JSON logs carrying a request identifier (#569, #570,
  #573).
- Schedule the maintenance jobs, run `make doctor` daily with a failure notification, rotate the
  container logs, rehearse a cold restore on a disposable stack and let the installation set
  container memory limits (#572, #584, #611, #634, #638).
- List every problem type in the OpenAPI contract and guard the whole `/api/v1` contract (#315,
  #595).
- Verify WCAG 2.2 AA on the critical journeys and gate the responsive and browser journeys with
  end-to-end tests (#320, #327).

### Changed

- The Investments page and the portfolio account Positions tab now share one allocation panel: a
  criterion control, a donut and an always-visible table of the slices.
- The positions view switch of the Investments page now sits just above the positions table, and the allocation card no longer follows it.
- Soften the shell: a warm light in the top-left corner of the background, no divider between
  the menu and the page, a menu as wide as its content and a glass hover on its links.
- Unify the interface on the graphite and platinum registry: shared modal layouts (full screen on
  mobile), tabs, tables and controls, one dated row list across registers, balances, valuations
  and portfolio operations, an icon and a label on every page header action, a back arrow on
  phone detail pages and a five-icon mobile navigation bar (#551, #552, #554, #635, #667, #669,
  #679, #681, #689, #691, #692, #693).
- Show the date of the net worth card under its title, and draw the curve charts with more dashed
  level lines and axis figures, in a quieter axis color.
- Reorder the primary navigation around accounts, put the budget after home, make the monthly
  budget the entry point for transactions and move the monthly closure into its overflow menu
  (#349, #458, #476, #510).
- Simplify the portfolio, life insurance, catalogue and investments screens and the account and
  performance views (#420, #555, #556, #557, #558, #563).
- Move page actions into the header and group the settings under `/parameters` (#297).
- Load the French dictionary as its own chunk and tally the transaction facets in one scan when
  no split filter is set (#586, #643).
- Split the PHP classes and React components that exceeded the size ceilings and serve
  cross-module totals from dedicated readers, with no behavior change (#577, #579, #583, #637,
  #656).

### Fixed

- Hide read-only amounts and their signs consistently across account, budget and investment views.

- Run local architecture checks with Docker tooling and report overdue image-scan exception
  reviews in the nightly check.
- Save categories with budget inclusion turned off (#715).
- Apply the balance floor when withdrawing a snapshot or switching an eligible account to
  transaction-based valuation.
- Retrying a transaction, transfer or refund save after a lost response no longer creates a
  duplicate (#717).
- The application and database containers start again on their own after a reboot of Docker
  Desktop on WSL: they read their secrets from a Docker volume instead of bind-mounted host files.
- Enter a positive fee when editing a transfer, just as when creating one.
- Derive account balances from snapshots and booked movements, and measure the net worth change
  from the closing balance of the baseline (#344, #394).
- Keep the original analytic axes on a proposed refund split, handle refund decimal entry safely,
  allow editing existing refund metadata and start a new refund on the workspace's today (#378,
  #465, #511, #645).
- Respect the upcoming transaction preference in goals, cover descendants in parent category
  columns and align the multi-year report with the annual details (#413, #514, #615).
- Preserve the scroll position when loading more rows, and make overflowing tab strips scrollable
  with a mouse (#317, #504).
- Fix the mobile layout of cards, tabs, filters, month cards and action menus across the
  application (#640, #641, #646, #649, #661, #662, #663, #664, #665, #670, #671).

### MVP boundaries

- Generic import, exports, tax tracking, banking synchronization and shared workspaces remain
  outside 1.0.0. Real-time prices, payment initiation, financial advice, official tax filing,
  full multi-currency support and native mobile apps are not delivered by this MVP.
- A release still requires its complete final review and recorded fresh-machine installation;
  this entry does not claim those release checks have already passed.

### Security

- Complete the OWASP ASVS 5.0 security review of the MVP, update Symfony to 7.4.23 for security
  advisories and apply all Alpine, zlib, krb5 and sqlite image fixes (#325, #322, #470, #477,
  #516, #601).
- Upgrade the FrankenPHP base image to 1.13.1 (Go binary free of known HIGH and CRITICAL findings)
  and drop the expired `.trivyignore` exceptions for kin-openapi, gRPC-Go and golang.org/x/crypto.
- Send `Strict-Transport-Security` (one year, no preload) on any host name except localhost,
  `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy` set to `same-origin`, and
  `Cache-Control: no-store` on every API response (SEC-003).
- Check the security headers, the absence of `Server` and `X-Powered-By`, and the session cookie
  flags on the running stack in the image-scan job (`make check-http-headers`), and scan the
  repository and its history for secrets with gitleaks on pull requests and nightly (SEC-003).
- Prove with tests that no amount, label, e-mail or password reaches the logs, and that an
  unhandled exception in production answers a problem detail without class, path, trace or SQL
  (SEC-003).
- Log an unhandled exception by its class, SQLSTATE and location only: a database error message
  quotes the refused row, and unhandled API errors were not logged at all before (SEC-003).
- Cap API request bodies at 1 MB, answer a request for an unknown Host with 421 and no `Server`
  banner, set `expose_php = 0`, and keep tests and the performance suite out of the runtime image
  (SEC-003).
- Refuse common passwords (SecLists top 3000 entries of 12 characters or more, matched
  case-insensitively) when choosing the owner password (SEC-003).
- Anchor the public session routes in the firewall rules and prove with a router-generated test
  that every other API operation answers an anonymous caller with 401 (SEC-003).
- Scan the backup image with Trivy in the pull-request gate and the nightly scan (SEC-003).

## [0.7.0] - 2026-10-08

### Added

- Calculate and explain total portfolio gain from valuations and external flows, keeping internal
  operations out of contributions and withdrawals (PERF-001).
- Calculate monthly Modified Dietz performance with exact dated flow weights and explicit
  non-calculable reasons (PERF-002).
- Calculate time-weighted return only when the required valuations are available, exposing
  subperiods and missing boundaries instead of substituting an estimate (PERF-003).
- Calculate money-weighted return with a bounded decimal XIRR solver, and explain absent,
  ambiguous or non-convergent solutions (PERF-004).
- Expose performance methods, formulas, dated inputs, rounding and data quality in calculation
  details with links back to source operations and valuations (PERF-005).
- Compare monthly and annual portfolio performance, with value history, contributions,
  withdrawals, gains and qualified return methods beside table alternatives (PTF-003).
- Model life-insurance accounts as portfolios of euro-fund and unit-linked supports, with a
  contract tab, masked contract numbers, versioned fee terms and sourced annual euro-fund rates
  (INS-001).
- Offer a linked portfolio deposit when recording a transfer into an investment account,
  avoiding a second cash movement and preserving transfer neutrality (PTF-010).
- Record life-insurance premiums and partial or full surrenders with exact support allocations,
  linked transfers, entry fees and cumulative premium tracking (INS-002).
- Switch between life-insurance supports atomically, including switch fees and an exact
  reconciliation of the outgoing and incoming values (INS-004).
- Record euro-fund interest and contract fees as distinct investment operations, with dated
  source terms and exact cash or support-unit effects (INS-005).
- Show life-insurance allocation, performance, cumulative premiums and surrenders, contract
  history and informative tax tracking with explicit source and quality explanations (INS-003).
- Add a global breadcrumb with linked parents, including Home / Accounts / Account name /
  Portfolio, that works when a page is opened directly and while its data loads.

### Changed

- Group insurance reporting into the portfolio's existing tabs: allocation, contract and
  performance, with contract amount sources in a disclosure closed by default.
- Clarify account and portfolio navigation by using the breadcrumb for parent pages and keeping
  business actions in the page header.

### Fixed

- Leave a chained Modified Dietz return unpublished when a month loses at least the invested
  capital, and build the base-100 index from the unrounded monthly rates.
- Open the operation or valuation from a performance explanation, and offer a retry when the
  year or since-opening request fails.
- Align portfolio operation table headers with their columns.
- Focus the selected source operation when multiple insurance quality explanations link to the
  same operation, and reopen collapsed contract sources when returning from a source detail.
- Separate cumulative contract amount sources from the preceding table with the shared spacing
  scale.

## [0.6.0] - 2026-10-04

### Added

- Manage investment instruments: ISIN validation, duplicate detection, deactivation instead of
  deletion, bounded search and a picker (PTF-001).
- Show a layout-faithful skeleton on every data-backed screen, built with one shared primitive and
  reused for the page preview shown while the session is checked (UI-005).
- Record exact portfolio operations with decimal quantities and prices, atomic corrections and
  voids, linked transfers, idempotent writes and a live preview (PTF-004).
- Reconcile positions and tax lots from operations, with first-in first-out and average cost,
  splits, merges and a guard against negative positions (PTF-002).
- Reconcile portfolio cash, fees and taxes, with named warnings and a monthly costs card kept out
  of the budget (PTF-005).
- Record portfolio valuations and position snapshots, with total or per-line entry, price quality,
  superseding and a flag when the history changed after a valuation (PTF-006).
- Build the investment account page: key figures with their explanation, dated allocation and the
  operations, positions, cash and valuations tabs (PTF-007).
- Prompt and prefill the month-end portfolio valuation, and warn when closing a period without it
  (PTF-008).
- Chart the portfolio value over time with the shared curve, a period control, a table alternative
  and explicit gaps for partial valuations (PTF-009).
- Show on the portfolio list the unrealized gain and its performance since the start (gain over
  cost basis, computed on the server), the value curve of each account and a total of all
  investments with the date of its oldest valuation.

### Changed

- The portfolio list cards are reduced to the account name, institution, latest total, gain and
  curve.
- The application header stays fixed while the page scrolls.

### Fixed

- The portfolio page no longer shows its title twice, and its `Instruments` button keeps its
  height.

## [0.5.0] - 2026-10-01

### Added

- Version the metric policy definitions: a policy defines which account kinds count as cash,
  new versions are created and activated with an expected-version check and an audit trail, and
  every report names the policy version it was computed with (RPT-006).
- Aggregate report months exactly: totals, averages, medians, minimums and maximums are computed
  on exact decimals, state which months were counted or excluded, and return an explicit reason
  instead of zero when a figure cannot be calculated (RPT-005).
- Build the annual monthly-summary report: twelve months of configurable columns with charts and
  tabular alternatives, saved column and incomplete-month preferences, per-aggregate explanations
  and a link from each monthly cell to its source (RPT-002).
- Build the multi-year comparison report: one row per year over a bounded range with year-over-year
  change, a detailed continuous view and accessible charts (RPT-003).
- Create configurable savings and budget goals, annual or rolling, scoped to the workspace, a
  category, an axis or an account (GOAL-001).
- Distribute an annual goal into an exact monthly calendar that always sums to the target
  (GOAL-003).
- Calculate goal progress and status at a given date, with explicit non-calculable reasons
  (GOAL-004).
- Calculate the emergency-fund coverage goal from a versioned policy (GOAL-002).
- Preserve goal history across changes: every revision is a new version, with a correction
  preview and a version history (GOAL-005).

### Fixed

- Goals no longer relabel a figure denominated in another currency: it is reported as not
  calculable (`MIXED_ASSETS`).
- Reports and goals count account valuations when finding the first month with data, so a
  workspace with balances but no transaction yet is no longer reported empty.
- The annual and multi-year report controls stay reachable on narrow screens.
- The demonstration seed works during the first days of a month.

## [0.4.0] - 2026-09-25

### Added

- Reconcile pending transactions into their booked counterparts: a `PENDING` transaction matched
  to a real movement becomes `RECEIVED` or is read back as `LATE` from the calendar, without
  duplicating the canonical transaction or losing user enrichment (TX-010).
- Reconcile account balances against a dated observed statement and explain the discrepancy
  between the opening balance plus movements and the closing figure, with unreconciled items kept
  distinct from reconciled ones (TX-007).
- Close and reopen monthly periods safely: a workspace-wide closure protects every dated write
  behind one shared `AssertPeriodOpen` guard, blocking conditions (unreconciled account,
  unexplained discrepancy, pending transactions) must each be confirmed with a reason, and
  reopening always records a reason and is audited (CLS-001).
- Build the monthly financial projection, reproduced from source transactions and valuations
  rather than stored per-month tables (RPT-001), and create the `Reporting` module.
- Workspace-scoped budget plans and category, group or analytic-axis targets for monthly or
  annual periods: plans move through draft, active and closed states with audited activation and
  closure; targets store exact AMOUNT or RATIO values with a single asset code inherited from the
  plan; overlap between parent and child scopes is detected at read time; ratio targets surface
  explicit non-calculable reasons (`ZERO_CASH_INCOME`, `MIXED_ASSETS`, `NO_ACCOUNT`) instead of a
  silent zero; and the Budget screen manages plans and targets through the shared modal without
  hard-coding a 50/30/20 rule (BUD-001).
- Compare monthly budget targets with actual results: actual, target, variance and status are
  calculated per scope from the selected metric policy (BUD-002).
- Expose a calculation-detail drill-down for every complex KPI: formula, scope, period, source
  rows, freshness and quality are always available (RPT-004).
- Build the accessible monthly report and its data alternatives: one selected month's projection,
  quality, pending state and KPI explanations, with a tabular alternative to every chart (UI-002),
  and create the `Reports` navigation entry.
- Calculate net monthly savings transfers as a KPI distinct from gross incoming transfers to
  savings and portfolio accounts, so transfers between savings accounts never register as new
  savings (RPT-007).
- Build the month-first budget workbook: the Budget navigation now opens directly on the selected
  month's sheet, with January-to-December tabs, source movements by category or account, and
  plan/target management moved to a Budget subpage (BUD-003).
- Build the generic account detail page: identity, dated value, reconciliation quality, rules and
  movements for any account, reachable from the monthly recap (ACC-008).
- Show the monthly account and wealth recap: account and group values beside the budget movement
  panels, a net-worth change card and a configurable totals recap, all read-only and built from
  dated source valuations (RPT-008).
- Nest category management under Transactions: the former top-level Categories page moves into
  the Transactions section with an explicit management action from the transaction list (UI-004).

### Fixed

- Closed-period guard on manual balance snapshots: recording or superseding a dated observed
  balance now calls `AssertPeriodOpen` before writing, so a snapshot dated in a closed month is
  refused with the shared `/problems/period-closed` response instead of silently altering a
  closed period (including resetting a `RECONCILED` snapshot back to `UNRECONCILED`).

### Security

- Re-review the seven accepted image-scan findings bundled in FrankenPHP 1.12.7 (kin-openapi,
  gRPC-Go, golang.org/x/crypto) and extend their `.trivyignore` expiry to 2026-10-10: no fixed
  official image exists yet and the deployed Caddyfile still reaches none of those code paths.
- Document the CSRF parameter on the two account rule-override mutations
  (`POST /api/v1/accounts/{id}/rule-overrides` and its `/withdraw` sibling), which enforced the
  guard already but omitted it from the OpenAPI contract; a new
  `pnpm openapi:csrf` check now fails the build if any mutating `/api/v1` operation is
  undocumented for CSRF.
- Expose the workspace's IANA timezone on the session payload (`workspace.timeZone`) instead of
  hardcoding `Europe/Paris` in the SPA: the budget route and its "today" resolution now read the
  zone from the session the browser already loaded.
- Bound the `app` and `db` containers with an explicit `mem_limit` and `pids_limit` in
  `compose.yaml`, so a runaway request or query cannot exhaust host memory or the process table;
  `scripts/test-infrastructure.mjs` now asserts both are set.
- Pin the `node`, `composer`, `dunglas/frankenphp` and `postgres` base images in
  `docker/app/Dockerfile`, `docker/tools/Dockerfile` and `docker/postgres/Dockerfile` by digest
  (tag kept for readability), so a build always resolves the exact image reviewed.

## [0.3.0] - 2026-09-17

### Added

- Exact decimal value objects and serialization: `ExactDecimal::sum()`, `negate()` and
  `absolute()` keep every submitted decimal place, a shared parser turns an amount fragment into
  an `AssetAmount` and rejects a malformed, imprecise or unknown-asset figure before any write,
  and the browser adds, subtracts, sums, negates and compares signed canonical decimals exactly
  with the same reference cases and property tests as the backend.
- Atomic internal transfers: one request books both legs in a single PostgreSQL transaction, an
  exact zero-sum pair for a same-asset transfer and two exact amounts with one derived rate for a
  cross-asset transfer, an explicit fee transaction, and editing or voiding always moves the pair
  together; transfers never appear as income or expense.
- Partial and full refunds linked to their original expense: the refundable amount is the
  original minus its non-voided refunds, checked under a row lock so two concurrent refunds
  cannot both pass, with a default largest-remainder allocation across the original's categories
  and a two-way link shown on both the refund and the original.
- Idempotent, precision-safe transaction creation: a repeated `Idempotency-Key` with the same body
  replays the first response instead of writing again, a reused key with a different body is
  refused, keys are scoped to the workspace and to the use case, an imported or provider-sourced
  creation requires a key, and a stored key expires after seven days and is purged by a console
  command.
- Search and filter transactions with bounded, keyset-paginated results: period, accounts,
  states, natures, categories with descendants, analytic axes, a signed amount range, source,
  categorization state and free text combine as a conjunction, repeated values of one filter
  combine as a disjunction, and the transactions screen reflects the filters in the URL with its
  own empty, impossible-combination and stale-page states.
- Safe automatic categorization rules: a rule matches on the raw label, the normalized label, the
  counterparty, the merchant category code, a signed amount range and a direction, with
  `EQUALS`, `CONTAINS` and `REGEX` conditions validated against a write-time safety policy and
  bounded at execution by a backtrack limit and a per-rule time budget; applying a rule to
  history always goes through a preview that writes nothing, and a manually categorized split is
  never overwritten.
- Recurring transaction detection: a bounded scan of the workspace history proposes candidates
  grouped by account and counterparty with a qualitative, nullable confidence, confirming one
  creates an editable recurrence and its forward occurrences, and a real movement is matched to
  its expected occurrence, becoming `RECEIVED` or read back as `LATE` from the calendar, when
  created, edited or voided.
- Split a transaction across zero to twenty categories, each with its own exact amount, optional
  analytic axes and note, summing exactly to the transaction amount or left empty. The transaction
  modal offers a split editor with a live remaining amount, "assign the remainder to this row" and
  a deterministic "split evenly across N rows" helper, both computed with exact decimal arithmetic.
  A dedicated `PUT /api/v1/transactions/{id}/splits` endpoint replaces an allocation on its own,
  and the transactions screen exposes a "to categorise" tab (`GET
/api/v1/transactions?categorization=NONE`) with its own empty state for the non-voided
  transactions that carry no split yet.
- Category identity pickers: a category is either colourless or carries one freely chosen colour,
  with the canonical `#RRGGBB` value editable beside the native control and no palette to pick
  from. Icons come from a flat, searchable catalogue of 71 local glyphs that carries no category
  meaning, so any icon fits any category. Each category then renders as one pill filled with its
  colour, whose text and glyph use black or white by WCAG contrast ratio; a category without an
  icon shows none, and a category without a colour keeps a neutral pill. The pill accompanies
  categories in lists, selection fields and transactions, and refreshes immediately after category
  edits. A transaction split exposes the read-only `categoryIcon` and `categoryColor` of its
  category, resolved in the bounded lookup that already supplies its label, so a transaction row
  draws its pill with no extra request.
- Workspace-scoped transactions: a signed movement on one account, with exact decimal
  scale preservation, optional single-category allocation, optimistic editing, voiding
  without deletion, duplication dated today, cursor listing, and the `/transactions`
  screen.
- Category creation from the transaction form: the category picker offers to create the searched
  label in a second-level dialog, prefilled with the type matching the movement sign, and selects
  the new category without losing the transaction draft. Nested dialogs keep a single active focus
  trap and return focus to the control that opened them.

## [0.2.0] - 2026-09-06

### Added

- Workspace-scoped financial accounts carrying a free label, a denomination taken from the system
  asset reference, an account kind, a masked identifier suffix, a valuation mode, a liquidity level,
  net-worth and emergency-fund inclusion policies and opening and closing dates. Managed through
  `/api/v1/accounts` and the responsive accounts interface, with optimistic versioning, archiving
  instead of deletion, and a redacted audit event for every creation, edit, closure, reopening and
  archive.
- Net-worth sign convention on every account: an asset contributes positively and a liability
  negatively, stated by the API and displayed in words rather than by colour alone. No balance is
  implied, an account carries no amount until valuations exist.
- Account creation from a system catalogue product, in three steps: choose the model, describe the
  account, then review what it inherits. An account stores the product reference and the institution
  holding it, and nothing else the catalogue owns: ceilings, rates, accrual methods and their sources
  stay in the catalogue and are read on the business date they are needed, so a regulatory revision
  is never frozen into an account. The review lists every inherited rule with its effective period,
  verification state and official source, and shows a rule no source covers on that date as
  unavailable rather than as zero.
- Product-declared attributes are enforced by the API, not merely pre-filled: the account kind must
  be the one its product declares, and a valuation mode is refused unless the product declares the
  capability that feeds it. The account carries its product and its kind together, so a foreign key
  on the catalogue refuses both an unknown reference and a product filed under a kind it does not
  declare, whatever writer reaches the table. An account already used by history can change neither
  kind nor product.
- Ceiling basis stated by the catalogue API on every product: a share savings plan is capped on the
  contributions paid into it, whatever the plan is worth, while a regulated passbook is capped on
  what was deposited, credited interest excluded, so a passbook carried past its ceiling by its own
  interest has broken no rule. A ceiling read on everything an account holds, credited interest
  included, is a distinct measure and is recorded as its own rule kind rather than resolved to the
  opposite verdict. Market products announce no promised yield anywhere in the creation flow.
- Rules in force for one account on a business date the reader chooses, at
  `GET /api/v1/accounts/{id}/rules` and in the accounts interface: ceilings with the figure each one
  is measured against, rates always as a bracket scale with their application mode, dated terms, and
  the rule kinds the product is expected to carry that no sourced period covers. A gap is reported as
  unavailable and never as zero, a period left open is in force for every later date instead of
  expiring silently, and a ceiling published in another unit than the account is reported as not
  comparable rather than converted.
- Accounts created from a reusable workspace product model: the wizard lists the workspace's own
  templates beside the system catalogue, and the account stores only the model reference. Dated
  ceilings, rates and terms stay on the model and are read on the business date they are needed, so
  archiving a template blocks new use without changing what an existing account resolves. A rule
  declared by the workspace carries no verification state and no publication; it is shown as
  declared rather than as sourced.
- Dated rule overrides recorded against a single account, in front of the catalogue product or
  workspace model it follows. An override carries its own effective dates, a required reason and the
  author taken from the session, and it may only state what the authority behind the account could
  itself have stated: no rate on a product that promises none, no rule the account's capabilities do
  not support, and no amount in a unit the account is not held in. Managed through
  `/api/v1/accounts/{id}/rule-overrides`, with a redacted audit event that names neither the amount
  nor the reason.
- Layered rule resolution at `GET /api/v1/accounts/{id}/rules`: every rule now states what the
  system catalogue publishes, what the workspace model the account follows says, and what the
  account claims locally, side by side rather than merged into the winning figure, with the layer in
  force named explicitly. A catalogue revision moves only the published layer and never overwrites a
  local claim. A catalogue figure for a kind the workspace model never carried stays a gap rather
  than an in-force fallback.
- Withdrawing an override, distinct from ending one: an end date is a dated fact and the account
  keeps resolving against the claim up to that day, while withdrawing stops it applying on every
  date, past ones included, and gives back the inherited rule of each of those dates. The withdrawn
  claim is kept and stays readable in the account's override history, because it is the provenance
  of every statement produced while it applied.
- Explainable net worth on a business day, read through `/api/v1/net-worth`: the signed sum of the
  accounts included in net worth and open that day, where a liability keeps its positive outstanding
  amount and carries the negative sign. Every source travels with the figure, the contributing
  accounts, their exclusive group weights, the freshness of each valuation and the movement since a
  compared day, so the total can be challenged rather than trusted. A sum that cannot be produced
  is null beside its reason: no eligible account, a missing valuation, or eligible accounts
  denominated in several units, which are refused rather than added together.
- Net-worth change: the delta since a compared day, and its rate when the base allows one. The two
  fail apart, so a real movement measured against a zero or a negative base keeps its amount and
  states why no percentage follows; a percentage against a negative base would read backwards.
- Net-worth history curve through `/api/v1/net-worth/history`: one point per month end, ending on
  the requested day itself. Each point is recomputed from the valuations that were valid on its own
  day, so a balance recorded late lands on the day it describes, and a month that cannot be computed
  keeps its place with its reason instead of falling to zero.
- The dashboard now reads that aggregate instead of demonstration figures: the headline amount, the
  movement since the compared day, the freshness of the sources, the curve with its tabular
  alternative, and the exclusive allocation of the top-level account groups.
- Category lifecycle operations at `/api/v1/categories/{id}`: moving a branch, folding a category
  into another, retiring one, and replacing one from a date onwards. Each is confirmed against an
  impact preview served by `GET /api/v1/categories/{id}/impact`, which answers what the operation
  would change without changing anything and names every reason it would be refused. The same
  assessment gates the write, so the confirmation screen and the operation it leads to never
  disagree, and a stale confirmation is refused rather than applied.
- Cycles are now impossible in a category tree at both boundaries: the use case walks the ancestry
  before a move, and a database trigger walks it again on every write, so no writer can attach a
  category below itself. A move rewrites the depth of the whole branch, archived categories
  included, inside one transaction.
- A merge moves the direct children under the target, records the redirection that makes the
  history of the source read as the target's, and archives the source. A dated replacement records
  the same redirection from a chosen day onwards and leaves the source in place, still selectable
  for the period before it: history is never silently rewritten. A category is redirected at most
  once, never across income and expense, and never in a loop.
- No category is ever hard-deleted, used or not: the API exposes archiving only, and archiving waits
  for the branch below to be retired first. Every lifecycle operation writes a redacted audit event
  carrying structure and identifiers, never a label.
- The impact preview reports the historical classifications an operation would re-point as
  unknown, with an explicit reason, while no transaction history exists. It is never an invented
  `0`, and the categories interface shows that non-calculable state as such. It also states the
  archived subcategories a move carries along and the redirections that name the category, so
  nothing moves without being announced first.
- Retiring a category that others redirect into is handled rather than left dangling: archiving is
  refused because it names no successor, while a merge carries those redirections over to its own
  target in the same transaction. A redirection therefore never ends up naming a category nobody
  can select.
- Owner account self-service at `/settings/profile`: the signed-in owner edits their display name
  and changes their password without touching the command line. `GET`, `PATCH /api/v1/profile` and
  `PUT /api/v1/profile/password` act on the account behind the session and take no account
  identifier, and the screen covers its loading, saved, error and expired-session states. The
  profile section reads from the identity record alone, so it renders on an install that holds no
  account or transaction yet.
- A password change re-verifies the current password server-side, refuses a new password that
  breaks the 12-to-128-character policy or repeats the current one, and names the offending field
  through a distinct RFC 9457 problem type rather than a bare 422. None of the three refusals
  writes anything.

### Changed

- `cadran:identity:setup` replaces the four positional arguments of
  `cadran:identity:provision-initial-owner`. It prompts for the owner email, display name,
  workspace name, base currency and a hidden password, sets all of them in one run, and refuses to
  run once provisioning is complete. No credential reaches the shell history or the host process
  list. `make provision-owner` becomes `make setup`.
- Sessions are stored in PostgreSQL instead of files on disk, so they survive a container restart
  and a credential change can drop them.

### Security

- A successful password change revokes every other open session and rotates the identifier of the
  session that made the change, so a cookie captured earlier stops authenticating while the acting
  browser stays signed in. The stored hash is replaced under a compare-and-set against the hash
  that was just verified, so two concurrent changes cannot both win.
- The current-password check is rate-limited on its own sliding window, separate from sign-in
  throttling: exhausting it cannot lock the owner out of signing in, and signing in cannot refill
  it. Neither password reaches a log, an error body or the audit trail.

## [0.1.0] - 2026-09-02

### Added

- System asset reference holding the currencies and crypto-assets every financial figure is
  denominated in, with the decimals each one stores, the decimals it shows and the rounding rule
  that applies between them. Readable at `GET /api/v1/assets` with bounded classic pagination and
  one asset at a time at `GET /api/v1/assets/{code}`.
- Canonical decimal contract: every financial value travels as a decimal string paired with its
  asset code, never as a JSON number. A value carrying more decimals than its asset or
  `NUMERIC(50,24)` accepts is refused before persistence instead of being rounded in silence.
- One-time local provisioning of the first owner, isolated workspace, and OWNER membership.
- Workspace-scoped audit trail for sensitive identity operations, readable at
  `GET /api/v1/audit-events` with bounded cursor pagination, and one event at a time at
  `GET /api/v1/audit-events/{id}`.
- Workspace-scoped income and expense categories with typed parent trees, display metadata,
  analytic defaults, budget inclusion and deterministic ordering, managed through
  `/api/v1/categories` and the responsive categories interface.
- Global system catalogue for Livret A, LDDS, LEP, Livret jeune, PEA, PEA-PME, CTO and
  life-insurance foundations. Its responsive interface resolves sourced ceilings and rates on a
  chosen business date and states unavailable, stale or unverified values explicitly.
- Explicit product capabilities for balances, transactions, interest, holdings, trades, arbitrage,
  contributions, fees, tax tracking and liabilities. The generated API contract and responsive
  catalogue expose the normalized capability set of each product without inferring behavior from
  its name.
- Initial project documentation, contribution guidelines, and repository automation.

### Changed

- Creating or editing a category now happens in a modal dialog instead of a form pushed into the
  page. A shared modal component carries the overlay, heading and close control, and a shared
  overlay hook makes the application inert, locks background scrolling, traps focus and closes on
  Escape or a backdrop press for every dialog surface.

### Fixed

- Signing in no longer answers `500`. `LOCK_DSN` was defined only by the test harness, so the lock
  the login limiter takes could not be built in the container, and every `POST /api/v1/session`
  failed while every other endpoint kept working. The DSN now carries a default in the application
  configuration, and the test suite runs without the override so the gap fails a test rather than
  only the deployed sign-in.

### Security

- The asset reference is global and read-only: it carries no workspace data, exposes no write path,
  and changes only through a reviewed migration. The workspace base currency is validated against it
  before provisioning writes anything.
- Workspace scope resolved server-side from the session and carried as a dedicated type into every
  scoped query, so an identifier supplied by a client can never widen it. An object that belongs to
  another workspace answers `404` exactly like an unknown one, so the API cannot be used to
  enumerate what exists elsewhere.
- A repository guard fails the build when a statement reads a table carrying `workspace_id` without
  filtering on it, writes or deletes without naming it in its criteria, or reaches such a table from
  outside an infrastructure layer. An integration test holds the tables the guard sees against the
  migrated schema, so it cannot go blind without failing.
- CSRF protection on every `/api/` mutation via a signed double-submit token and a same-origin
  check, enforced at the request boundary.
- Login throttling: at most 5 failed attempts per email and source address, and 25 per source
  address, over a rolling 15-minute window, answered with an account-agnostic `429`.
- Audit events recording provisioning, first-run password definition, and session outcomes, with a
  bounded diff whose sensitive attributes are redacted. A database trigger rejects any `UPDATE` or
  `DELETE` on the trail, so a stray write cannot rewrite history; the role that owns the schema can
  still disable it, and hardening that requires a separate application database role.
- Category mutations enforce same-workspace parents, bounded depth and payloads, server-side field
  allowlists, optimistic versioning, database-backed sibling uniqueness and redacted audit events.
- Product rule periods cannot overlap, historical rows reject rewrites and deletion except for
  closing an open period, and a composite product/yield constraint prevents a market product from
  carrying a catalogue rate even when a migration bypasses the domain.
- Capability dependencies are enforced both in the domain and by deferred PostgreSQL constraints;
  unknown capabilities, incomplete products, liability escalation and rules backed by no declared
  capability are rejected before the catalogue state commits.
