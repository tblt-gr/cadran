<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\InvalidProductModel;
use App\Module\Accounts\Domain\ModelProvenance;
use App\Module\Accounts\Domain\ModelRule;
use App\Module\Accounts\Domain\ModelRuleSchedule;
use App\Module\Accounts\Domain\ModelRuleValue;
use App\Module\Accounts\Domain\ProductModel;
use App\Module\Accounts\Domain\ProductModelRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Application\ProductCatalog;
use App\Module\Catalog\Domain\CatalogEntry;
use App\Module\Catalog\Domain\InvalidCatalogEntry;
use App\Module\Catalog\Domain\ProductCode;
use App\Module\Catalog\Domain\ProductRule;
use App\Module\Catalog\Domain\RuleValueType;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use Symfony\Component\Clock\ClockInterface;

/**
 * Starts a workspace model from a system catalogue product.
 *
 * The copy is made by the server from the catalogue row rather than from a
 * body the client composed: a model that claims to have started from a Livret
 * A must actually carry what the catalogue says a Livret A is, or its
 * provenance would be a label anyone could paste on anything.
 *
 * What it copies is the description and the dated periods with their effective
 * dates. What it drops is the provenance of each figure: a catalogue rule
 * carries the publication it was read from and the trace of who checked it,
 * and neither survives the copy, because nobody published the workspace model.
 * From this moment the two stop tracking each other, so a later catalogue
 * revision never silently changes what the workspace model says.
 */
final readonly class CreateProductModelFromProduct
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private ProductModelRepository $models,
        private ProductCatalog $catalog,
        private UuidGenerator $uuidGenerator,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $productCode, string $name, string $valuationMode): ProductModelView
    {
        $context = $this->caller->resolveContext();
        $mode = ProductModelInputParser::valuationMode($valuationMode);
        $modelName = trim($name);

        try {
            $code = ProductCode::fromString($productCode);
        } catch (InvalidCatalogEntry $failure) {
            // A code that cannot exist and a code that does not exist answer
            // alike, so a client cannot probe the catalogue through the shape
            // of the refusal.
            throw new InvalidProductModelInput('The product must exist in the system catalogue.', previous: $failure);
        }

        $entry = $this->catalog->findByCode($code);
        if (null === $entry) {
            throw new InvalidProductModelInput('The product must exist in the system catalogue.');
        }

        return $this->transactionBoundary->transactional(function () use ($context, $entry, $code, $modelName, $mode): ProductModelView {
            if ($this->models->hasActiveName($context->workspace, $modelName)) {
                throw new ProductModelConflict('An active model already uses this name.');
            }

            $now = $this->clock->now();
            $product = $entry->product;

            try {
                $model = new ProductModel(
                    id: $this->uuidGenerator->generate(),
                    workspace: $context->workspace,
                    name: $modelName,
                    family: $product->accountKind,
                    wrapperKind: $product->wrapperKind,
                    yieldKind: $product->yieldKind,
                    defaultGroupCode: $product->defaultGroupCode,
                    valuationMode: $mode,
                    capabilities: $product->capabilities,
                    provenance: ModelProvenance::fromSystemProduct($code),
                    schedule: new ModelRuleSchedule($this->copiedRules($entry)),
                    version: 1,
                    createdAt: $now,
                    updatedAt: $now,
                );
            } catch (InvalidProductModel $exception) {
                throw new InvalidProductModelInput($exception->getMessage(), previous: $exception);
            }

            $this->models->add($model);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: ProductModelAuditEvents::DUPLICATED,
                entityType: ProductModelAuditEvents::ENTITY,
                entityId: $model->id,
                diff: AuditDiff::creation(ProductModelAuditFingerprint::of($model)),
            ));

            return ProductModelView::fromModel($model);
        });
    }

    /**
     * @return list<ModelRule>
     */
    private function copiedRules(CatalogEntry $entry): array
    {
        $rules = [];
        foreach ($entry->schedule->rules as $rule) {
            $rules[] = new ModelRule(
                id: $this->uuidGenerator->generate(),
                kind: $rule->kind,
                value: self::copiedValue($rule),
                period: $rule->period,
            );
        }

        return $rules;
    }

    private static function copiedValue(ProductRule $rule): ModelRuleValue
    {
        return match ($rule->kind->valueType()) {
            RuleValueType::AMOUNT => ModelRuleValue::amount(
                $rule->value->amount ?? throw new \LogicException('A catalogue amount rule carries its amount.'),
            ),
            // A published rate resolves to a one-bracket scale, which is the
            // shape every workspace rate travels in. Copying it that way is
            // what lets a holder then add a bracket without re-entering the
            // figure the catalogue already gave.
            RuleValueType::PERCENTAGE => ModelRuleValue::rate(
                $rule->rateScale() ?? throw new \LogicException('A catalogue rate rule carries its rate.'),
            ),
            RuleValueType::TEXT => ModelRuleValue::text(
                $rule->value->text ?? throw new \LogicException('A catalogue text rule carries its token.'),
            ),
        };
    }
}
