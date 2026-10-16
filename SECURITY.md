# Security Policy

## Supported versions

Security support targets the latest patch in the `1.0.x` release line once 1.0.0 is published.
Earlier `0.x` versions are development releases and receive no ongoing security maintenance;
upgrade to the supported line when available. The `main` branch contains unreleased work and
carries no stability guarantee. This policy does not assert that 1.0.0 has already been released.

## Reporting a vulnerability

Do not disclose vulnerability details in a public issue, pull request, or discussion.

Use **Report a vulnerability** in the repository's Security tab when GitHub private vulnerability
reporting is available. Otherwise, ask [@tblt-gr](https://github.com/tblt-gr) for a private contact
channel without disclosing the issue publicly.

Include the following in the private report when possible:

- the affected version or commit;
- the deployment method and relevant configuration, with secrets removed;
- the impact and conditions required for exploitation;
- reproduction steps or a minimal proof of concept;
- a suggested mitigation or fix.

The target acknowledgement time is seven days. The maintainer will validate the report, coordinate
a fix, and agree on a disclosure timeline with the reporter.

## Scope

Relevant reports include authentication or authorization bypasses, broken workspace isolation,
CSRF, injection, path traversal, hostile imports, exposure of secrets or financial data, session
weaknesses, replay attacks, concurrency failures that break invariants, unprotected backups, and
exploitable vulnerable dependencies.

Regular bugs without a security impact belong in the bug report form. Risks caused solely by a
configuration explicitly documented as unsafe are out of scope.

Only test systems and data you own or are explicitly authorized to test. Do not access another
person's data, perform denial-of-service testing, use social engineering, or degrade shared
services.
