<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\DependencyInjection;

use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The `settings_from_environment` environment is configured in tests/TestApplication/config/config.yaml: every
 * numeric setting of the plugin is an environment variable there. A container compiles with them, and what each
 * service is built with is the value the variable has when it runs, not anything decided when compiling.
 */
final class SettingsFromEnvironmentTest extends KernelTestCase
{
    private const VARIABLES = [
        'CARRIER_TIMEOUT' => '2.5',
        'CARRIER_RATE_LIFETIME' => '600',
        'CARRIER_RATE_RETENTION' => '7200',
        'CARRIER_TRACKING_LIFETIME' => '120',
        'CARRIER_DOCUMENTS_RETENTION_DAYS' => '30',
        'CARRIER_TEMPORARY_DOCUMENTS_RETENTION' => '3600',
    ];

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name => $value) {
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach (array_keys(self::VARIABLES) as $name) {
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }

    public function testThePurgeKeepsDocumentsForWhatTheVariablesSay(): void
    {
        $settings = $this->settingsOf($this->service('jpmmartin_carrier.label.document_purger'));

        // Thirty days, through the store's own processor.
        self::assertSame(30 * 24 * 60 * 60, $settings->documentsRetention);
        self::assertSame(3600, $settings->temporaryDocumentsRetention);
    }

    public function testRatesAreQuotedAndKeptForWhatTheVariablesSay(): void
    {
        $settings = $this->settingsOf($this->service('jpmmartin_carrier.rate_provider'));

        self::assertSame(600, $settings->rateLifetime);
        self::assertSame(7200, $settings->rateRetention);
    }

    public function testTheStatusOfAShipmentIsKeptForWhatTheVariableSays(): void
    {
        self::assertSame(120, $this->settingsOf($this->service('jpmmartin_carrier.tracking_provider'))->trackingLifetime);
    }

    public function testACarrierIsWaitedForAsLongAsTheVariableSays(): void
    {
        self::assertSame(2.5, $this->property($this->service('jpmmartin_carrier.carrier.fedex.connector_factory'), 'timeout'));
    }

    /**
     * The same compiled container, another value: nothing of the variable was fixed when compiling.
     */
    public function testAnotherValueOfTheVariableNeedsNoNewContainer(): void
    {
        $_ENV['CARRIER_RATE_LIFETIME'] = $_SERVER['CARRIER_RATE_LIFETIME'] = '1200';

        self::assertSame(1200, $this->settingsOf($this->service('jpmmartin_carrier.rate_provider'))->rateLifetime);
    }

    private function service(string $id): object
    {
        self::bootKernel(['environment' => 'settings_from_environment']);

        $service = self::getContainer()->get($id);
        self::assertIsObject($service);

        return $service;
    }

    /**
     * The settings the service works with, read from the service itself, because that is what they are for.
     */
    private function settingsOf(object $service): CarrierSettings
    {
        $provider = $this->property($service, 'settings');
        self::assertInstanceOf(CarrierSettingsProvider::class, $provider);

        return $provider->defaults();
    }

    private function property(object $service, string $name): mixed
    {
        return (new \ReflectionProperty($service, $name))->getValue($service);
    }
}
