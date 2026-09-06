<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

interface AuthenticationUserRepository
{
    /**
     * Non-credential lookup by email, case-insensitive. Used to describe the
     * session of an already-authenticated request.
     */
    public function findByEmail(string $email): ?AuthenticatedUser;

    /**
     * Non-credential lookup by identifier. Used once a request is authenticated
     * and its actor is known, so no email round-trip is needed.
     */
    public function findById(string $userId): ?AuthenticatedUser;

    /**
     * Credential lookup by email, case-insensitive. Returns null when there is
     * no such user or the user has no password yet. Used only by the firewall.
     */
    public function findCredentialsByEmail(string $email): ?OwnerCredentials;

    /**
     * Credential lookup by identifier, for the one flow that must re-verify a
     * password it already authenticated: changing it.
     */
    public function findCredentialsById(string $userId): ?OwnerCredentials;

    /**
     * Returns the single provisioned owner account, or null before IDN-001 has
     * run. Used to decide whether the first-run password flow is still pending.
     */
    public function findProvisionedOwner(): ?AuthenticatedUser;
}
