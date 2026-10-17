import { execFileSync, spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = fileURLToPath(new URL('..', import.meta.url));
const composeConfiguration = process.env.COMPOSE_CONFIG_PATH
  ? readFileSync(process.env.COMPOSE_CONFIG_PATH, 'utf8')
  : execFileSync(
      'docker',
      ['compose', '--env-file', '.env.example', 'config', '--format', 'json'],
      {
        cwd: repositoryRoot,
        encoding: 'utf8',
      },
    );
const configuration = JSON.parse(composeConfiguration);

const fail = (message) => {
  throw new Error(message);
};

const services = configuration.services ?? {};
const serviceNames = Object.keys(services).sort();

if (JSON.stringify(serviceNames) !== JSON.stringify(['app', 'db'])) {
  fail(`Expected only app and db services, received: ${serviceNames.join(', ')}`);
}

for (const [serviceName, service] of Object.entries(services)) {
  if (!service.image?.startsWith('cadran_')) {
    fail(`${serviceName} image must start with cadran_`);
  }

  if (!service.container_name?.startsWith('cadran_')) {
    fail(`${serviceName} container_name must start with cadran_`);
  }

  if (!service.healthcheck?.test) {
    fail(`${serviceName} must define a health check`);
  }

  if (
    service.read_only !== true ||
    service.security_opt?.includes('no-new-privileges:true') !== true
  ) {
    fail(`${serviceName} must use a read-only root filesystem and no-new-privileges`);
  }

  if (!service.user || service.user.startsWith('0:')) {
    fail(`${serviceName} must run as a non-root user`);
  }

  // A runaway request handler or query must not be able to starve the host
  // or the sibling container of memory or of the kernel's process table.
  if (!service.mem_limit) {
    fail(`${serviceName} must declare a memory limit`);
  }

  if (!service.pids_limit || service.pids_limit <= 0) {
    fail(`${serviceName} must declare a positive pids limit`);
  }

  if (
    JSON.stringify(service.cap_drop) !== JSON.stringify(['ALL']) ||
    service.cap_add !== undefined
  ) {
    fail(`${serviceName} must drop every Linux capability and add none`);
  }

  if (service.privileged === true) {
    fail(`${serviceName} must not run privileged`);
  }

  // A secret travels as a mounted file, never as a value in the environment.
  const environment = service.environment ?? {};
  for (const name of Object.keys(environment)) {
    if (/(?:PASSWORD|SECRET|TOKEN|KEY)/i.test(name) && !name.endsWith('_FILE')) {
      fail(`${serviceName} must not carry the secret ${name} in its environment`);
    }
  }

  const mountedSecrets = (service.secrets ?? []).map((secret) => secret.source ?? secret);
  if (!mountedSecrets.includes('db_password')) {
    fail(`${serviceName} must read the database password from a mounted secret file`);
  }
}

if (services.app.environment?.APP_ENV !== 'prod') {
  fail('The app service must run with APP_ENV=prod');
}

if (!(services.app.secrets ?? []).some((secret) => (secret.source ?? secret) === 'app_secret')) {
  fail('The app service must read APP_SECRET from a mounted secret file');
}

if (services.db.environment?.POSTGRES_PASSWORD_FILE !== '/run/secrets/db_password') {
  fail('PostgreSQL must read its password through POSTGRES_PASSWORD_FILE');
}

for (const [name, secret] of Object.entries(configuration.secrets ?? {})) {
  if (typeof secret.file !== 'string' || secret.file === '') {
    fail(`Secret ${name} must come from a file`);
  }
}

if (services.db.ports !== undefined) {
  fail('PostgreSQL must not publish a host port');
}

const httpsPort = services.app.ports?.[0];

if (httpsPort?.host_ip !== '127.0.0.1' || `${httpsPort?.target}` !== '8443') {
  fail('HTTPS must bind to loopback by default and target port 8443');
}

if (configuration.networks?.internal?.internal !== true) {
  fail('The application and database network must be internal');
}

const databaseNetworks = Object.keys(services.db.networks ?? {});

if (JSON.stringify(databaseNetworks) !== JSON.stringify(['internal'])) {
  fail('PostgreSQL must only join the internal network');
}

const makefile = readFileSync(new URL('../Makefile', import.meta.url), 'utf8');

if (!makefile.includes('doctrine:migrations:migrate')) {
  fail('Migrations must run through an explicit command');
}

if (!makefile.includes('$(MAKE) install') || !makefile.includes('TOOLS_RUN_WITH_DB')) {
  fail('Initialization and database tests must use the reproducible tools image');
}

if (!makefile.includes('$(MAKE) generate')) {
  fail('Initialization must generate the ignored API client in the workspace');
}

if (!makefile.includes('$(TOOLS_RUN) sh scripts/install-git-hooks.sh')) {
  fail('The scriptless dependency install must explicitly enable repository hooks');
}

if (!makefile.includes('$(COMPOSE_DEFAULT) config --format json')) {
  fail('Infrastructure assertions must validate Compose defaults, not local overrides');
}

const qualityTarget = makefile.match(/^quality:[^\n]*(?:\n\t[^\n]*)*/m)?.[0] ?? '';

if (
  !qualityTarget.includes('test-database') ||
  !qualityTarget.includes('$(TOOLS_RUN_WITH_DB) composer quality')
) {
  fail('The quality target must prepare PostgreSQL and inject its database secret');
}

const dockerignore = readFileSync(new URL('../.dockerignore', import.meta.url), 'utf8');

if (!dockerignore.split('\n').includes('**/.env*')) {
  fail('Environment files must be excluded recursively from Docker build contexts');
}

for (const testOnlyPath of [
  'apps/api/tests',
  'apps/api/performance',
  'apps/api/phpunit.dist.xml',
]) {
  if (!dockerignore.split('\n').includes(testOnlyPath)) {
    fail(
      `${testOnlyPath} must stay out of the Docker build context: the runtime image ships no test code`,
    );
  }
}

if (!dockerignore.split('\n').includes('**/docker-secrets')) {
  fail('Docker secret directories must be excluded recursively from build contexts');
}

const healthcheck = readFileSync(new URL('../docker/app/healthcheck.php', import.meta.url), 'utf8');

if (
  !healthcheck.includes("getenv('CADRAN_SERVER_NAME')") ||
  !healthcheck.includes("'peer_name' => $serverName") ||
  !healthcheck.includes('https://127.0.0.1:8443/')
) {
  fail('The app health check must use the configured TLS server name on loopback');
}

if (
  !healthcheck.includes("'ready' === ($payload['status'] ?? null)") ||
  !healthcheck.includes("'v1' === ($payload['apiVersion'] ?? null)") ||
  /\['status' => 'ready', 'apiVersion' => 'v1'\] === \$payload/.test(healthcheck)
) {
  fail('The app health check must verify status and apiVersion only, not the whole payload');
}

const appDockerfile = readFileSync(new URL('../docker/app/Dockerfile', import.meta.url), 'utf8');
const runtimeStage = appDockerfile.split(/^FROM .* AS runtime$/m)[1] ?? '';

if (
  !/^ARG CADRAN_VERSION=unknown$/m.test(runtimeStage) ||
  !/^ENV CADRAN_VERSION=\$\{CADRAN_VERSION\}$/m.test(runtimeStage)
) {
  fail('The runtime image stage must expose its release through ARG and ENV CADRAN_VERSION');
}

if (
  typeof services.app?.build?.args?.CADRAN_VERSION !== 'string' ||
  services.app.build.args.CADRAN_VERSION === ''
) {
  fail('The app build must receive the CADRAN_VERSION build argument');
}

if (!/^export CADRAN_VERSION\s*:=.*package\.json/m.test(makefile)) {
  fail('The Makefile must export CADRAN_VERSION from the root package.json for every image build');
}

if (!/^FROM dunglas\/frankenphp:\S+-trixie@sha256:[0-9a-f]{64} AS runtime$/m.test(appDockerfile)) {
  fail('The runtime stage must start from the FrankenPHP trixie variant pinned by digest');
}

if (
  !/AS php_extensions$/m.test(appDockerfile) ||
  !/install-php-extensions pdo_pgsql bcmath/.test(appDockerfile) ||
  /install-php-extensions/.test(runtimeStage)
) {
  fail(
    'pdo_pgsql and bcmath must be built in the php_extensions stage, never in the runtime stage',
  );
}

// The base image ships a toolchain for building extensions; the runtime stage
// has to purge it so no compiler or kernel header remains after a compromise.
const purgeBlock = runtimeStage.match(/apt-get purge[^&]*/)?.[0] ?? '';

for (const pkg of [
  'autoconf',
  'binutils',
  'cpp',
  'dpkg-dev',
  'gcc',
  'g++',
  'make',
  'libc6-dev',
  'linux-libc-dev',
  'curl',
  'perl',
]) {
  if (!new RegExp(`(^|\\s)${pkg.replace(/[+]/g, '\\$&')}(\\s|\\\\|$)`).test(purgeBlock)) {
    fail(`The runtime stage must purge ${pkg}`);
  }
}

const trivyIgnore = readFileSync(new URL('../.trivyignore', import.meta.url), 'utf8');
const trivyIgnoreLines = trivyIgnore.split('\n');

trivyIgnoreLines.forEach((line, index) => {
  if (!/^CVE-\d{4}-\d+$/.test(line)) {
    return;
  }
  const block = [];
  for (let i = index - 1; i >= 0 && /^(#|CVE-)/.test(trivyIgnoreLines[i]); i -= 1) {
    block.push(trivyIgnoreLines[i]);
  }
  const review = block.join('\n').match(/# review: (\d{4}-\d{2}-\d{2})/);
  const days = review ? (Date.parse(review[1]) - Date.now()) / 86_400_000 : Number.NaN;
  if (!(days >= 0 && days <= 90)) {
    fail(
      `${line} in .trivyignore needs a "# review: YYYY-MM-DD" date at most 90 days ahead and not past`,
    );
  }
});

const caddyfile = readFileSync(new URL('../docker/app/Caddyfile', import.meta.url), 'utf8');

// HSTS is sent only to a real host name: on localhost it would pin every http://localhost:*
// site in the browser for a year. It never carries preload or includeSubDomains.
const hstsLines = caddyfile
  .split('\n')
  .filter((line) => line.includes('Strict-Transport-Security'));

if (
  hstsLines.length !== 1 ||
  !/^\s*header\s+@publicHost\s+Strict-Transport-Security\s+"max-age=31536000"\s*$/.test(
    hstsLines[0],
  ) ||
  !/^\s*@publicHost\s+not\s+host\s+localhost\s+127\.0\.0\.1\s+\[::1\]\s+::1\s*$/m.test(caddyfile)
) {
  fail(
    'HSTS (max-age=31536000, no preload or includeSubDomains) must be sent only behind a matcher excluding localhost, 127.0.0.1 and [::1] (bracketed or not)',
  );
}

for (const policy of [
  'Cross-Origin-Opener-Policy "same-origin"',
  'Cross-Origin-Resource-Policy "same-origin"',
]) {
  if (!caddyfile.includes(policy)) {
    fail(`The runtime must send ${policy}`);
  }
}

if (!/handle\s+@api\s*\{[\s\S]*?request_body\s*\{\s*max_size\s+1MB\s*\}/.test(caddyfile)) {
  fail('The /api/* handler must cap the request body at 1MB (request_body max_size)');
}

if (!/^https:\/\/:8443\s*\{[^}]*header\s+-Server[^}]*respond\s+"[^"]*"\s+421/m.test(caddyfile)) {
  fail('A request for an unconfigured Host must be answered 421 without a Server header');
}

const phpHardening = readFileSync(
  new URL('../docker/app/zz-cadran-security.ini', import.meta.url),
  'utf8',
);

if (
  !/^expose_php\s*=\s*(?:0|Off)\s*$/m.test(phpHardening) ||
  !/COPY\s+docker\/app\/zz-cadran-security\.ini\s+\$PHP_INI_DIR\/conf\.d\//.test(appDockerfile)
) {
  fail('The app image must install a conf.d ini file with expose_php = 0');
}

if (!/handle\s+@api\s*\{[\s\S]*?header\s+>Cache-Control\s+"no-store"/.test(caddyfile)) {
  fail(
    'Every /api/* response must replace its Cache-Control with the deferred header >Cache-Control "no-store"',
  );
}

if (
  !caddyfile.includes('admin off') ||
  caddyfile.includes('reverse_proxy') ||
  /\b(?:grpc|xds)\b/i.test(caddyfile)
) {
  fail('The app runtime must keep Caddy administration and gRPC/xDS paths disabled');
}

if (
  !caddyfile.includes("manifest-src 'self'") ||
  !caddyfile.includes("worker-src 'self'") ||
  !caddyfile.includes('Cache-Control "no-cache"') ||
  !caddyfile.includes('Cache-Control "public, max-age=31536000, immutable"')
) {
  fail('The web runtime must allow its own manifest and worker and set the PWA cache headers');
}

const phpunitConfiguration = readFileSync(
  new URL('../apps/api/phpunit.dist.xml', import.meta.url),
  'utf8',
);

if (phpunitConfiguration.includes('127.0.0.1:5432')) {
  fail('PHPUnit must connect to PostgreSQL through the internal Docker network');
}

const doctrineConfiguration = readFileSync(
  new URL('../apps/api/config/packages/doctrine.yaml', import.meta.url),
  'utf8',
);
const toolsEntrypoint = readFileSync(
  new URL('../docker/tools/entrypoint.sh', import.meta.url),
  'utf8',
);
const appEntrypoint = readFileSync(new URL('../docker/app/entrypoint.sh', import.meta.url), 'utf8');

if (
  !doctrineConfiguration.includes("dbname_suffix: '_test%env(default::TEST_TOKEN)%'") ||
  !toolsEntrypoint.includes('@db:5432/cadran?')
) {
  fail('PHPUnit must derive cadran_test from the provisioned cadran database configuration');
}

if (
  !appEntrypoint.includes('"disable_dotenv":true') ||
  !toolsEntrypoint.includes('"disable_dotenv":true')
) {
  fail('Docker commands must not depend on excluded environment files');
}

for (const [serviceName, service] of Object.entries(services)) {
  const mountsBackups = (service.volumes ?? []).some(
    (volume) => /backup/i.test(volume.source ?? '') || /backup/i.test(volume.target ?? ''),
  );

  if (mountsBackups) {
    fail(`${serviceName} must not mount the backup directory`);
  }
}

const backupDockerfile = readFileSync(
  new URL('../docker/backup/Dockerfile', import.meta.url),
  'utf8',
);
const postgresDockerfile = readFileSync(
  new URL('../docker/postgres/Dockerfile', import.meta.url),
  'utf8',
);
const baseImage = (dockerfile) => dockerfile.match(/^FROM\s+(\S+)/m)?.[1];

if (!baseImage(backupDockerfile) || baseImage(backupDockerfile) !== baseImage(postgresDockerfile)) {
  fail('The backup image must use the exact base image of the PostgreSQL image');
}

if (
  !backupDockerfile.includes('USER 70:70') ||
  !/apk add --no-cache age\b/.test(backupDockerfile)
) {
  fail('The backup image must install age and run as the postgres user');
}

const backupImageName = makefile.match(/^BACKUP_IMAGE\s*:=\s*(\S+)/m)?.[1];

if (!backupImageName?.startsWith('cadran_')) {
  fail('The backup image name must start with cadran_');
}

const backupHost = readFileSync(new URL('../scripts/backup.sh', import.meta.url), 'utf8');
const backupTool = readFileSync(
  new URL('../docker/backup/cadran-backup.sh', import.meta.url),
  'utf8',
);

if (
  !backupHost.includes('--name cadran_backup_run') ||
  !backupHost.includes('--read-only') ||
  !backupHost.includes('--cap-drop ALL') ||
  !backupHost.includes('--security-opt no-new-privileges')
) {
  fail('The backup container must be a named, read-only, capability-free one-shot run');
}

if (!backupTool.startsWith('#!/bin/sh') || /bash/.test(backupTool)) {
  fail('The backup tool must be POSIX sh');
}

if (/PGPASSWORD|--password/.test(backupTool) || !backupTool.includes('PGPASSFILE')) {
  fail('The database password must reach pg_dump through a password file only');
}

for (const target of [
  'backup-key',
  'db-backup',
  'db-backup-prune',
  'db-backup-verify',
  'db-restore',
  'db-restore-cleanup',
]) {
  if (!new RegExp(`^${target}:`, 'm').test(makefile)) {
    fail(`The Makefile must define ${target}`);
  }
}

// Restore (OPS-002): every container goes through the hardened run_container.
if ((backupHost.match(/docker run/g) ?? []).length !== 1) {
  fail('Every backup and restore container must be started through run_container only');
}

if (
  !backupTool.includes('--single-transaction') ||
  !backupTool.includes('--exit-on-error') ||
  !backupTool.includes('--no-owner') ||
  /pg_restore[^\n]*--clean/.test(backupTool)
) {
  fail('pg_restore must run single-transaction, exit-on-error, without owners and without --clean');
}

if (!backupHost.includes('restore-cadran') || !/CONFIRM/.test(backupHost)) {
  fail('The restore must refuse without the exact CONFIRM value');
}

for (const name of ['cadran_restore_check', 'cadran_restore_new']) {
  if (!backupTool.includes(name)) {
    fail(`The restore tool must use the fixed database name ${name}`);
  }
}

if (/\u2014/.test(backupHost + backupTool)) {
  fail('The backup scripts must not contain an em dash');
}

// Upgrade and rollback (OPS-003): thin Makefile targets over scripts/upgrade.sh.
const upgradeScript = readFileSync(new URL('../scripts/upgrade.sh', import.meta.url), 'utf8');

if (!upgradeScript.startsWith('#!/bin/sh') || /bash/.test(upgradeScript)) {
  fail('The upgrade script must be POSIX sh');
}

if (!/^upgrade:\n\t.*scripts\/upgrade\.sh upgrade$/m.test(makefile)) {
  fail('make upgrade must run scripts/upgrade.sh upgrade');
}

if (!/^rollback:\n\t.*scripts\/upgrade\.sh rollback$/m.test(makefile)) {
  fail('make rollback must run scripts/upgrade.sh rollback');
}

if (!/^status:\n(?:\t.*\n)*?\t.*Version: %s.*CADRAN_VERSION/m.test(makefile)) {
  fail('make status must print the version read from the running container');
}

if (!/^main "\$@"; exit \$\?$/m.test(upgradeScript)) {
  fail('The upgrade script must run main and exit on one final line: git checkout rewrites it');
}

if (/--force|git fetch[^\n]*\+/.test(upgradeScript.replace(/^\s*#.*$/gm, ''))) {
  fail('Tags must be fetched without --force so an existing local tag is never moved');
}

if (
  !upgradeScript.includes('CADRAN_RESTORE_LEAVE_APP_STOPPED=1') ||
  !backupHost.includes('CADRAN_RESTORE_LEAVE_APP_STOPPED')
) {
  fail('The rollback must restore with the newer app image left stopped');
}

if (/—/.test(upgradeScript)) {
  fail('The upgrade script must not contain an em dash');
}

// Workbook import (IMP-006, transitional): one Make target over scripts/import-workbook.sh, which refuses repository paths
// before any container starts and mounts every input read-only.
const importScript = readFileSync(
  new URL('../scripts/import-workbook.sh', import.meta.url),
  'utf8',
);

if (
  !/^import-workbook:.*\n(?:\t.*\n)*?\t.*scripts\/import-workbook\.sh$/m.test(makefile) ||
  !/^\.PHONY:.* import-workbook /.test(makefile)
) {
  fail('make import-workbook must be a phony target running scripts/import-workbook.sh');
}

if (!/readonly"/.test(importScript) || /\beval\b/.test(importScript.replace(/^\s*#.*$/gm, ''))) {
  fail('The import script must mount every input read-only and never eval its arguments');
}

const importDirectory = mkdtempSync(join(tmpdir(), 'cadran-import-'));
const outsideWorkbook = join(importDirectory, '2025.xlsx');
const outsideMapping = join(importDirectory, 'mapping.yaml');
writeFileSync(outsideWorkbook, 'x');
writeFileSync(outsideMapping, 'x');

for (const [name, environment] of [
  ['FILE', { FILE: `${repositoryRoot}README.md`, MAPPING: outsideMapping }],
  ['MAPPING', { FILE: outsideWorkbook, MAPPING: `${repositoryRoot}package.json` }],
  ['EMIT_MAPPING', { FILES: outsideWorkbook, EMIT_MAPPING: `${repositoryRoot}mapping.yaml` }],
  [
    'FILES',
    { FILES: `'${repositoryRoot}README.md'`, EMIT_MAPPING: join(importDirectory, 'skeleton.yaml') },
  ],
  ['a relative FILE', { FILE: './README.md', MAPPING: outsideMapping }],
]) {
  const run = spawnSync('bash', ['scripts/import-workbook.sh'], {
    cwd: repositoryRoot,
    encoding: 'utf8',
    env: { PATH: process.env.PATH, ...environment },
  });

  if (run.status !== 2 || !run.stderr.includes('outside the repository')) {
    fail(`The import script must refuse a ${name} inside the repository with exit code 2`);
  }
}

rmSync(importDirectory, { recursive: true, force: true });
