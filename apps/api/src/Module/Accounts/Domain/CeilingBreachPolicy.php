<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

/**
 * What happens when a figure passes a ceiling.
 *
 * Passing one is not, on its own, a fault the application may act on. Interest
 * credited by the institution legitimately carries a regulated passbook past
 * its deposit ceiling, and a historical import records what an account really
 * held, including years the holder was over. Refusing either would destroy a
 * true figure to protect a rule that was never broken.
 *
 * The policy is therefore stated with the ceiling rather than decided by
 * whichever screen or importer meets it first, so a breach always surfaces the
 * same way: reported, never refused.
 */
enum CeilingBreachPolicy: string
{
    /** The breach is reported to the holder and the figure is kept as recorded. */
    case WARN = 'WARN';
}
