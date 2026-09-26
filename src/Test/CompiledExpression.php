<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Test;

use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Exception\DALException;
use Shopware\DynamodbDalBundle\Exception\UpdateDuplicatePathException;
use Shopware\DynamodbDalBundle\Exception\UpdateEmptyException;
use Shopware\DynamodbDalBundle\Expression\Contract\FilterInterface;
use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\ExpressionCompiler;
use Shopware\DynamodbDalBundle\Expression\Update;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use AsyncAws\DynamoDb\ValueObject\AttributeValue;

/**
 * A filter or update compiled as a request sends it, for a unit test of a filter or update action of your own.
 * {@see resolved()} reads it back without its placeholders, so a test need not depend on how they are named:
 *
 * ```
 * $compiled = CompiledExpression::ofFilter(EntityDefinitionFactory::create(OrderEntity::class), new SizeGreaterThanFilter('tags', 2));
 *
 * static::assertSame('size(tags) > 2', $compiled->resolved());
 * ```
 */
final readonly class CompiledExpression
{
    /**
     * @param ?string $expression - the expression, `null` where it compiles to nothing
     * @param array<string, string> $names - `ExpressionAttributeNames`: placeholder => attribute name
     * @param array<string, AttributeValue> $values - `ExpressionAttributeValues`: placeholder => value
     */
    private function __construct(
        public ?string $expression,
        public array $names,
        public array $values,
    ) {
    }

    /**
     * A filter compiled as a search's filter, a key condition or a write condition compiles it.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @throws DALException if the filter names a field the entity does not have, or a value that does not serialize for it
     */
    public static function ofFilter(EntityDefinition $definition, FilterInterface $filter): self
    {
        $result = new ExpressionCompiler(new Serializer())->compileFilter($definition, $filter);

        return new self($result->expression, $result->names, $result->values);
    }

    /**
     * An update compiled as a write sends it: passed through the entity's normalizer first, as `$context->operation`
     * `Update`, then compiled.
     *
     * @param EntityDefinition<AbstractEntity> $definition
     *
     * @throws UpdateEmptyException if the update writes nothing
     * @throws UpdateDuplicatePathException
     * @throws DALException if the update names a field the entity does not have, or a value that does not serialize for it
     */
    public static function ofUpdate(EntityDefinition $definition, UpdateExpression|UpdateActionInterface $update): self
    {
        [, $result] = new ExpressionCompiler(new Serializer())->compileUpdate(
            $definition,
            $update instanceof UpdateExpression ? $update : Update::with($update),
        );

        return new self($result->expression, $result->names, $result->values);
    }

    /**
     * The expression with each placeholder replaced by what it stands for: a name by the attribute name, and a value
     * by its content. A string is quoted, a number, a boolean and `null` read as they are, a list as `[…]`, a map as
     * `{"key": …}`, and a set as `<<…>>`. `null` where the expression compiles to nothing.
     */
    public function resolved(): ?string
    {
        if ($this->expression === null) {
            return null;
        }

        return strtr($this->expression, [...$this->names, ...array_map(self::render(...), $this->values)]);
    }

    private static function render(AttributeValue $value): string
    {
        $quote = static fn (string $string): string => json_encode($string, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE) ?: '""';

        return match (true) {
            $value->getS() !== null => $quote($value->getS()),
            $value->getN() !== null => $value->getN(),
            $value->getBool() !== null => $value->getBool() ? 'true' : 'false',
            $value->getNull() !== null => 'null',
            $value->getB() !== null => $quote(base64_encode($value->getB())),
            $value->getSs() !== [] => '<<' . implode(', ', array_map($quote, $value->getSs())) . '>>',
            $value->getNs() !== [] => '<<' . implode(', ', $value->getNs()) . '>>',
            $value->getBs() !== [] => '<<' . implode(', ', array_map(static fn (string $binary): string => $quote(base64_encode($binary)), $value->getBs())) . '>>',
            $value->getM() !== [] => '{' . implode(', ', array_map(
                static fn (string $key, AttributeValue $entry): string => $quote($key) . ': ' . self::render($entry),
                array_keys($value->getM()),
                $value->getM(),
            )) . '}',
            // An empty map has no marker AsyncAws keeps apart from an empty list
            default => '[' . implode(', ', array_map(self::render(...), $value->getL())) . ']',
        };
    }
}
