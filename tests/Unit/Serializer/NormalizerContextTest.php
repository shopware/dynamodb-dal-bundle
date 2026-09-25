<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Serializer;

use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NormalizerContext::class)]
class NormalizerContextTest extends TestCase
{
    /**
     * `null` is the value of a path a write removes, so it has to stay apart from a path the write never names.
     */
    public function testAnAbsentPathIsStillPresent(): void
    {
        $context = $this->context(['name' => null]);

        static::assertTrue($context->has('name'));
        static::assertNull($context->get('name'));
        static::assertFalse($context->has('required'));
        static::assertNull($context->get('required'));
    }

    /**
     * An update may write into an attribute without naming it, so a rule about the attribute has to find its
     * nested paths too, but not a longer attribute name that happens to share the prefix.
     */
    public function testWithinFindsThePathAndThoseNestedUnderIt(): void
    {
        $context = $this->context([
            'meta.kind' => 'invoice',
            'meta.channel' => null,
            'metadata' => 'other attribute',
            'tags[1]' => 'second',
            'name' => 'whole',
        ]);

        static::assertSame(['meta.kind' => 'invoice', 'meta.channel' => null], $context->getWithin('meta'));
        static::assertSame(['tags[1]' => 'second'], $context->getWithin('tags'));
        static::assertSame(['name' => 'whole'], $context->getWithin('name'));
        static::assertSame([], $context->getWithin('required'));

        static::assertTrue($context->hasWithin('meta'));
        static::assertTrue($context->hasWithin('meta.channel'));
        static::assertFalse($context->hasWithin('required'));
        static::assertFalse($context->has('meta'));
    }

    public function testSetAddsOrReplacesAPath(): void
    {
        $context = $this->context(['name' => 'a']);

        $context->set('name', 'b');
        $context->set('required', 'c');

        static::assertSame(['name' => 'b', 'required' => 'c'], $context->getFields());
    }

    /**
     * Filling in a path the write does not name would add it: a generated `createdAt` on an update would overwrite
     * the stored one, and a key would gain an attribute it does not have.
     */
    public function testSetIfUnsetFillsOnlyAPathThatIsPresentWithoutAValue(): void
    {
        $context = $this->context(['name' => null, 'required' => 'kept']);

        $context->setIfUnset('name', 'filled');
        $context->setIfUnset('required', 'ignored');
        $context->setIfUnset('requiredNullableName', 'ignored');

        static::assertSame(['name' => 'filled', 'required' => 'kept'], $context->getFields());
    }

    public function testRemoveKeepsThePathPresentAsNull(): void
    {
        $context = $this->context(['name' => 'a']);

        $context->remove('name');
        $context->remove('required');

        static::assertSame(['name' => null, 'required' => null], $context->getFields());
    }

    public function testOmitLeavesThePathOut(): void
    {
        $context = $this->context(['name' => 'a', 'required' => 'b']);

        $context->omit('name');
        $context->omit('unknown');

        static::assertFalse($context->has('name'));
        static::assertSame(['required' => 'b'], $context->getFields());
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function context(array $fields): NormalizerContext
    {
        return NormalizerContext::fromFields(NormalizerOperation::Update, $fields);
    }
}
