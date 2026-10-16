# Release verification record

Copy this checklist into a private release record or the release pull request and fill in the
results. An unchecked item means unverified, not passed. Do not publish secrets, financial data,
backup contents, identities, full environment dumps or unreviewed browser traces.

## Environment and evidence

- [ ] Record candidate commit, intended tag, host OS/WSL version, CPU, available RAM and disk,
      Docker Engine version and Compose v2 version.
- [ ] Use a genuinely fresh Linux VM or WSL2 distribution with Git, GNU Make, Docker and at least
      2 GB available RAM. A second clone sharing existing fixed-name containers/volumes is not a
      clean-machine test. Never reset a real installation to simulate one.
- [ ] Record UTC start/end time, each command, exit status and a short sanitized result. Record
      interactive prompts as completed without recording their answers or credentials. Do not
      enable shell tracing or record secrets through a terminal transcript tool.

Suggested record format (one row per invocation, including retries):

| UTC     | Candidate commit | Command with sensitive values omitted | Exit    | Observable result |
| ------- | ---------------- | ------------------------------------- | ------- | ----------------- |
| Pending | Pending          | Pending                               | Pending | Not yet exercised |

## Clean-machine installation and everyday use

Follow the README alone. Do not silently fix a missing step: record it and correct the guide,
then repeat from a clean machine.

- [ ] Clone and select the candidate commit; run `make init`. Record dependency/image builds,
      database health and migration completion.
- [ ] Run `make setup`; confirm a second attempt is refused. Keep the chosen disposable owner
      credentials outside the record.
- [ ] Run `make tls-certificate`, install the exported public root in the actual browser trust
      store, open the documented HTTPS URL and sign in without certificate warnings.
- [ ] Run `make status`, `make down`, `make up`, then `make status`; verify data and trust persist.
- [ ] Exercise desktop and mobile private-network URLs, PWA installation, offline notice,
      reconnection and update/reload with unfinished edits preserved until accepted. Record
      browsers/devices, keyboard access and the 360 px layout.

## Backup and disaster recovery, disposable data only

Use only synthetic data and external identity storage. Record file names and statuses, never
contents. Recover onto another fresh machine to validate the documented disaster procedure.

- [ ] Run `make backup-key`, secure the identity separately and create a second recovery copy.
- [ ] Run `make db-backup LABEL=manual` and `make db-backup LABEL=daily`; preserve each complete
      three-file set on another device.
- [ ] Install the README cron entry with the actual checkout path; record one successful
      scheduled invocation and a nonzero-failure notification test.
- [ ] Run `make db-backup-prune DRY_RUN=1`, review the plan, then `make db-backup-prune`.
- [ ] Run `make db-backup-verify` with actual `FILE` and external `IDENTITY` arguments. Confirm
      synthetic live data is unchanged. Try the wrong identity and a corrupted disposable copy;
      both must fail without altering the active database.
- [ ] Run `make db-restore` with the real `FILE`, external `IDENTITY` and
      `CONFIRM=restore-cadran`. Confirm the expected snapshot, sign-in and source records.
- [ ] Run `make migrate` for a compatible older restored schema and check reports.
- [ ] Run `make db-restore-cleanup` only after confirming recovery; record its interactive
      confirmation without exposing data.
- [ ] On the replacement machine, recover the public recipient and backup set, use the original
      offline identity and restore successfully. Trust its new authority; do not reuse old leaf
      certificates or expect a newly generated identity to decrypt the original backup.

## Upgrade and rollback, disposable data only

Use two actual release tags available on origin, with matching declared package versions.
Record the precise previous and target commit. A target that is not published cannot be marked
exercised. The upgrade script's stub tests alone do not prove a real release upgrade.

- [ ] Exercise `make upgrade` with the target `VERSION`, external `IDENTITY`, and `FROM` when
      required. Record pre-upgrade verification, application stop, migration and final health.
- [ ] Exercise `make rollback` with the external `IDENTITY`. Confirm the previous release and
      snapshot are active, and later synthetic writes are available only in recovery copies.
- [ ] Review the refusal cases and failure recovery instructions, including a newer-schema
      backup, unavailable disk space, missing recovery state and an interrupted upgrade lock.
- [ ] Confirm Caddy leaf renewal preserves trust; on a disposable authority replacement,
      re-export and trust the new root on each test device. Never delete real certificate data.

## Automated gates and command coverage

The command checker proves target existence, not operational success. CI exercises the quality,
test, build, audit, infrastructure, migration, backup and selected browser checks. The manual
sections above supply evidence for interactive and recovery commands that cannot safely run
against a developer's live installation.

- [ ] Record successful `make quality`, `make test`, `make build` and `make audit` for this commit,
      plus the aggregate CI gate and image/secret scans.
- [ ] On the disposable development stack, run `make e2e-provision` and `make e2e`. Record the
      complete applicable browser suite, including PWA checks; inspect artifacts before sharing.
- [ ] Record host-only contributor checks if advertised as exercised: `pnpm install`,
      `pnpm format`, `pnpm format:check`, `pnpm lint:docs`, `pnpm quality` and `pnpm audit`.
- [ ] Review every command example in README and CONTRIBUTING against either CI logs or a row
      in this record. Record unsupported host/browser combinations and outstanding checks.
- [ ] Review the changelog, supported-version policy and release exclusions; update the package
      version and move Unreleased only in the actual release preparation.
- [ ] After merging and successful CI, create the signed annotated matching version tag using
      the CONTRIBUTING procedure, then record the successful release workflow and artifacts.

No clean-machine installation or operational rehearsal is asserted by this template itself.
