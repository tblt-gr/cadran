import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
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

const caddyfile = readFileSync(new URL('../docker/app/Caddyfile', import.meta.url), 'utf8');

if (caddyfile.includes('Strict-Transport-Security')) {
  fail('The localhost runtime must not persist an HSTS policy in the browser');
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
