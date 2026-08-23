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
