<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Integration;

use AsyncAws\DynamoDb\ValueObject\AttributeValue;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Shopware\DynamodbDalBundle\AbstractEntity;
use Shopware\DynamodbDalBundle\Definition\EntityDefinition;
use Shopware\DynamodbDalBundle\Serializer\SerializedResult;
use Shopware\DynamodbDalBundle\Serializer\NormalizerOperation;
use Shopware\DynamodbDalBundle\Serializer\Serializer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\Address;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ContactEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\ContactEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntity;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\NormalizedEntityNormalizer;
use Shopware\DynamodbDalBundle\Tests\Integration\Fixtures\Entity\RecordEntity;
use Symfony\Component\DependencyInjection\Container;

/**
 * Drives the real {@see Serializer} against a compiled {@see EntityDefinition} — its real normalizer and
 * field serializers, no mocks — and asserts that an item reads back into the entity it was written from.
 */
#[CoversClass(Serializer::class)]
#[CoversClass(SerializedResult::class)]
class SerializerTest extends TestCase
{
    use CompilesContainerTrait;

    private Container $container;

    protected function setUp(): void
    {
        $this->container = $this->compileContainer(
            [
                RecordEntity::class => 'phpunit-record',
                NormalizedEntity::class => 'phpunit-normalized',
                ContactEntity::class => 'phpunit-contact',
            ],
            [NormalizedEntityNormalizer::class, ContactEntityNormalizer::class],
            [Serializer::class],
        );
    }

    /**
     * A `JsonSerializable` value object is written as its JSON but read back as the decoded array, so
     * only the entity's normalizer turns it into the object again.
     */
    public function testANormalizerReadsAJsonSerializableFieldBackIntoItsObject(): void
    {
        $definition = $this->definition('contact');

        $contact = new ContactEntity();
        $contact->id = 'contact-1';
        $contact->address = new Address('Main Street 1', 'Springfield');

        $item = $this->serializer()->serialize($definition, $contact, NormalizerOperation::Put)->getFields();

        static::assertEquals(new AttributeValue(['S' => '{"street":"Main Street 1","city":"Springfield"}']), $item['address'] ?? null);

        $read = $this->serializer()->deserialize($definition, $item);

        static::assertInstanceOf(ContactEntity::class, $read);
        static::assertEquals($contact->address, $read->address);
    }

    private function serializer(): Serializer
    {
        $serializer = $this->container->get('test.' . Serializer::class);
        static::assertInstanceOf(Serializer::class, $serializer);

        return $serializer;
    }

    /**
     * @return EntityDefinition<AbstractEntity>
     */
    private function definition(string $name): EntityDefinition
    {
        $definition = $this->container->get('dal.definition.' . $name);
        static::assertInstanceOf(EntityDefinition::class, $definition);

        return $definition;
    }
}
