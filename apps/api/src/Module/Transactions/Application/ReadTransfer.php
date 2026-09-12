<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application;

use App\Module\Foundation\Application\CallerWorkspace;
use App\Module\Transactions\Domain\TransferRepository;

final readonly class ReadTransfer
{
    public function __construct(
        private CallerWorkspace $caller,
        private TransferRepository $transfers,
        private PresentTransfer $presentTransfer,
    ) {
    }

    public function __invoke(string $id): TransferView
    {
        $transfer = $this->transfers->find($this->caller->resolve(), $id);
        if (null === $transfer) {
            throw new TransferNotFound();
        }

        return $this->presentTransfer->one($transfer);
    }
}
