<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\Compiler;

use Sylius\Component\Shipping\Checker\Eligibility\CompositeShippingMethodEligibilityChecker;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * When an order is completed, Sylius rejects a shipping method that is not eligible with a message about the
 * products of the order. A carrier's shipping method is rejected by the plugin instead, with a message saying it is
 * not available right now, so Sylius's two completion validators are given every eligibility checker but the
 * plugin's. Everything else in Sylius keeps asking all of them.
 */
final class ShippingMethodEligibilityOnCompletionPass implements CompilerPassInterface
{
    public const CHECKER = 'jpmmartin_carrier.shipping.eligibility_checker.on_completion';

    private const PLUGIN_CHECKER = 'jpmmartin_carrier.shipping.eligibility_checker';

    private const SYLIUS_CHECKER = 'sylius.checker.shipping_method_eligibility';

    private const CHECKER_TAG = 'sylius.shipping_method_eligibility_checker';

    private const COMPLETION_VALIDATORS = [
        'sylius.validator.order_shipping_method_eligibility',
        'sylius_api.validator.order_shipping_method_eligibility',
    ];

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::PLUGIN_CHECKER)) {
            return;
        }

        $checkers = [];
        foreach (array_keys($container->findTaggedServiceIds(self::CHECKER_TAG)) as $id) {
            if (self::PLUGIN_CHECKER !== $id) {
                $checkers[] = new Reference($id);
            }
        }

        $container->setDefinition(self::CHECKER, new Definition(CompositeShippingMethodEligibilityChecker::class, [$checkers]));

        foreach (self::COMPLETION_VALIDATORS as $validatorId) {
            if (!$container->hasDefinition($validatorId)) {
                continue;
            }

            $validator = $container->getDefinition($validatorId);
            foreach ($validator->getArguments() as $index => $argument) {
                if ($argument instanceof Reference && self::SYLIUS_CHECKER === (string) $argument) {
                    $validator->replaceArgument($index, new Reference(self::CHECKER));
                }
            }
        }
    }
}
