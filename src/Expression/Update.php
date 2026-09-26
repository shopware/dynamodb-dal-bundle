<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Expression;

use Shopware\DynamodbDalBundle\Client\Input\UpdateInput;
use Shopware\DynamodbDalBundle\Expression\Contract\UpdateActionInterface;
use Shopware\DynamodbDalBundle\Expression\Update\AddAction;
use Shopware\DynamodbDalBundle\Expression\Update\DeleteAction;
use Shopware\DynamodbDalBundle\Expression\Update\ListAppendAction;
use Shopware\DynamodbDalBundle\Expression\Update\SetIfNotExistsAction;
use Shopware\DynamodbDalBundle\Expression\Update\UpdateExpression;

/**
 * Static factory for the update expressions passed to {@see UpdateInput}, the counterpart of {@see Filter}.
 * Each method returns a whole {@see UpdateExpression}, and {@see self::with()} combines several into one:
 *
 * ```
 * $input = new UpdateInput(
 *     $order,
 *     Update::with(
 *         Update::set('status', OrderStatus::Paid),
 *         Update::remove('note'),
 *         Update::increment('attempts'),
 *     ),
 * );
 * ```
 *
 * An array handed to {@see UpdateInput} is shorthand for {@see self::setFields()}.
 */
final class Update
{
    private function __construct()
    {
    }

    /**
     * Sets each path to its value, or removes it for `null`.
     *
     * @param array<string, mixed> $fields
     */
    public static function setFields(array $fields): UpdateExpression
    {
        return new UpdateExpression($fields);
    }

    /**
     * Sets the path to the value, or removes it for `null`.
     */
    public static function set(string $fieldName, mixed $value): UpdateExpression
    {
        return self::setFields([$fieldName => $value]);
    }

    public static function remove(string $fieldName): UpdateExpression
    {
        return self::set($fieldName, null);
    }

    /**
     * Sets the path to the value only where it holds none yet, e.g. to stamp a `firstSeenAt` once.
     */
    public static function setIfNotExists(string $fieldName, mixed $value): UpdateExpression
    {
        return self::with(new SetIfNotExistsAction($fieldName, $value));
    }

    /**
     * Adds to a number with DynamoDB's `ADD`, counting a missing one as 0.
     */
    public static function increment(string $fieldName, int|float $by = 1): UpdateExpression
    {
        return self::with(new AddAction($fieldName, $by));
    }

    /**
     * Subtracts from a number with DynamoDB's `ADD` of the negative step, counting a missing one as 0.
     */
    public static function decrement(string $fieldName, int|float $by = 1): UpdateExpression
    {
        return self::with(new AddAction($fieldName, -$by));
    }

    /**
     * Adds elements to the end of a list, counting a missing list as empty.
     *
     * @param list<mixed> $values
     */
    public static function append(string $fieldName, array $values): UpdateExpression
    {
        return self::with(new ListAppendAction($fieldName, $values));
    }

    /**
     * Adds elements to the start of a list, counting a missing list as empty.
     *
     * @param list<mixed> $values
     */
    public static function prepend(string $fieldName, array $values): UpdateExpression
    {
        return self::with(new ListAppendAction($fieldName, $values, prepend: true));
    }

    /**
     * Adds elements to a set with DynamoDB's `ADD`, creating a missing set.
     * `$elements` is a value of the set field, serialized by its serializer.
     */
    public static function addToSet(string $fieldName, mixed $elements): UpdateExpression
    {
        return self::with(new AddAction($fieldName, $elements));
    }

    /**
     * Removes elements from a set with DynamoDB's `DELETE`.
     * `$elements` is a value of the set field, serialized by its serializer.
     * To remove the attribute itself, use {@see self::remove()}.
     */
    public static function removeFromSet(string $fieldName, mixed $elements): UpdateExpression
    {
        return self::with(new DeleteAction($fieldName, $elements));
    }

    /**
     * Combines expressions, and actions of your own, into one, as `Filter::and()` combines filters.
     * See {@see UpdateExpression::with()}.
     */
    public static function with(UpdateExpression|UpdateActionInterface ...$updates): UpdateExpression
    {
        return new UpdateExpression()->with(...$updates);
    }
}
