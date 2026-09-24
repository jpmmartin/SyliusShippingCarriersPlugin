<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\LabelFormats;
use JpmMartin\SyliusShippingCarriersPlugin\Entity\CarrierChannelSettingsInterface;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettings;
use JpmMartin\SyliusShippingCarriersPlugin\Settings\CarrierSettingsProvider;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

/**
 * The same minimums as the configuration, and a retention of rates never below the lifetime the channel will have:
 * its own, or, left empty, the configuration's. Checked here, when the administrator saves, so that the channel
 * never runs on a value its rates, statuses or documents would then refuse.
 *
 * @internal
 */
final class ChannelCarrierSettingsValidator extends ConstraintValidator
{
    public function __construct(
        private readonly CarrierSettingsProvider $settings,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, ChannelCarrierSettings::class);

        if (!$value instanceof CarrierChannelSettingsInterface) {
            return;
        }

        $this->atLeast('carrierTimeout', $value->getCarrierTimeout(), CarrierSettings::MIN_CARRIER_TIMEOUT, $constraint);
        $this->atLeast('rateLifetime', $value->getRateLifetime(), CarrierSettings::MIN_SECONDS, $constraint);
        $this->atLeast('rateRetention', $value->getRateRetention(), CarrierSettings::MIN_SECONDS, $constraint);
        $this->atLeast('trackingLifetime', $value->getTrackingLifetime(), CarrierSettings::MIN_SECONDS, $constraint);
        $this->atLeast('documentsRetention', $value->getDocumentsRetention(), CarrierSettings::MIN_SECONDS, $constraint);

        $this->retentionNotBelowLifetime($value, $constraint);

        foreach (LabelFormats::SUPPORTED as $carrier => $supported) {
            $format = $value->getLabelFormat($carrier);
            if (null !== $format && !in_array($format, $supported, true)) {
                $this->context->buildViolation($constraint->unsupportedLabelFormatMessage)
                    ->atPath($carrier . 'LabelFormat')
                    ->setParameter('{{ format }}', $format)
                    ->setParameter('{{ formats }}', implode(', ', $supported))
                    ->addViolation()
                ;
            }
        }
    }

    private function atLeast(string $path, int|float|null $value, int|float $minimum, ChannelCarrierSettings $constraint): void
    {
        if (null === $value || $value >= $minimum) {
            return;
        }

        $this->context->buildViolation($constraint->tooShortMessage)
            ->atPath($path)
            ->setParameter('{{ minimum }}', (string) $minimum)
            ->addViolation()
        ;
    }

    /**
     * Only about what the administrator set: a configuration that is itself inconsistent is not theirs to fix here,
     * and is refused where it is used.
     */
    private function retentionNotBelowLifetime(CarrierChannelSettingsInterface $value, ChannelCarrierSettings $constraint): void
    {
        $ownLifetime = $value->getRateLifetime();
        $ownRetention = $value->getRateRetention();
        if (null === $ownLifetime && null === $ownRetention) {
            return;
        }

        $defaults = $this->settings->defaults();
        $lifetime = $ownLifetime ?? $defaults->rateLifetime;
        $retention = $ownRetention ?? $defaults->rateRetention;
        if ($retention >= $lifetime) {
            return;
        }

        // Said where the administrator can do something about it: the field they filled in.
        if (null !== $ownRetention) {
            $this->context->buildViolation($constraint->retentionBelowLifetimeMessage)
                ->atPath('rateRetention')
                ->setParameter('{{ lifetime }}', (string) $lifetime)
                ->addViolation()
            ;

            return;
        }

        $this->context->buildViolation($constraint->lifetimeAboveRetentionMessage)
            ->atPath('rateLifetime')
            ->setParameter('{{ retention }}', (string) $retention)
            ->addViolation()
        ;
    }
}
