# Changelog

All notable changes to Cadran Budget are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
  its expected occurrence — becoming `RECEIVED` or read back as `LATE` from the calendar — when
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
  implied — an account carries no amount until valuations exist.
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
  what was deposited, credited interest excluded — so a passbook carried past its ceiling by its own
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
  amount and carries the negative sign. Every source travels with the figure — the contributing
  accounts, their exclusive group weights, the freshness of each valuation and the movement since a
  compared day — so the total can be challenged rather than trusted. A sum that cannot be produced
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
