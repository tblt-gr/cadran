<?php

declare(strict_types=1);

namespace App\Tests\Module\Import\Support;

use App\Module\Accounts\Application\CreateAccount;
use App\Module\Accounts\Application\CreateAccountInput;
use App\Module\Categories\Application\CreateCategory;
use App\Module\Categories\Application\CreateCategoryInput;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** Creates, through the use cases, the accounts and categories the synthetic mapping names. The owner must already be assumed. */
final readonly class ImportWorkspace
{
    public function __construct(private ContainerInterface $container)
    {
    }

    public function populate(string $openedOn = '2024-06-01'): void
    {
        $account = $this->container->get(CreateAccount::class);
        if (!$account instanceof CreateAccount) {
            throw new \LogicException('The container did not return the account use case.');
        }
        foreach ([
            ['Main current account', 'CURRENT', 'TRANSACTIONS'],
            ['Savings A', 'SAVINGS', 'TRANSACTIONS'],
            ['Meal vouchers', 'EMPLOYEE_BENEFIT', 'TRANSACTIONS'],
            ['PEA account', 'PORTFOLIO', 'PORTFOLIO'],
            ['Broker account', 'PORTFOLIO', 'PORTFOLIO'],
        ] as [$label, $kind, $mode]) {
            $account(new CreateAccountInput(
                $label, 'EUR', $kind, null, null, null, null, $mode, 'IMMEDIATE', false, false, $openedOn, null,
            ));
        }

        $category = $this->container->get(CreateCategory::class);
        if (!$category instanceof CreateCategory) {
            throw new \LogicException('The container did not return the category use case.');
        }
        foreach ([
            ['INCOME', 'Income', ['Salary', 'Meals', 'Interest']],
            ['EXPENSE', 'Housing', ['Rent']],
            ['EXPENSE', 'Food', ['Groceries', 'Meals']],
            ['EXPENSE', 'Transport', ['Fuel']],
        ] as $order => [$type, $root, $children]) {
            $parent = $category(new CreateCategoryInput($type, $root, null, null, null, [], true, $order * 10))->id;
            foreach ($children as $index => $child) {
                $category(new CreateCategoryInput($type, $child, $parent, null, null, 'Fuel' === $child ? ['ESSENTIAL'] : [], true, $index));
            }
        }
    }
}
