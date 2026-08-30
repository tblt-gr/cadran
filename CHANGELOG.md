# Changelog

All notable changes to Cadran Budget are documented in this file. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- One-time local provisioning of the first owner, isolated workspace, and OWNER membership.
- Initial project documentation, contribution guidelines, and repository automation.

### Security

- CSRF protection on every `/api/` mutation via a signed double-submit token and a same-origin
  check, enforced at the request boundary.
- Login throttling: at most 5 failed attempts per email and source address, and 25 per source
  address, over a rolling 15-minute window, answered with an account-agnostic `429`.
