<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin;

use JpmMartin\SyliusShippingCarriersPlugin\DependencyInjection\Compiler\ShippingMethodEligibilityOnCompletionPass;
use Sylius\Bundle\CoreBundle\Application\SyliusPluginTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Bundle\Bundle;

final class JpmMartinSyliusShippingCarriersPlugin extends Bundle
{
    use SyliusPluginTrait;

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new ShippingMethodEligibilityOnCompletionPass());
    }

    public function getPath(): string
    {
        return \dirname(__DIR__);
    }
}
