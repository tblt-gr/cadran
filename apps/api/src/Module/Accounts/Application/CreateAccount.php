<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Accounts\Domain\InvalidAccount;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Reference\Application\AssetCatalog;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateAccount
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private AssetCatalog $assets,
        private AccountProduct $products,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateAccountInput $input): AccountView
    {
        $context = $this->caller->resolveContext();
        $assetCode = AccountInputParser::assetCode($input->assetCode);
        $kind = AccountInputParser::kind($input->kind);
        $valuationMode = AccountInputParser::valuationMode($input->valuationMode);
        $liquidityLevel = AccountInputParser::liquidityLevel($input->liquidityLevel);
        $maskedIdentifier = AccountInputParser::maskedIdentifier($input->maskedIdentifier);
        $openedOn = AccountInputParser::businessDay($input->openedOn, 'opening date');
        $closedOn = AccountInputParser::optionalBusinessDay($input->closedOn, 'closing date');
        $label = trim($input->label);
        $institution = AccountInputParser::institution($input->institution);
        // The catalogue decides whether the submitted kind and valuation mode
        // are the ones this product declares, so a tampered form cannot file a
        // PEA as a plain savings account.
        $productCode = $this->products->resolve($input->productCode, $kind, $valuationMode);

        // The asset reference is global and read-only, so an unknown code is a
        // client error rather than something the workspace could create.
        if (null === $this->assets->findByCode($assetCode)) {
            throw new InvalidAccountInput('The account asset must exist in the system reference.');
        }

        return $this->transactionBoundary->transactional(function () use (
            $context,
            $label,
            $assetCode,
            $kind,
            $productCode,
            $institution,
            $maskedIdentifier,
            $valuationMode,
            $liquidityLevel,
            $input,
            $openedOn,
            $closedOn,
        ): AccountView {
            if ($this->accounts->hasActiveLabel($context->workspace, $label)) {
                throw new AccountConflict('An active account already uses this label.');
            }

            $now = $this->clock->now();

            try {
                $account = new Account(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    label: $label,
                    assetCode: $assetCode,
                    kind: $kind,
                    productCode: $productCode,
                    institution: $institution,
                    maskedIdentifier: $maskedIdentifier,
                    valuationMode: $valuationMode,
                    liquidityLevel: $liquidityLevel,
                    includeInNetWorth: $input->includeInNetWorth,
                    includeInEmergencyFund: $input->includeInEmergencyFund,
                    openedOn: $openedOn,
                    closedOn: $closedOn,
                    version: 1,
                    createdAt: $now,
                    updatedAt: $now,
                );
            } catch (InvalidAccount $exception) {
                throw new InvalidAccountInput($exception->getMessage(), previous: $exception);
            }

            $this->accounts->add($account);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: AccountAuditEvents::CREATED,
                entityType: AccountAuditEvents::ENTITY,
                entityId: $account->id,
                diff: AuditDiff::creation(AccountAuditFingerprint::of($account)),
            ));

            return AccountView::fromAccount($account);
        });
    }
}
