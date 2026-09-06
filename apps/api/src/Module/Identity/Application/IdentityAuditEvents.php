<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

/**
 * The identity operations that reach the audit trail, named once so the
 * console command, the first-run flow and the firewall handlers cannot drift.
 */
final class IdentityAuditEvents
{
    public const string ENTITY_USER = 'user';
    public const string ENTITY_WORKSPACE = 'workspace';
    public const string ENTITY_MEMBERSHIP = 'membership';

    public const string USER_CREATED = 'user.created';
    public const string WORKSPACE_CREATED = 'workspace.created';
    public const string MEMBERSHIP_GRANTED = 'membership.granted';
    public const string PASSWORD_DEFINED = 'user.password_defined';
    public const string PASSWORD_CHANGED = 'user.password_changed';
    public const string PASSWORD_CHANGE_FAILED = 'user.password_change_failed';
    public const string PROFILE_UPDATED = 'user.profile_updated';
    public const string SESSION_OPENED = 'session.opened';
    public const string SESSION_CLOSED = 'session.closed';
    public const string SIGN_IN_FAILED = 'session.sign_in_failed';
}
