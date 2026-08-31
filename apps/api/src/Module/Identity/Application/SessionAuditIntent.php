<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * A session outcome waiting to be audited once the response has been sent.
 *
 * Recording inline would put a database round trip on the authentication
 * response path. On a failed sign-in that is measurable: the write only happens
 * for an email that matches an account, so its latency would tell an attacker
 * which addresses exist — the very channel the dummy password verify in
 * IdentityUserProvider exists to close. On sign-out and sign-in it would also
 * let a write failure break an operation that has already succeeded.
 */
final readonly class SessionAuditIntent
{
    public const string REQUEST_ATTRIBUTE = '_cadran_session_audit';

    private const array RECORDABLE = [
        IdentityAuditEvents::SESSION_OPENED,
        IdentityAuditEvents::SESSION_CLOSED,
        IdentityAuditEvents::SIGN_IN_FAILED,
    ];

    public function __construct(
        public string $eventType,
        public string $email,
    ) {
        if (!in_array($eventType, self::RECORDABLE, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is not a session audit event.', $eventType));
        }

        if ('' === $email) {
            throw new \InvalidArgumentException('A session audit intent names the account it concerns.');
        }
    }
}
