<p align="center"><img src=".github/assets/cadran-budget-logo.svg" alt="Cadran Budget" height="96"></p>
<h4 align="center">Self-hosted personal budgeting and wealth tracking.</h4>

<p align="center">
<a href="https://github.com/tblt-gr/cadran/actions/workflows/ci.yml"><img src="https://github.com/tblt-gr/cadran/actions/workflows/ci.yml/badge.svg?branch=main" alt="CI"></a>
<a href="https://github.com/tblt-gr/cadran/releases/latest"><img src="https://img.shields.io/github/v/release/tblt-gr/cadran?sort=semver&amp;display_name=tag" alt="Latest release"></a>
<a href="https://symfony.com/"><img src="https://img.shields.io/badge/Symfony-7.4-000000?logo=symfony&amp;logoColor=white" alt="Symfony 7.4"></a>
<a href="https://react.dev/"><img src="https://img.shields.io/badge/React-19-61DAFB?logo=react&amp;logoColor=black" alt="React 19"></a>
<a href="https://www.postgresql.org/"><img src="https://img.shields.io/badge/PostgreSQL-18-4169E1?logo=postgresql&amp;logoColor=white" alt="PostgreSQL 18"></a>
<a href="./LICENSE"><img src="https://img.shields.io/badge/License-MIT-blue.svg" alt="MIT License"></a>
</p>

<hr>
<p align="center">
<a href="#status">Status</a> &bull;
<a href="#features">Features</a> &bull;
<a href="#stack">Stack</a> &bull;
<a href="#quick-start-with-docker">Quick Start</a> &bull;
<a href="#configuration">Configuration</a> &bull;
<a href="#daily-use-and-pwa">Daily use and PWA</a> &bull;
<a href="#backups">Backups</a> &bull;
<a href="#upgrade">Upgrade</a> &bull;
<a href="#troubleshooting">Troubleshooting</a> &bull;
<a href="#development">Development</a> &bull;
<a href="#documentation">Documentation</a> &bull;
<a href="#contributing">Contributing</a>
</p>
<hr>

Cadran Budget replaces an annual budget and wealth spreadsheet with a responsive application on
your own server. Financial calculations use exact decimals and expose their sources and data
quality; unavailable results stay explicitly unavailable.

## Status

The upcoming 1.0.0 MVP adds the one-time migration of supported annual workbooks, encrypted
backups, safe upgrades and an installable responsive PWA. Check the [changelog](./CHANGELOG.md)
and select a published release before using personal data. `main` contains unreleased work;
the package version remains the last release until release preparation is complete.

This is a single-owner application. Generic imports, exports, tax tracking, bank synchronization,
shared workspaces, live market prices, payment initiation and financial advice are outside the MVP.
It does not produce official tax returns. There is no native mobile application.

## Features

- **Accounts and wealth**: balances, valuations and explained net wealth.
- **Transactions**: exact amounts, splits, transfers, refunds, categorization and recurrences.
- **Budget and closing**: reconciliation, monthly and annual plans, targets and period closing.
- **Reports and goals**: monthly, annual and multi-year reports, savings goals and emergency funds.
- **Manual investments**: portfolios, qualified performance and multi-support life insurance.
- **MVP delivery**: supported annual-workbook migration, encrypted backups, safe upgrades and PWA.

## Stack

| Layer    | Technology                                                       |
| -------- | ---------------------------------------------------------------- |
| Backend  | PHP 8.5, Symfony 7.4, FrankenPHP, Doctrine DBAL, Brick\Math      |
| API      | REST `/api/v1`, OpenAPI 3.1, generated TypeScript client         |
| Frontend | React 19, TypeScript 6, Vite 8, TanStack Query                   |
| Data     | PostgreSQL 18, exact `NUMERIC(50,24)` financial storage          |
| Runtime  | Docker Compose v2, Caddy HTTPS, containerized Node.js 22 tooling |

The API and SPA form a modular monolith deployed together. PostgreSQL is the source of truth;
financial values cross the API as decimal strings.

## Quick Start with Docker

### Requirements

- Linux, or a Linux distribution under WSL2 with Docker integration enabled.
- Docker Engine with Docker Compose v2, GNU Make and Git.
- At least 2 GB of RAM available to the stack, plus enough disk for images, data and backups.
- Internet access for the initial image and dependency downloads.

PHP, Node.js and pnpm run in the pinned tooling container; they are not required on the host for
installation. Container and network names are fixed with a `cadran_` prefix: use a dedicated
machine for a second installation rather than starting a competing stack in another directory.

```bash
git clone https://github.com/tblt-gr/cadran.git
cd cadran
# Optionally check out the published release tag you have reviewed before initialization.
make init
make setup
make tls-certificate
```

Initialization creates `.env.local`, generates the application and database secrets under
`var/docker-secrets/`, installs dependencies, builds images, starts PostgreSQL, applies migrations
and starts the application. Keep `.env.local` private. Setup prompts for the owner email, display
name, workspace, currency and hidden password. It is a one-time action; a second run is refused.
Use a unique password of 12 to 128 characters; common passwords are refused.

### Trust HTTPS and sign in

The certificate command exports the **public** local Caddy root to
`var/tls/cadran-local-ca.crt`. Trust it in each device's operating-system or browser certificate
store before signing in. For Debian/Ubuntu, including WSL Linux browsers:

```bash
sudo cp var/tls/cadran-local-ca.crt /usr/local/share/ca-certificates/cadran-local-ca.crt
sudo update-ca-certificates
```

A Windows browser used with WSL needs the certificate imported into the Windows trusted root
store as well. Firefox may use its own Authorities store. On mobile, install and enable trust
for this public root using the device's certificate settings. Transfer only the exported root,
never the private authority from the Docker volume. Confirm the certificate came from your own
installation before trusting it.

Open <https://localhost:8443> and sign in with the owner credentials. Do not bypass a certificate
warning to enter your password.

## Configuration

Initialization copies [.env.example](./.env.example) to the private `.env.local` once. Edit that
local file for runtime settings; generated secrets stay in `var/docker-secrets/`.

| Variable               | Default     | Purpose                                                                           |
| ---------------------- | ----------- | --------------------------------------------------------------------------------- |
| `COMPOSE_PROJECT_NAME` | `cadran`    | Keep the project name aligned with fixed container, network and volume names.     |
| `CADRAN_HTTPS_BIND`    | `127.0.0.1` | Host bind address; expose only to a trusted private LAN or VPN after owner setup. |
| `CADRAN_HTTPS_PORT`    | `8443`      | Published HTTPS port.                                                             |
| `CADRAN_SERVER_NAME`   | `localhost` | Resolvable host name used by HTTPS and absolute URLs.                             |
| `CADRAN_BACKUP_DIR`    | `./backups` | Optional external backup directory; create it first with private permissions.     |

`CADRAN_VERSION` is derived from the package version. The application and database secrets are
generated, never copied from example values. `FILE`, `IDENTITY`, `LABEL`, `CONFIRM`, `VERSION`,
`FROM` and `DRY_RUN` are command arguments, not persistent runtime settings. See the backup and
upgrade examples below and the comments in `.env.example` for development-only arguments.

The default bind address is `127.0.0.1`. To access the application from another device, complete
owner setup first, then edit `CADRAN_HTTPS_BIND`, `CADRAN_SERVER_NAME` and, if needed,
`CADRAN_HTTPS_PORT` in `.env.local`. Use a resolvable host name on a trusted private LAN or VPN,
trust the authority on that device, and restart the stack. Keep the installation off the public
internet. The PostgreSQL port is never published. The [.env.example](./.env.example) comments
explain configuration, generated secrets and command arguments.

## Daily use and PWA

```bash
make up
make status
make down
```

Stopping preserves database and certificate volumes. Never remove volumes to fix a startup problem.
Status reports application health, its built version and backup freshness; a backup warning does
not mean a backup has been verified.

Install the PWA from a supporting desktop browser's install action or from the mobile browser's
Add to Home Screen action, after HTTPS is trusted. Mobile devices need the reachable private
address described above; their own `localhost` is not your server. Availability of the install
button depends on browser support. The PWA caches application assets, never API responses or
financial data for offline use. Offline mode explains the connection problem; reconnect to read
or change data. When an update is offered, finish any open edits, then accept it to reload.

## Backups

### Create and protect the encryption key

```bash
make backup-key
make db-backup LABEL=manual
```

Key generation writes an age identity to `~/.config/cadran/backup-identity.txt` by default, outside
the repository, and a public recipient to `var/docker-secrets/backup_recipient`. You can choose
an absolute external identity path with `IDENTITY=/secure/offline/backup-identity.txt` when
creating the key. Existing keys are never overwritten. Keep the private identity offline, away
from this machine and separately from backups, with a second secure recovery copy. Creation of
a backup needs only the public recipient. Losing the identity makes its backups unreadable;
rotation does not make old backups readable by the new key.

Backups go to `backups/` by default. `CADRAN_BACKUP_DIR` in `.env.local` can select an absolute
external directory, which must already exist with private permissions (for example, create it
with `install -d -m 700 /absolute/path/to/backups` before configuring it). Only the unconfigured
default directory is created automatically; symlink directories are refused. Each backup
is a set of three files: `cadran-YYYYMMDD-HHMMSS-LABEL.dump.age`, its `.dump.age.sha256` checksum,
and the matching `.manifest.json`. Copy all three to a separate device or host. Treat the
manifest and checksum as sensitive too. Backups contain PostgreSQL data, not Docker secrets,
configuration or certificates. Preserve those separately under equivalent protection.

### Schedule, retain and verify

Install this entry in the host user's crontab, replacing the path. That user needs Docker access;
configure cron's PATH to find Docker and GNU Make. Monitor nonzero exits and backup age rather
than relying only on the presence of a cron entry.

```cron
0 3 * * * cd /path/to/cadran && make db-backup LABEL=daily
```

Review retention before applying it:

```bash
make db-backup-prune DRY_RUN=1
make db-backup-prune
```

Retention keeps every backup less than seven days old, the newest in each of the current and
previous three calendar weeks, and the newest in each of the current and previous eleven months.
Pre-upgrade and pre-restore backups are additionally kept for 90 days. The newest valid backup
is protected. Pruning is local; schedule it separately and define retention for off-host copies.

Periodically mount your offline identity and verify a real copied backup set:

```bash
make db-backup-verify FILE=/backup/cadran-YYYYMMDD-HHMMSS-manual.dump.age IDENTITY=/secure/offline/backup-identity.txt
```

Replace the timestamp and paths with existing files. Verification checks checksum, decryption,
manifest compatibility and restoration in the isolated `cadran_restore_check` database; it does
not replace the live database. Unmount the identity after use. A successful backup alone is
insufficient evidence of recovery.

### Restore

Restore replaces the active database. Keep users out of the application during the operation;
verify the selected timestamp first, then explicitly confirm:

```bash
make db-restore FILE=/backup/cadran-YYYYMMDD-HHMMSS-manual.dump.age IDENTITY=/secure/offline/backup-identity.txt CONFIRM=restore-cadran
make status
```

The command verifies first, takes an encrypted pre-restore backup, stops the application and
restores into a new database before swapping it into service. The replaced database is retained
under `cadran_previous_*`. Check sign-in, recent transactions, account balances and reports before
running `make db-restore-cleanup`; cleanup lists retained databases and asks for confirmation.
Do not delete these recovery copies just to silence a warning.

For a replacement machine: install the same release or a compatible newer release, initialize
it, securely recover the public backup recipient and all three backup files, mount the original
identity, and restore. Do not create a new encryption key and expect it to decrypt old backups.
Run `make migrate` for an older restored schema, then check the application. Restore refuses a
backup with a schema newer than the checked-out code supports; use compatible code first.
The application version alone is not a compatibility gate. Re-export and trust
the replacement machine's HTTPS authority on each device.

## Upgrade

Read the target release's [changelog](./CHANGELOG.md), have disk space for a complete additional
backup/database, and make the offline identity available. Keep the Git working tree clean.
`VERSION` must name an existing newer release tag, for example `v1.0.0` once published:

```bash
make upgrade VERSION=vX.Y.Z IDENTITY=/secure/offline/backup-identity.txt
```

The placeholder `vX.Y.Z` must be replaced. The command validates the remote release tag and its
version, takes and restore-verifies a pre-upgrade backup, records recovery state in
`var/upgrade/last-upgrade.json`, checks out the verified commit, builds, stops the application,
migrates and checks health. Do not delete the state file or pre-upgrade backup. The checkout
remains detached at the release commit. If your checkout is not exactly the installed release
tag, additionally pass `FROM=vX.Y.Z` naming the release actually running; the first upgrade from
0.7.0 requires current upgrade tooling checked out and `FROM=v0.7.0`.

On failure, follow the printed recovery command:

```bash
make rollback IDENTITY=/secure/offline/backup-identity.txt
```

Rollback restores the recorded backup before building and starting the previous release. Never
run an older application against a newer schema. Writes made after the pre-upgrade snapshot
are absent from the restored active database; recovery keeps a pre-restore backup and the
replaced database. Review those records before cleanup. Do not simply check out an old tag and
restart. If an upgrade was killed, remove `var/upgrade/lock` only after confirming no upgrade or
rollback process remains active.

## Troubleshooting

| Symptom                        | Recovery                                                                                                                                                                                                                                                                                                                                                       |
| ------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Certificate warning            | Check the URL matches `CADRAN_SERVER_NAME`, the host clock and trust store. Re-export the root with `make tls-certificate` and trust it on the affected device. Caddy renews leaf certificates automatically while its data volume persists. After a root renewal or replacement, export and trust the new root; preserve that volume rather than deleting it. |
| HTTPS port already in use      | Choose a free `CADRAN_HTTPS_PORT` in `.env.local`, restart with `make down` then `make up`, and use the new URL.                                                                                                                                                                                                                                               |
| A feature fails after updating | Confirm `make status` and the intended version. Run `make migrate` with that version's code; if the upgrade failed, use its printed rollback command. Never migrate with arbitrarily older code.                                                                                                                                                               |
| Database will not start        | Inspect disk space and `docker compose --env-file .env.local logs --tail=100 db`. Preserve volumes and the original database secret; regenerating a secret does not change an existing database password.                                                                                                                                                      |
| Restore refused                | Check all three backup files are together and regular files, the identity is the original one, and the code supports the manifest version/schema. Recover a corrupt set from another copy; never bypass validation.                                                                                                                                            |
| Disk full                      | Stop further writes, free unrelated files or expand storage, then retry. Keep the last verified backup and recovery databases. Preview pruning before removing eligible backups; do not delete Docker volumes.                                                                                                                                                 |
| No recent backup               | Check cron output, Docker permissions, the public recipient and backup directory space. Create a backup and verify it with the offline identity.                                                                                                                                                                                                               |

Logs may contain operational details: sanitize them before sharing and never post backup contents,
financial data or secrets. See [support](./SUPPORT.md) and [private security reporting](./SECURITY.md).

## Development

See [CONTRIBUTING.md](./CONTRIBUTING.md) for architecture, branches, PRs and verification.

```bash
make quality
make test
make build
make audit
make e2e
```

Use only a disposable development installation for browser tests. The [release checklist](./.github/RELEASE_CHECKLIST.md)
records a fresh Linux/WSL installation, operational command exercises and PWA checks before a
release. It is a procedure, not a claim that the clean-machine run has already happened.

## Documentation

| Document                                            | Content                                                           |
| --------------------------------------------------- | ----------------------------------------------------------------- |
| [CONTRIBUTING.md](./CONTRIBUTING.md)                | Development setup, quality gates, branches, commits and releases. |
| [CHANGELOG.md](./CHANGELOG.md)                      | Released capabilities, upcoming changes and MVP boundaries.       |
| [SECURITY.md](./SECURITY.md)                        | Supported versions and private vulnerability reporting.           |
| [SUPPORT.md](./SUPPORT.md)                          | Support channels and data sanitization.                           |
| [CODE_OF_CONDUCT.md](./CODE_OF_CONDUCT.md)          | Community expectations.                                           |
| [Release checklist](./.github/RELEASE_CHECKLIST.md) | Clean-machine installation and operational recovery evidence.     |

## Contributing

Read the [contributing guidelines](./CONTRIBUTING.md) and [Code of Conduct](./CODE_OF_CONDUCT.md)
before opening an issue or pull request. Report suspected vulnerabilities privately using the
[Security Policy](./SECURITY.md).

## License

[MIT](./LICENSE) © 2026 tblt-gr.
