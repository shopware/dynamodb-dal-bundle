<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle;

use Shopware\DynamodbDalBundle\Serializer\Field\AbstractFieldSerializer;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;

/**
 * Tags every registered {@see AbstractEntity} and {@see AbstractFieldSerializer} so
 * {@see DefinitionCompilerPass} finds them.
 *
 * It walks the container's definitions rather than relying on `registerForAutoconfiguration()`,
 * because autoconfiguration only ever reaches services an application registered with
 * `autoconfigure: true`. An entity declared as a plain service — one explicit definition, a service
 * file that does not turn autoconfiguration on, a `ChildDefinition` — would otherwise be silently
 * absent from the DAL, with the first symptom a missing `dal.definition.<name>` service at runtime.
 *
 * Tagging a service twice would have {@see DefinitionCompilerPass} compile it twice, so a definition
 * that already carries the tag (from an application's own service file, or from autoconfiguration
 * where it is enabled) is left alone.
 */
class ServiceTaggingPass implements CompilerPassInterface
{
    /**
     * The tag names match the class names, which is what {@see DefinitionCompilerPass} looks for.
     *
     * @var list<class-string>
     */
    private const array TAGGED_BASE_CLASSES = [
        AbstractEntity::class,
        AbstractFieldSerializer::class,
    ];

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $definition) {
            // An abstract definition is a template, never a service of its own.
            if ($definition->isAbstract()) {
                continue;
            }

            $class = $this->resolveClass($container, $definition);
            if ($class === null) {
                continue;
            }

            foreach (self::TAGGED_BASE_CLASSES as $baseClass) {
                if (!$definition->hasTag($baseClass) && is_subclass_of($class, $baseClass, true)) {
                    $definition->addTag($baseClass);
                }
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
