<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds to the shipment export the reference the plugin gives the carrier, with the DBAL Schema API so it runs
 * on any platform Doctrine supports (see Version20260812140643).
 *
 * Nullable on purpose: an export written before this column existed has no such reference, and a shipment
 * whose carrier never answered still has to be readable.
 */
final class Version20260921120000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_shipment_export';

    public function getDescription(): string
    {
        return 'Add the plugin reference to the shipment export.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(self::TABLE)->addColumn('own_reference', 'string', ['length' => 64, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(self::TABLE)->dropColumn('own_reference');
    }
}
