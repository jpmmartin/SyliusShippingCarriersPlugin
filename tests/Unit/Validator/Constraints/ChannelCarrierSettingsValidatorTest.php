<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Unit\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOrigin;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierShippingOriginInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\ChannelCarrierSettings;
use JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints\ChannelCarrierSettingsValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Tests\JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsFactory;

/**
 * @extends ConstraintValidatorTestCase<ChannelCarrierSettingsValidator>
 */
final class ChannelCarrierSettingsValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ChannelCarrierSettingsValidator
    {
        // The configuration quotes a rate for 900 seconds and keeps it for 86400.
        return new ChannelCarrierSettingsValidator(CarrierSettingsFactory::provider(rateLifetime: 900, rateRetention: 86400));
    }

    public function testAnOriginThatSaysNothingIsValid(): void
    {
        $this->validator->validate(new CarrierShippingOrigin(), new ChannelCarrierSettings());

        $this->assertNoViolation();
    }

    public function testTheMinimumsThemselvesAreValid(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setCarrierTimeout(0.1);
        $origin->setRateLifetime(1);
        $origin->setRateRetention(1);
        $origin->setTrackingLifetime(1);
        $origin->setDocumentsRetention(1);

        $this->validator->validate($origin, new ChannelCarrierSettings());

        $this->assertNoViolation();
    }

    /**
     * @param \Closure(CarrierShippingOrigin): void $set
     */
    #[DataProvider('belowTheMinimum')]
    public function testAValueBelowTheMinimumIsRefusedAtItsField(\Closure $set, string $path, string $minimum): void
    {
        $origin = new CarrierShippingOrigin();
        $set($origin);

        $this->validator->validate($origin, new ChannelCarrierSettings());

        $this->buildViolation('jpmmartin_carrier.shipping_origin.carrier_settings.too_short')
            ->atPath('property.path.' . $path)
            ->setParameter('{{ minimum }}', $minimum)
            ->assertRaised()
        ;
    }

    /**
     * @return iterable<string, array{\Closure(CarrierShippingOrigin): void, string, string}>
     */
    public static function belowTheMinimum(): iterable
    {
        yield 'timeout' => [static fn (CarrierShippingOrigin $origin) => $origin->setCarrierTimeout(0.05), 'carrierTimeout', '0.1'];
        yield 'rate lifetime' => [static fn (CarrierShippingOrigin $origin) => $origin->setRateLifetime(0), 'rateLifetime', '1'];
        yield 'tracking lifetime' => [static fn (CarrierShippingOrigin $origin) => $origin->setTrackingLifetime(0), 'trackingLifetime', '1'];
        yield 'documents retention' => [static fn (CarrierShippingOrigin $origin) => $origin->setDocumentsRetention(0), 'documentsRetention', '1'];
    }

    /**
     * The lifetime left empty is the configuration's 900 seconds, and that is what the retention is compared with.
     */
    public function testARetentionBelowTheLifetimeTheChannelWillHaveIsRefused(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setRateRetention(600);

        $this->validator->validate($origin, new ChannelCarrierSettings());

        $this->buildViolation('jpmmartin_carrier.shipping_origin.carrier_settings.rate_retention_below_lifetime')
            ->atPath('property.path.rateRetention')
            ->setParameter('{{ lifetime }}', '900')
            ->assertRaised()
        ;
    }

    public function testARetentionBelowItsOwnLifetimeIsRefused(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setRateLifetime(3600);
        $origin->setRateRetention(1800);

        $this->validator->validate($origin, new ChannelCarrierSettings());

        $this->buildViolation('jpmmartin_carrier.shipping_origin.carrier_settings.rate_retention_below_lifetime')
            ->atPath('property.path.rateRetention')
            ->setParameter('{{ lifetime }}', '3600')
            ->assertRaised()
        ;
    }

    /**
     * The retention left empty is the configuration's 86400 seconds: a lifetime above it is the field to correct.
     */
    public function testALifetimeAboveTheRetentionTheChannelWillHaveIsRefused(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setRateLifetime(90000);

        $this->validator->validate($origin, new ChannelCarrierSettings());

        $this->buildViolation('jpmmartin_carrier.shipping_origin.carrier_settings.rate_lifetime_above_retention')
            ->atPath('property.path.rateLifetime')
            ->setParameter('{{ retention }}', '86400')
            ->assertRaised()
        ;
    }

    /**
     * A configuration inconsistent by itself is not the administrator's to fix here: it is refused where it is used.
     */
    public function testAnInconsistentConfigurationIsNotBlamedOnAnOriginThatSaysNothingOfRates(): void
    {
        $validator = new ChannelCarrierSettingsValidator(CarrierSettingsFactory::provider(rateLifetime: 900, rateRetention: 600));
        $validator->initialize($this->context);
        $origin = new CarrierShippingOrigin();
        $origin->setTrackingLifetime(60);

        $validator->validate($origin, new ChannelCarrierSettings());

        $this->assertNoViolation();
    }

    public function testALabelFormatTheCarrierDoesNotPrintIsRefused(): void
    {
        $origin = new CarrierShippingOrigin();
        $origin->setLabelFormat('ups', 'PDF');

        $this->validator->validate($origin, new ChannelCarrierSettings());

        $this->buildViolation('jpmmartin_carrier.shipping_origin.carrier_settings.unsupported_label_format')
            ->atPath('property.path.upsLabelFormat')
            ->setParameter('{{ format }}', 'PDF')
            ->setParameter('{{ formats }}', 'GIF, ZPL, EPL, SPL')
            ->assertRaised()
        ;
    }

    public function testAnOriginModelWithoutChannelSettingsIsLeftAlone(): void
    {
        $this->validator->validate($this->createStub(CarrierShippingOriginInterface::class), new ChannelCarrierSettings());

        $this->assertNoViolation();
    }
}
