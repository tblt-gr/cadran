## Summary

<!-- What does this PR change, and why? Link exactly one primary issue. -->

Closes #

## Type of change

- [ ] `feat` — new capability
- [ ] `fix` — bug fix
- [ ] `perf` — performance improvement
- [ ] `refactor` — no intended behavior change
- [ ] `docs` / `test` / `build` / `ci` / `chore`

## Verification

<!-- Give a reviewer reproducible commands and manual steps. -->

## Risks and rollback

<!-- Security, financial data, compatibility, deployment risks, and recovery steps. -->

## Checklist

- [ ] This PR has the same milestone and labels as the primary issue named above
- [ ] Acceptance criteria, errors and edge cases are covered
- [ ] Financial signs, precision, rounding and non-calculable states are tested where relevant
- [ ] Positive and negative workspace authorization is tested where relevant
- [ ] Logs and errors contain no sensitive financial data
- [ ] Database migrations include a rollback or documented recovery plan
- [ ] OpenAPI and the generated TypeScript client are synchronized if the API changed
- [ ] Loading, empty, error and non-calculable UI states are handled
- [ ] Keyboard, focus, contrast and responsive behavior are checked if the UI changed
- [ ] Relevant formula, catalog, user and architecture documentation is updated
- [ ] `pnpm quality` and all applicable application checks pass locally
- [ ] No unrelated change is mixed into this PR

## Manual visual check

<!--
What the reviewer must check by hand in the running application before merging, as a
to-do list. One checkbox per observable behaviour: where to go, what to do, what to expect.
Cover the nominal path, each error or empty state the change touches, keyboard-only use and a
360 px wide viewport. Write "Not applicable" when the change has no visible effect.

Example:
- [ ] Transactions > New transaction: type -12.34 and pick "Create…" in the category picker;
      the category dialog opens over the transaction dialog with the label prefilled.
-->

- [ ]

## Visual changes

<!-- Add sanitized desktop and mobile screenshots, or write "Not applicable". -->
