<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds who the parcel is from to the shipping origin, with the DBAL Schema API so it runs on any platform
 * Doctrine supports (see Version20260812140643).
 *
 * Nullable on purpose: an origin created before labels existed keeps working, and the form is what requires
 * the three from now on.
 */
final class Version20260918220000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_shipping_origin';

    public function getDescription(): string
    {
        return 'Add the sender contact to the shipping origin.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $table->addColumn('company_name', 'string', ['notnull' => false]);
        $table->addColumn('contact_name', 'string', ['notnull' => false]);
        $table->addColumn('phone', 'string', ['length' => 32, 'notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $table->dropColumn('company_name');
        $table->dropColumn('contact_name');
        $table->dropColumn('phone');
    }
}
