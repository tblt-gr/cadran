# Changelog

All notable changes to Cadran Budget are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

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
- Initial project documentation, contribution guidelines, and repository automation.

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
