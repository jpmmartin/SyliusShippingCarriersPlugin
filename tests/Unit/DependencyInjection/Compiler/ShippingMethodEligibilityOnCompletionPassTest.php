<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\DependencyInjection\Compiler;

use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\Compiler\ShippingMethodEligibilityOnCompletionPass;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Shipping\Checker\Eligibility\CompositeShippingMethodEligibilityChecker;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class ShippingMethodEligibilityOnCompletionPassTest extends TestCase
{
    public function testSyliusCompletionValidatorsAskEveryCheckerButThePlugins(): void
    {
        $container = $this->container();

        (new ShippingMethodEligibilityOnCompletionPass())->process($container);

        $checker = $container->getDefinition(ShippingMethodEligibilityOnCompletionPass::CHECKER);
        self::assertSame(CompositeShippingMethodEligibilityChecker::class, $checker->getClass());
        self::assertEquals([[new Reference('sylius.checker.zone'), new Reference('app.checker.weekend')]], $checker->getArguments());

        self::assertEquals([new Reference(ShippingMethodEligibilityOnCompletionPass::CHECKER)], $container->getDefinition('sylius.validator.order_shipping_method_eligibility')->getArguments());
        self::assertEquals(
            [new Reference('sylius.repository.order'), new Reference(ShippingMethodEligibilityOnCompletionPass::CHECKER)],
            $container->getDefinition('sylius_api.validator.order_shipping_method_eligibility')->getArguments(),
        );
    }

    /**
     * Offering a shipping method and assigning one by default still ask every checker, the plugin's included.
     */
    public function testEverythingElseKeepsSyliusChecker(): void
    {
        $container = $this->container();

        (new ShippingMethodEligibilityOnCompletionPass())->process($container);

        self::assertEquals([new Reference('sylius.checker.shipping_method_eligibility')], $container->getDefinition('sylius.resolver.shipping_methods.default')->getArguments());
    }

    public function testWithoutTheApiNothingFails(): void
    {
        $container = $this->container();
        $container->removeDefinition('sylius_api.validator.order_shipping_method_eligibility');

        (new ShippingMethodEligibilityOnCompletionPass())->process($container);

        self::assertEquals([new Reference(ShippingMethodEligibilityOnCompletionPass::CHECKER)], $container->getDefinition('sylius.validator.order_shipping_method_eligibility')->getArguments());
    }

    private function container(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach (['sylius.checker.zone', 'jpmmartin_carrier.shipping.eligibility_checker', 'app.checker.weekend'] as $id) {
            $container->setDefinition($id, (new Definition(\stdClass::class))->addTag('sylius.shipping_method_eligibility_checker'));
        }

        $container->setDefinition('sylius.validator.order_shipping_method_eligibility', new Definition(\stdClass::class, [new Reference('sylius.checker.shipping_method_eligibility')]));
        $container->setDefinition('sylius_api.validator.order_shipping_method_eligibility', new Definition(\stdClass::class, [new Reference('sylius.repository.order'), new Reference('sylius.checker.shipping_method_eligibility')]));
        $container->setDefinition('sylius.resolver.shipping_methods.default', new Definition(\stdClass::class, [new Reference('sylius.checker.shipping_method_eligibility')]));

        return $container;
    }
}
