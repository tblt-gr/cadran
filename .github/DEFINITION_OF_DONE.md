# Definition of Done

Every functional ticket inherits this file. A ticket body states only what is specific to
it; everything universal lives here and is never repeated in an issue.

A ticket that skips an applicable item below is not done. Security findings are fixed
inside the ticket, never deferred to a later "security phase".

## 1. Numeric exactness

- No amount, rate, price or return is computed with binary floating point.
- Financial decimals use `NUMERIC(50,24)` in PostgreSQL, exact PHP value objects in the
  domain, and canonical decimal strings in the API contract, each carrying its asset or
  currency code.
- A value that cannot be computed is `null` with an explicit reason. It is never an
  invented `0`.
- Rounding happens at presentation, is documented, and is covered by reference tests.
- The frontend never aggregates a financial KPI. It displays a value computed by the
  backend.

## 2. Isolation and authorization

- Every mutable business record belongs to a workspace. Global system references are
  read-only exceptions.
- Access control is enforced by `workspace_id` on every business query, in application
  services rather than only in routes or UI.
- Repositories filter by workspace. Uniqueness constraints and indexes include the
  workspace scope.
- Positive **and** negative workspace authorization tests are added.
- CSRF protection covers every web mutation.
- Replay and concurrency are handled for sensitive operations; an idempotency key is
  required on imports and on provider-sourced creations.
- Input, pagination, time and volume limits are bounded. Transactions use cursor
  pagination; reference data may use classic pagination.

## 3. Architecture

- The application stays a modular monolith. No microservice, event sourcing, distributed
  bus, global cache or generic abstraction without a measured problem.
- Module dependencies remain `Domain <- Application <- Infrastructure/UI`, checked
  automatically; a forbidden import fails CI.
- A controller adapts HTTP to a DTO, invokes a use case and transforms the result. It
  computes no KPI and contains no query.
- Entities and value objects protect their invariants. Generic public setters are avoided.
- One PostgreSQL transaction wraps a complete business invariant, for example both sides of
  a transfer.
- Composition and dependency injection before inheritance.
- DRY applies to a duplicated business rule, not to syntactic resemblance. An abstraction
  needs a business name, and simpler tests than the duplication it replaces.
- `Utils`, `Helpers`, `Managers` and `Common` never become dumping grounds.
- A file is split when it mixes presentation, data access, calculation, authorization or
  persistence; changes for several independent reasons; or accumulates mode branches.
  Around 250 lines for a React component and 300 for a PHP class triggers a discussion, not
  a mechanical split.

## 4. Security

- The applicable OWASP ASVS 5.0 level 1 controls are mapped and verified, at minimum from
  access control, validation and business logic, error handling and logging, data
  protection, and API and web service areas. Authentication, session, file and cryptography
  controls are added when relevant.
- Logs and errors contain no amount, financial label, full banking identifier, secret,
  token, tax data, or raw import content.
- Secrets come from injected variables or Docker secrets, never from the repository.

## 5. States and accessibility

- Loading, empty, error, unauthorized, stale and non-calculable states are implemented
  wherever they apply.
- Responsive and keyboard behaviour, visible focus, semantic labels and WCAG 2.2 AA
  contrast are verified. Critical meaning never relies on colour alone.
- Every chart has an accessible tabular alternative.
- Critical journeys work at 360 px wide without overflow.

## 6. Tests

- Risk-proportionate domain unit or property tests.
- PostgreSQL and API integration tests, plus contract tests.
- Component coverage for critical interface behaviour.
- Browser E2E coverage is deferred until v1.0.0, when stable critical journeys exist;
  before then it is neither a ticket requirement nor a pull-request gate.
- Migrations are tested from an empty database **and** from the previous schema whenever
  persistence changes.

## 7. Contract and documentation

- The API is versioned under `/api/v1`. Input and output DTOs are distinct from ORM
  entities. Errors use `application/problem+json` per RFC 9457. Dates are ISO 8601.
- OpenAPI 3.1 is generated and validated in CI, and the generated TypeScript client stays
  in sync. No API type is duplicated by hand.
- Every changed formula gets calculation documentation with a manual numeric example and
  reference tests.
- Architecture decision records, catalog sources, operational documentation, demo data and
  the changelog are updated when applicable.
- Demo data lets a reviewer actually see the feature.
- A comment explains _why_. Commented-out code is not kept.

## 8. Quality gates

- Formatting, static analysis, architecture checks, tests, audits and builds pass without
  hiding skipped controls.
- Dependency audits and image scanning fail on known critical or high-severity findings.
