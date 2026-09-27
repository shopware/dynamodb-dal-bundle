<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\Contract\NormalizableUpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Compiles an {@see UpdateExpression} into an {@see ExpressionCompiledResult}.
 * The update passes through the entity's normalizer first, as the fields of a put do.
 *
 * Value placeholders are unique per call, also against a {@see FilterCompiler}'s, so an update merges with its
 * condition into one request. Name placeholders are derived from the attribute, so every result agrees on them.
 *
 * @internal
 */
class UpdateCompiler implements ResetInterface
{
    /**
     * Namespaces the value placeholders of a compile. u = Update
     */
    private const string PREFIX = 'u_';

    /**
     * The number of the last compile, which its prefix carries.
     */
    private int $sequence = 0;

    /**
     * @internal
     */
    public function __construct(
        private readonly Serializer $serializer,
    ) {
    }

    /**
     * Normalizes the update, then compiles it.
     *
     * @throws UpdateEmptyException if the update has nothing to write
     * @throws UpdateDuplicatePathException
     * @throws DALException if the update names a field the entity does not have, or a value that does not serialize for it
     *
     * @return array{UpdateExpression, ExpressionCompiledResult} - the update as normalized, which is what an entity can take back, and its compiled form
     */
    public function update(EntityDefinition $definition, UpdateExpression $update): array
    {
        $normalized = $this->normalizeUpdate($definition, $update);
        $context = new ExpressionCompileContext($definition, self::PREFIX . dechex(++$this->sequence));

        $compiled = $normalized->compile($context);
        if ($compiled === null || trim($compiled) === '') {
            throw new UpdateEmptyException($definition);
        }

        return [$normalized, new ExpressionCompiledResult($compiled, $context->names, $context->values)];
    }

    public function reset(): void
    {
        $this->sequence = 0;
    }

    /**
     * Passes the update's fields and the input of every {@see NormalizableUpdateActionInterface} through the entity's
     * normalizer in one call, keyed by path, so it sees every value the update stores as given and never an action.
     * Each action takes its input back, and one whose path the normalizer left out is dropped. A field the normalizer
     * adds becomes a field, and one it drops is not written.
     *
     * @throws UpdateDuplicatePathException
     */
    private function normalizeUpdate(EntityDefinition $definition, UpdateExpression $update): UpdateExpression
    {
        $inputs = [];
        foreach ($update->actions as $action) {
            if (!$action instanceof NormalizableUpdateActionInterface) {
                continue;
            }

            // The map holds one value per path, so a second one would replace the first without a word, and a
            // null among them would even hide the overlap from DynamoDB.
            $path = $action->getPath();
            if (\array_key_exists($path, $update->fields) || \array_key_exists($path, $inputs)) {
                throw new UpdateDuplicatePathException($definition, $path);
            }

            $inputs[$path] = $action->getValue();
        }

        $normalized = $this->serializer->normalize($definition, [...$update->fields, ...$inputs], NormalizerOperation::Update);

        $actions = [];
        foreach ($update->actions as $action) {
            if (!$action instanceof NormalizableUpdateActionInterface) {
                $actions[] = $action;
            } elseif (\array_key_exists($action->getPath(), $normalized)) {
                $actions[] = $action->withValue($normalized[$action->getPath()]);
            }
        }

        return new UpdateExpression(
            array_filter($normalized, static fn (string $path): bool => !\array_key_exists($path, $inputs), \ARRAY_FILTER_USE_KEY),
            $actions,
        );
    }
}
