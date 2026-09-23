<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Tags every registered {@see AbstractFieldSerializer} so {@see DefinitionCompilerPass} finds it.
 *
 * @internal
 */
class ServiceTaggingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            // An abstract definition is a template, never a service of its own.
            if ($definition->isAbstract() || $definition->hasTag(AbstractFieldSerializer::class)) {
                continue;
            }

            $class = $this->resolveClass($container, $definition);
            if ($class === null) {
                continue;
            }

            // The tag name matches the class name, which is what DefinitionCompilerPass looks for.
            if (is_subclass_of($class, AbstractFieldSerializer::class, true)) {
                $definition->addTag(AbstractFieldSerializer::class);
            }
        }
    }

    /**
     * The definition's class, following a {@see ChildDefinition} up to whichever parent names one —
     * child definitions are only resolved in the optimization phase, after this pass.
     *
     * @return class-string|null
     */
    private function resolveClass(ContainerBuilder $container, Definition $definition): ?string
    {
        $seen = [];

        while ($definition->getClass() === null && $definition instanceof ChildDefinition) {
            $parent = $definition->getParent();
            if (isset($seen[$parent])) {
                return null;
            }

            $seen[$parent] = true;

            try {
                $definition = $container->findDefinition($parent);
            } catch (InvalidArgumentException) {
                return null;
            }
        }

        $class = $definition->getClass();
        if ($class === null) {
            return null;
        }

        // A class name may be a parameter, and may name something that does not exist at all — an
        // application is free to register a service whose class only ships in another environment.
        $class = $container->getParameterBag()->resolveValue($class);

        if (!\is_string($class) || $container->getReflectionClass($class, false) === null) {
            return null;
        }

        /** @var class-string $class */
        return $class;
    }
}
