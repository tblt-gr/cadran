<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

/**
 * A later keyset page's cursor names a workspace watermark that no longer
 * matches: something changed between the two requests. HTTP pagination
 * cannot hold a database snapshot open across requests, so this is a
 * best-effort change *detector*, answered as `409 transactions.cursor_stale`
 * rather than silently serving a possibly-inconsistent page.
 */
final class TransactionCursorStale extends \RuntimeException
{
}
