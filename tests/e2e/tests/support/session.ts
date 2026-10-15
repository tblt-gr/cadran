import { tmpdir } from 'node:os';
import { join } from 'node:path';

/** Where the setup project stores the signed-in session the other projects start from. */
export const ownerStorageState = join(tmpdir(), 'cadran-e2e-owner.json');

/** The provisioned owner (`make e2e-provision`); there is no default for a real installation. */
export function ownerCredentials(): { email: string; password: string } {
  const email = process.env.E2E_OWNER_EMAIL;
  const password = process.env.E2E_OWNER_PASSWORD;
  if (!email || !password) {
    throw new Error('Set E2E_OWNER_EMAIL and E2E_OWNER_PASSWORD to the provisioned e2e owner.');
  }

  return { email, password };
}
