<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression\Update;

use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Definition\FieldPath;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\FieldMissingSerializedValueException;
use Shopware\DynamodbDalBundle\Exception\UnknownFieldException;
use Shopware\DynamodbDalBundle\Expression\Contract\NormalizableUpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompileContext;
use Shopware\DynamodbDalBundle\Expression\Update;

/**
 * A whole DynamoDB update expression: fields set to a given value, and actions whose value DynamoDB
 * computes from the stored item.
 *
 * The fields are what an array handed to {@see UpdateInput} becomes. Like the fields of a put, they pass
 * through the entity's normalizer, and a written entity can take them without reading the row back.
 * An action passes the normalizer only an input it selects as a value of its field, see
 * {@see NormalizableUpdateActionInterface}, and its result is never known without reading the row back.
 *
 * Built with {@see Update}. {@see self::with()} combines it with further expressions and actions, and leaves
 * this one as it is.
 */
final class UpdateExpression
{
    /**
     * @var list<UpdateActionInterface>
     */
    public readonly array $actions;

    /**
     * @param array<string, mixed> $fields - keyed by path, `null` removes the path
     * @param list<UpdateActionInterface> $actions
     */
    public function __construct(
        public readonly array $fields = [],
        array $actions = [],
    ) {
        $this->actions = array_values($actions);
    }

    /**
     * Combines this with further expressions, and actions of your own, into a new expression. A path set by
     * several takes the last value, and actions keep their order.
     */
    public function with(self|UpdateActionInterface ...$updates): self
    {
        $fields = $this->fields;
        $actions = $this->actions;

        foreach ($updates as $update) {
            if ($update instanceof UpdateActionInterface) {
                $actions[] = $update;

                continue;
            }

            $fields = [...$fields, ...$update->fields];
            $actions = [...$actions, ...$update->actions];
        }

        return new self($fields, $actions);
    }

    /**
     * Whether this only sets or removes whole fields, so the fields alone are the new value of everything it writes.
     * A path into an attribute keeps the rest of that attribute as stored, and an action computes its value from the stored one.
     * The writer decides by it whether a transaction reads the entity back.
     *
     * @internal
     */
    public function onlySetsWholeFields(EntityDefinition $definition): bool
    {
        return $this->actions === [] && array_all(
            array_keys($this->fields),
            static fn (string $name): bool => $definition->getFieldDefinition($name) !== null,
        );
    }

    /**
     * Compile this into the whole update expression, `null` when there is nothing to write.
     *
     * @throws DALException if a field or an action names a field the entity does not have, or a value that does not serialize for it
     */
    public function compile(ExpressionCompileContext $context): ?string
    {
        /** @var array<string, list<string>> $clauses */
        $clauses = [];

        foreach ($this->fields as $fieldName => $value) {
            if ($value !== null) {
                $clauses[UpdateClause::Set->value][] = "{$context->path($fieldName)} = {$context->value($fieldName, $value)}";

                continue;
            }

            // DynamoDB has no null attribute, so null removes, and a field that may not be null cannot be removed.
            $path = FieldPath::tryParse($context->definition, $fieldName) ?? throw new UnknownFieldException($context->definition, $fieldName);
            if (!$path->definition->allowsNull()) {
                throw new FieldMissingSerializedValueException($path->definition);
            }

            $clauses[UpdateClause::Remove->value][] = $context->path($fieldName);
        }

        foreach ($this->actions as $action) {
            $fragment = $action->compile($context);
            if ($fragment !== null) {
                $clauses[$action->getClause()->value][] = $fragment;
            }
        }

        $expression = [];
        foreach (UpdateClause::cases() as $clause) {
            if (isset($clauses[$clause->value])) {
                $expression[] = $clause->value . ' ' . implode(', ', $clauses[$clause->value]);
            }
        }

        return $expression === [] ? null : implode(' ', $expression);
    }
}
