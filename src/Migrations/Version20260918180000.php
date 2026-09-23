<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates what happened when a shipment was handed to its carrier, and the label of each of its packages,
 * with the DBAL Schema API so it runs on any platform Doctrine supports (see Version20260812140643).
 */
final class Version20260918180000 extends AbstractMigration
{
    private const EXPORT_TABLE = 'jpmmartin_carrier_shipment_export';

    private const LABEL_TABLE = 'jpmmartin_carrier_shipment_label';

    public function getDescription(): string
    {
        return 'Create the export of a shipment to its carrier and the label of each of its packages.';
    }

    public function up(Schema $schema): void
    {
        $export = $schema->createTable(self::EXPORT_TABLE);
        $export->addColumn('id', 'integer', ['autoincrement' => true]);
        $export->addColumn('shipment_id', 'integer', ['notnull' => true]);
        $export->addColumn('state', 'string', ['length' => 16, 'notnull' => true]);
        $export->addColumn('carrier', 'string', ['length' => 16, 'notnull' => false]);
        $export->addColumn('environment', 'string', ['length' => 16, 'notnull' => false]);
        $export->addColumn('carrier_reference', 'string', ['notnull' => false]);
        $export->addColumn('issued_at', 'datetime_immutable', ['notnull' => false]);
        $export->addColumn('issued_by', 'string', ['notnull' => false]);
        $export->addColumn('voided_at', 'datetime_immutable', ['notnull' => false]);
        $export->addColumn('voided_by', 'string', ['notnull' => false]);
        $export->addColumn('failure_reason', 'text', ['notnull' => false]);
        $export->setPrimaryKey(['id']);
        // A shipment is exported once.
        $export->addUniqueIndex(['shipment_id'], 'uniq_jpmmartin_carrier_export_shipment');
        $export->addForeignKeyConstraint('sylius_shipment', ['shipment_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_jpmmartin_carrier_export_shipment');

        $label = $schema->createTable(self::LABEL_TABLE);
        $label->addColumn('id', 'integer', ['autoincrement' => true]);
        $label->addColumn('export_id', 'integer', ['notnull' => true]);
        $label->addColumn('position', 'integer', ['notnull' => true]);
        $label->addColumn('path', 'string', ['notnull' => false]);
        $label->addColumn('format', 'string', ['length' => 16, 'notnull' => false]);
        $label->addColumn('tracking_number', 'string', ['notnull' => false]);
        $label->addColumn('declared_value', 'integer', ['notnull' => false]);
        $label->addColumn('declared_value_currency', 'string', ['length' => 3, 'notnull' => false]);
        $label->addColumn('purged_at', 'datetime_immutable', ['notnull' => false]);
        $label->setPrimaryKey(['id']);
        // One label per package of the export, and the package is what its position says.
        $label->addUniqueIndex(['export_id', 'position'], 'uniq_jpmmartin_carrier_label_export_position');
        $label->addForeignKeyConstraint(self::EXPORT_TABLE, ['export_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_jpmmartin_carrier_label_export');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::LABEL_TABLE);
        $schema->dropTable(self::EXPORT_TABLE);
    }
}
