<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Encryption;

/**
 * Marks an entity whose data EntityEncryptionListener encrypts in the database.
 *
 * The plugin's own marker rather than Sylius' Payment one, which is `@experimental`.
 *
 * @internal
 */
interface EncryptionAwareInterface
{
}
