# Contributing to Cadran Budget

By participating, you agree to follow the [Code of Conduct](./CODE_OF_CONDUCT.md). Never report a
vulnerability through a public issue or pull request; follow the [Security Policy](./SECURITY.md).

## Before you start

Cadran Budget is in its foundation phase. Read the [README](./README.md) before proposing a change.
Open an issue before significant work to confirm scope and domain invariants.

Current requirements are Node.js 22.22 or newer, pnpm 9 or newer, and Git.

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
- Keep each branch short and limited to one issue.
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
- Use a Conventional Commit title.
- Write the title, description, review comments, and testing instructions in English.
- Complete the pull request template and provide reproducible verification steps.
- Include sanitized desktop and mobile screenshots for visual changes.
- Do not include unrelated changes.
- Use squash merges only and delete the branch after merging.

## Current quality checks

```bash
pnpm format        # format supported files
pnpm format:check  # verify formatting without modifying files
pnpm lint:docs     # validate published Markdown files
pnpm quality       # run every current quality check
pnpm audit         # audit locked dependencies
```

Sprint 0 will make `make quality`, `make test`, and `make e2e` the global entry points for
PHP-CS-Fixer, PHPStan, PHPUnit, ESLint, TypeScript, contract checks, and E2E tests. Do not add a tool
without integrating it into the global command and CI.

## Implementation principles

- Protect invariants in the domain and PostgreSQL, not only in the interface.
- Use exact decimals from storage to display; never use binary floating point for finance.
- Keep controllers thin and financial logic out of React.
- Prefer explicit, local solutions over premature abstractions.
- Record durable decisions that affect several modules before implementation.
- Document every formula with a numerical example and reference tests.
- Handle loading, empty, error, and non-calculable states.
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
linear history, with no permanent bypass. Add application checks to the aggregate gate during
Sprint 0.

## Releases

Create releases only from a commit already merged into `main` with a successful `CI gate`. The
release workflow also requires the tag to match the root package version. Create annotated, signed
tags and never move a published tag:

```bash
git switch main
git pull --ff-only
git tag -s v0.1.0 -m "cadran v0.1.0"
git push origin v0.1.0
```

Maintain the `Unreleased` section of `CHANGELOG.md` only when it adds editorial context such as
migrations, breaking changes, or operator actions.

