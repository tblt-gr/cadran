# Browser end-to-end runner for `make e2e` and the CI e2e job.
#
# PLAYWRIGHT_VERSION must match @playwright/test in tests/e2e/package.json; the
# base image tag and the in-image install are both derived from it here.
ARG PLAYWRIGHT_VERSION=1.62.1
FROM mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble

ARG PLAYWRIGHT_VERSION
ENV CI=1
WORKDIR /e2e
RUN chown pwuser:pwuser /e2e
USER pwuser

# Install exactly the test runner, nothing else. The base image already carries
# the browsers; oxlint and tsc are workspace-only dev tooling and stay out of the
# runner image. An exact version keeps the build reproducible without a lockfile.
RUN npm install --no-save --no-audit --no-fund --omit=optional "@playwright/test@${PLAYWRIGHT_VERSION}"

COPY --chown=pwuser:pwuser tests/e2e/playwright.config.ts ./playwright.config.ts
COPY --chown=pwuser:pwuser tests/e2e/tests ./tests

ENTRYPOINT ["npx", "playwright", "test"]
