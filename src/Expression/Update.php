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
     * Adds to a number, counting a missing one as 0. Shorthand for {@see self::add()} with a number.
     */
    public static function increment(string $fieldName, int|float $by = 1): UpdateExpression
    {
        return self::add($fieldName, $by);
    }

    /**
     * Subtracts from a number, counting a missing one as 0. Shorthand for {@see self::add()} with a
     * negative number.
     */
    public static function decrement(string $fieldName, int|float $by = 1): UpdateExpression
    {
        return self::add($fieldName, -$by);
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
     * DynamoDB's `ADD`: adds to a number, counting a missing one as 0, or adds elements to a set, creating a
     * missing one.
     */
    public static function add(string $fieldName, mixed $value): UpdateExpression
    {
        return self::with(new AddAction($fieldName, $value));
    }

    /**
     * DynamoDB's `DELETE`: removes elements from a set. To remove an attribute, use {@see self::remove()}.
     */
    public static function delete(string $fieldName, mixed $value): UpdateExpression
    {
        return self::with(new DeleteAction($fieldName, $value));
    }

    /**
     * Combines expressions, and actions of your own, into one, as `Filter::and()` combines filters. See
     * {@see UpdateExpression::with()}.
     */
    public static function with(UpdateExpression|UpdateActionInterface ...$updates): UpdateExpression
    {
        return new UpdateExpression()->with(...$updates);
    }
}
