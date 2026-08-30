<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

interface TransactionManager
{
    /**
     * @template T
     *
     * @param \Closure(): T $callback
     *
     * @return T
     */
    public function transactional(\Closure $callback): mixed;
}
