# Contributing to Cadran Budget

By participating, you agree to follow the [Code of Conduct](./CODE_OF_CONDUCT.md). Never report a
vulnerability through a public issue or pull request; follow the [Security Policy](./SECURITY.md).

## Before you start

Cadran Budget is preparing its 1.0.0 MVP. Read the [README](./README.md) before proposing a change.
Open an issue before significant work to confirm scope and domain invariants.

Use the Linux/WSL2 Docker installation in the README for the full stack. Host-only frontend
checks require Node.js 22.22 or newer and the pnpm version pinned in `package.json`. Backend
commands require PHP 8.5 in the tooling container; do not run them with host PHP.

```bash
git clone git@github.com:tblt-gr/cadran.git
cd cadran
pnpm install
pnpm quality
```

The installation enables the local Git hooks. Enable them again manually with:

```bash
./scripts/install-git-hooks.sh
```

## Issues

Use the forms under `.github/ISSUE_TEMPLATE`. Write issues, pull requests, branch names, commit
messages, titles, and descriptions in English.

A functional ticket states:

1. the user problem, scope, and non-goals;
2. observable acceptance criteria, errors, and empty states;
3. domain invariants, signs, precision, and rounding rules;
4. relevant threats and OWASP ASVS 5.0 controls;
5. expected domain, integration, and interface tests;
6. workspace authorization and logging constraints;
7. migration, OpenAPI, public documentation, or decision-summary needs.

Do not list files to change. Describe expected behavior and testable acceptance criteria instead.

## Branches and commits

- Branch from `main`.
- Use `feat/short-description`, `fix/short-description`, `docs/short-description`, or
  `chore/short-description`.
- Use one issue, one branch and one pull request for a coherent capability. Keep branches short.
- Never push directly to `main`.

Commit messages follow [Conventional Commits](https://www.conventionalcommits.org/):

```text
feat: add account creation flow
fix: preserve decimal precision during import
docs: clarify transfer invariants
test: cover split transaction rounding
ci: add composer audit
chore: update development dependencies
```

The `commit-msg` hook enforces this locally, and CI validates every pull request again.

## Pull requests

- Target `main` and link one primary issue with `Closes #123`.
- Copy the primary issue's milestone and labels onto the pull request when creating it.
- Use a Conventional Commit title.
- Write the title, description, review comments, and testing instructions in English.
- Complete the pull request template and provide reproducible verification steps.
- Include sanitized desktop and mobile screenshots for visual changes.
- Do not include unrelated changes.
- Use squash merges only and delete the branch after merging.

## Quality checks

`make quality` and `make test` are the global entry points. `make quality` runs
PHP-CS-Fixer, PHPStan, PHPUnit, module-boundary checks, ESLint, strict TypeScript, the OpenAPI
lint, documented-command checks and infrastructure checks; `make test` runs PHPUnit against PostgreSQL and the frontend suite.
Do not add a required quality tool without wiring it into the matching global command and into CI.

For the 1.0.0 MVP, browser checks are required for applicable application changes. CI selects
critical browser journeys for frontend, API, client, tooling and runtime changes; documentation-only
changes can skip the browser job according to its path filter. The aggregate gate accepts that
intentional skip. To run browser checks on a **disposable development stack**, provision its
throwaway owner and demo data first (never run these commands against personal data):

```bash
make e2e-provision
make e2e
```

The runner uses the Linux host network. `E2E_BASE_URL` must match the published HTTPS URL;
`E2E_OWNER_EMAIL` and `E2E_OWNER_PASSWORD` must match that disposable owner. These command
arguments are test credentials, never production credentials. Failures leave sanitized-by-review
traces and screenshots in `var/e2e-results/`; inspect them for sensitive content before sharing.

Documentation and contract checks also run without Docker:

```bash
pnpm format        # format supported files
pnpm format:check  # verify formatting without modifying files
pnpm lint:docs     # validate published Markdown files
pnpm quality       # formatting, linting, OpenAPI lint and type checks
pnpm audit         # audit locked npm dependencies
```

Every pull request runs the same gates in CI: the checks above, backend tests on a
throwaway PostgreSQL, migrations from an empty database and from the released schema, an
OpenAPI and generated-client drift check, a production build of both applications, Composer
and npm audits, and a Trivy scan of the runtime images. The aggregate `CI gate` status is the
one branch protection requires.

## Implementation principles

- Protect invariants in the domain and PostgreSQL, not only in the interface.
- Use exact decimals from storage to display; never use binary floating point for finance.
- Keep controllers thin and financial logic out of React.
- Prefer explicit, local solutions over premature abstractions.
- Record durable decisions that affect several modules before implementation.
- Document every formula with a numerical example and reference tests.
- Handle loading, empty, error, and non-calculable states.
- Draw a loading skeleton of the whole final page: static chrome (titles, labels, table headers,
  buttons, links) stays real and only query-driven values are placeholder blocks.
- Write each skeleton as a mirror of the loaded component's HTML, element for element: same tags,
  nesting, classes, table caption and mobile `data-label`s. Only leaves holding a query value become
  `SkeletonBlock`s, placed in the value's own tag. Prefer rendering the real component frozen
  (`fieldset disabled inert`) when its values are already known, or letting the loaded component
  render its own blocks for absent data; a separate `XxxSkeleton.tsx` stays next to its component,
  reuses its static sub-components and changes in the same commit. Never replace a panel, cell or
  modal with a bare "Loading…" text.
- Meet WCAG 2.2 AA with semantic HTML, keyboard access, focus management, contrast, and chart
  alternatives.

## Hooks and branch protection

`pnpm install` configures `core.hooksPath=.githooks` in the current clone and installs Lefthook:

- `pre-commit` runs Prettier and markdownlint on staged files;
- `commit-msg` runs commitlint;
- `pre-push` blocks direct pushes to `main` on the official repository.

Hooks are only a local safety net and can be bypassed. Configure GitHub branch protection for
`main` as well. The intended protection is versioned in `.github/rulesets/main.json`: it requires
pull requests, a green `CI gate`, an up-to-date branch, resolved conversations, squash merges and
linear history, with no permanent bypass. The aggregate gate includes the applicable browser job.

## Releases

Create releases only from a commit already merged into `main` with a successful `CI gate`.
Follow the [release checklist](./.github/RELEASE_CHECKLIST.md), including a recorded clean-machine
installation and operational recovery rehearsal. Keep the upcoming 1.0.0 capabilities under
`Unreleased` until the release is actually prepared. Update the root package version as part of
that preparation, not merely to document the upcoming release.

Project policy requires a signed annotated `vMAJOR.MINOR.PATCH` tag. The release workflow
checks that the tag matches the root package version and an eligible commit; it does not verify
the signature or annotated tag type. Never move a published tag. For example, after the 1.0.0 release preparation is
merged and verified:

```bash
git switch main
git pull --ff-only
git tag -s v1.0.0 -m "cadran v1.0.0"
git push origin v1.0.0
```

Maintain the `Unreleased` section of `CHANGELOG.md` for new capabilities, migrations, breaking
changes and operator actions. Do not claim unfinished or unverified work has shipped.
