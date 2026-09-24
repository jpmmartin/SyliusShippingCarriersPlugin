<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds to the shipping origin what its channel says in place of the plugin's configuration, with the DBAL Schema
 * API so it runs on any platform Doctrine supports (see Version20260812140643).
 *
 * Every column is nullable and none is filled: an origin that existed before says nothing of its own, which is what
 * leaves its channel on the configuration, as it was.
 */
final class Version20260924120000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_shipping_origin';

    public function getDescription(): string
    {
        return 'Add the settings a channel gives in place of the configuration to the shipping origin.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $table->addColumn('carrier_timeout', 'float', ['notnull' => false]);
        $table->addColumn('rate_lifetime', 'integer', ['notnull' => false]);
        $table->addColumn('rate_retention', 'integer', ['notnull' => false]);
        $table->addColumn('tracking_lifetime', 'integer', ['notnull' => false]);
        $table->addColumn('documents_retention', 'integer', ['notnull' => false]);
        $table->addColumn('ups_label_format', 'string', ['length' => 8, 'notnull' => false]);
        $table->addColumn('fedex_label_format', 'string', ['length' => 8, 'notnull' => false]);
        $table->addColumn('services', 'json', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $table->dropColumn('carrier_timeout');
        $table->dropColumn('rate_lifetime');
        $table->dropColumn('rate_retention');
        $table->dropColumn('tracking_lifetime');
        $table->dropColumn('documents_retention');
        $table->dropColumn('ups_label_format');
        $table->dropColumn('fedex_label_format');
        $table->dropColumn('services');
    }
}
