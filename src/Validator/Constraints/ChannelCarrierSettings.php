<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * What a channel says in place of the configuration holds to the limits the configuration holds to.
 *
 * @internal
 */
final class ChannelCarrierSettings extends Constraint
{
    public string $tooShortMessage = 'jpmmartin_carrier.shipping_origin.carrier_settings.too_short';

    public string $retentionBelowLifetimeMessage = 'jpmmartin_carrier.shipping_origin.carrier_settings.rate_retention_below_lifetime';

    public string $lifetimeAboveRetentionMessage = 'jpmmartin_carrier.shipping_origin.carrier_settings.rate_lifetime_above_retention';

    public string $unsupportedLabelFormatMessage = 'jpmmartin_carrier.shipping_origin.carrier_settings.unsupported_label_format';

    public function validatedBy(): string
    {
        return 'jpmmartin_carrier_channel_carrier_settings';
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
