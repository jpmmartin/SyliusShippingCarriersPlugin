<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Form\Type\Shipping\UpsRateConfigurationType;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use Sylius\Bundle\ResourceBundle\Form\Registry\FormTypeRegistryInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\ChoiceList\View\ChoiceView;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;

/**
 * The `carrier_services_extended` environment is configured in tests/TestApplication/config/config.yaml.
 */
final class CarrierRateCalculatorRegistrationTest extends KernelTestCase
{
    /**
     * Registered like Sylius's own calculators, so the admin offers it in a shipping method's calculator list.
     */
    public function testTheUpsCalculatorIsRegisteredWithItsConfigurationForm(): void
    {
        self::bootKernel();

        $calculators = self::getContainer()->get('sylius.registry.shipping_calculator');
        self::assertInstanceOf(ServiceRegistryInterface::class, $calculators);
        self::assertInstanceOf(CarrierRateCalculator::class, $calculators->get('ups_rate'));

        $forms = self::getContainer()->get('sylius.form_registry.shipping_calculator');
        self::assertInstanceOf(FormTypeRegistryInterface::class, $forms);
        self::assertSame(UpsRateConfigurationType::class, $forms->get('ups_rate', 'default'));

        $labels = self::getContainer()->getParameter('sylius.shipping_calculators');
        self::assertIsArray($labels);
        self::assertSame('jpmmartin_carrier.form.shipping_calculator.ups_rate', $labels['ups_rate'] ?? null);
    }

    public function testTheFormOffersTheServicesThePluginShipsWith(): void
    {
        self::bootKernel();

        self::assertSame(['01' => 'UPS Next Day Air', '03' => 'UPS Ground'], $this->serviceChoices());
    }

    /**
     * The form of a new shipping method shows the default policy already chosen.
     */
    public function testANewShippingMethodShowsItHidesWhenTheCarrierFails(): void
    {
        self::bootKernel();

        self::assertSame('hide', $this->newForm()['failure_policy']->vars['value']);
    }

    /**
     * An application adds services and renames them from its own configuration, without touching the plugin. That
     * environment has no database of its own, so the list is read where the form reads it.
     */
    public function testAnApplicationAddsAndRenamesServices(): void
    {
        self::bootKernel(['environment' => 'carrier_services_extended']);

        $services = self::getContainer()->get('jpmmartin_carrier.shipping.carrier_services');
        self::assertInstanceOf(CarrierServices::class, $services);

        self::assertSame(['01', '03', '12'], $services->codes('ups'));
        self::assertSame('UPS Next Day Air', $services->name('ups', '01'));
        self::assertSame('Ground, renamed by the application', $services->name('ups', '03'));
        self::assertSame('A service added by the application', $services->name('ups', '12'));
    }

    /**
     * @return array<string, string> Choice labels by service code
     */
    private function serviceChoices(): array
    {
        $choices = [];
        foreach ($this->newForm()['service']->vars['choices'] as $choice) {
            self::assertInstanceOf(ChoiceView::class, $choice);
            self::assertIsString($choice->label);
            $choices[$choice->value] = $choice->label;
        }

        return $choices;
    }

    private function newForm(): FormView
    {
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create(UpsRateConfigurationType::class, null, ['csrf_protection' => false])->createView();
    }
}
