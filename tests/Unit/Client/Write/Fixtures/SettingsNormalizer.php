<?php declare(strict_types=1);

namespace Shopware\DynamodbDalBundle\Tests\Unit\Client\Write\Fixtures;

use Shopware\DynamodbDalBundle\Serializer\AbstractNormalizer;
use Shopware\DynamodbDalBundle\Serializer\NormalizerContext;

/**
 * Turns {@see Settings} into the array a map serializer stores, under keys of its own: the theme is stored as
 * `colorScheme`, so only the normalized field says where it is.
 */
final class SettingsNormalizer extends AbstractNormalizer
{
    public function normalize(NormalizerContext $context): void
    {
        $settings = $context->get('settings');
        if ($settings instanceof Settings) {
            $context->set('settings', ['colorScheme' => $settings->theme, 'locale' => $settings->locale]);
        }
    }

    public function denormalize(NormalizerContext $context): void
    {
        $settings = $context->get('settings');
        if (\is_array($settings) && \is_string($settings['colorScheme'] ?? null) && \is_string($settings['locale'] ?? null)) {
            $context->set('settings', new Settings($settings['colorScheme'], $settings['locale']));
        }
    }
}
