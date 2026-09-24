<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Shipping;

use JpmMartin\SyliusShippingCarriersPlugin\Form\Type\Shipping\FedexRateConfigurationType;
use JpmMartin\SyliusShippingCarriersPlugin\Form\Type\Shipping\UpsRateConfigurationType;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\Calculator\CarrierRateCalculator;
use JpmMartin\SyliusShippingCarriersPlugin\Shipping\CarrierServices;
use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Bundle\ResourceBundle\Form\Registry\FormTypeRegistryInterface;
use Sylius\Component\Registry\ServiceRegistryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
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
     * Registered like Sylius's own calculators, so the admin offers them in a shipping method's calculator list.
     *
     * @param class-string $formType
     */
    #[DataProvider('carriers')]
    public function testTheCalculatorOfEachCarrierIsRegisteredWithItsConfigurationForm(string $calculator, string $carrier, string $formType): void
    {
        self::bootKernel();

        $calculators = self::getContainer()->get('sylius.registry.shipping_calculator');
        self::assertInstanceOf(ServiceRegistryInterface::class, $calculators);
        $registered = $calculators->get($calculator);
        self::assertInstanceOf(CarrierRateCalculator::class, $registered);
        self::assertSame($calculator, $registered->getType());
        self::assertSame($carrier, $registered->getCarrier());

        $forms = self::getContainer()->get('sylius.form_registry.shipping_calculator');
        self::assertInstanceOf(FormTypeRegistryInterface::class, $forms);
        self::assertSame($formType, $forms->get($calculator, 'default'));

        $labels = self::getContainer()->getParameter('sylius.shipping_calculators');
        self::assertIsArray($labels);
        self::assertSame(sprintf('jpmmartin_carrier.form.shipping_calculator.%s', $calculator), $labels[$calculator] ?? null);
    }

    /**
     * @return iterable<string, array{string, string, class-string}>
     */
    public static function carriers(): iterable
    {
        yield 'UPS' => ['ups_rate', 'ups', UpsRateConfigurationType::class];
        yield 'FedEx' => ['fedex_rate', 'fedex', FedexRateConfigurationType::class];
    }

    /**
     * @param class-string $formType
     * @param array<string, string> $services
     */
    #[DataProvider('servicesThePluginShipsWith')]
    public function testTheFormOffersTheServicesThePluginShipsWith(string $formType, array $services): void
    {
        self::bootKernel();

        self::assertSame($services, $this->serviceChoices($formType));
    }

    /**
     * @return iterable<string, array{class-string, array<string, string>}>
     */
    public static function servicesThePluginShipsWith(): iterable
    {
        yield 'UPS' => [UpsRateConfigurationType::class, ['01' => 'UPS Next Day Air', '03' => 'UPS Ground']];
        yield 'FedEx' => [FedexRateConfigurationType::class, [
            'FEDEX_GROUND' => 'FedEx Ground',
            'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
            'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
        ]];
    }

    /**
     * The form of a new shipping method shows the default policy already chosen.
     */
    public function testANewShippingMethodShowsItHidesWhenTheCarrierFails(): void
    {
        self::bootKernel();

        self::assertSame('hide', $this->newForm(UpsRateConfigurationType::class)['failure_policy']->vars['value']);
    }

    /**
     * An application adds services and renames them from its own configuration, without touching the plugin. That
     * environment has no database of its own, and the list also reads the services the channels add on their
     * shipping origins, so it is given no origins to read: what is under test is what the configuration adds.
     */
    public function testAnApplicationAddsAndRenamesServices(): void
    {
        self::bootKernel(['environment' => 'carrier_services_extended']);

        $noOrigins = $this->createStub(RepositoryInterface::class);
        $noOrigins->method('findAll')->willReturn([]);
        self::getContainer()->set('jpmmartin_carrier.repository.shipping_origin', $noOrigins);

        $services = self::getContainer()->get('jpmmartin_carrier.shipping.carrier_services');
        self::assertInstanceOf(CarrierServices::class, $services);

        self::assertSame(['01', '03', '12'], $services->codes('ups'));
        self::assertSame('UPS Next Day Air', $services->name('ups', '01'));
        self::assertSame('Ground, renamed by the application', $services->name('ups', '03'));
        self::assertSame('A service added by the application', $services->name('ups', '12'));
    }

    /**
     * @param class-string $formType
     *
     * @return array<string, string> Choice labels by service code
     */
    private function serviceChoices(string $formType): array
    {
        $choices = [];
        foreach ($this->newForm($formType)['service']->vars['choices'] as $choice) {
            self::assertInstanceOf(ChoiceView::class, $choice);
            self::assertIsString($choice->label);
            $choices[$choice->value] = $choice->label;
        }

        return $choices;
    }

    /**
     * @param class-string $formType
     */
    private function newForm(string $formType): FormView
    {
        $formFactory = self::getContainer()->get('form.factory');
        self::assertInstanceOf(FormFactoryInterface::class, $formFactory);

        return $formFactory->create($formType, null, ['csrf_protection' => false])->createView();
    }
}
