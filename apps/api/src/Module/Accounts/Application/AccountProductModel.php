<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * Binds an account to the workspace product model it is created from.
 *
 * The submitted identifier is never trusted as a label: it is looked up in
 * the calling workspace, and the account attributes the client sent are
 * checked against what the model actually declares. That is what stops a
 * form from activating a capability the model does not carry, or from filing
 * a loan as a savings account so a later ceiling is read on the wrong basis.
 *
 * A model belongs to one workspace, so an unknown identifier and one owned by
 * another workspace answer alike here: the repository scopes the lookup, and
 * neither refusal confirms that the identifier is real.
 */
final readonly class AccountProductModel
{
    public function __construct(private ProductModelRepository $models)
    {
    }

    public function resolve(
        WorkspaceScope $workspace,
        ?string $id,
        AccountKind $kind,
        AccountValuationMode $valuationMode,
    ): ?string {
        if (null === $id) {
            return null;
        }

        // A malformed identifier and one that does not exist answer alike, so
        // a client cannot probe the workspace's models through the shape of
        // the refusal.
        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id)) {
            throw new InvalidAccountInput('The account model must exist in the workspace.');
        }

        $model = $this->models->find($workspace, $id);
        if (null === $model) {
            throw new InvalidAccountInput('The account model must exist in the workspace.');
        }

        // Archiving is how a model stops backing new use. An account already
        // referencing it keeps its reference regardless; only a new use of an
        // archived model is refused here.
        if ($model->isArchived()) {
            throw new InvalidAccountInput('An archived model cannot back a new account.');
        }

        if ($model->family !== $kind) {
            throw new InvalidAccountInput('The account kind must be the one its model declares.');
        }

        if (!$model->capabilities->contains($valuationMode->requiredCapability())) {
            throw new InvalidAccountInput('The account valuation mode requires a capability this model does not declare.');
        }

        return $model->id;
    }
}
