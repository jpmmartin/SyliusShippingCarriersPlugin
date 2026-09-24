<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\EnvVarProcessor;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * A processor of the kind a store writes for itself: `%env(days:NAME)%` reads a number of days and hands over the
 * seconds. The plugin knows nothing about it; it only has to accept whatever type a store's processor declares.
 */
final class DaysEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): int
    {
        $days = $getEnv($name);
        if (!is_numeric($days)) {
            throw new RuntimeException(sprintf('The environment variable "%s" is not a number of days.', $name));
        }

        return (int) $days * 24 * 60 * 60;
    }

    public static function getProvidedTypes(): array
    {
        return ['days' => 'int'];
    }
}
