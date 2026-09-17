<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the stored packages of a shipment, with the DBAL Schema API so it runs on any
 * platform Doctrine supports (see Version20260812140643).
 */
final class Version20260916180000 extends AbstractMigration
{
    private const PACKAGING_TABLE = 'jpmmartin_carrier_shipment_packaging';

    private const PACKAGE_TABLE = 'jpmmartin_carrier_shipment_package';

    private const PACKAGE_UNIT_TABLE = 'jpmmartin_carrier_shipment_package_unit';

    public function getDescription(): string
    {
        return 'Create the stored packages of a shipment and the units each one carries.';
    }

    public function up(Schema $schema): void
    {
        $packaging = $schema->createTable(self::PACKAGING_TABLE);
        $packaging->addColumn('id', 'integer', ['autoincrement' => true]);
        $packaging->addColumn('shipment_id', 'integer', ['notnull' => true]);
        $packaging->addColumn('state', 'string', ['length' => 16, 'notnull' => true]);
        // Only a failed packaging has a reason.
        $packaging->addColumn('failure_reason', 'text', ['notnull' => false]);
        $packaging->addColumn('created_at', 'datetime_immutable', ['notnull' => true]);
        $packaging->setPrimaryKey(['id']);
        // One packaging per shipment.
        $packaging->addUniqueIndex(['shipment_id'], 'uniq_jpmmartin_carrier_packaging_shipment');
        $packaging->addForeignKeyConstraint('sylius_shipment', ['shipment_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_jpmmartin_carrier_packaging_shipment');

        // Every value is copied when the order is completed, never read from a box.
        $package = $schema->createTable(self::PACKAGE_TABLE);
        $package->addColumn('id', 'integer', ['autoincrement' => true]);
        $package->addColumn('packaging_id', 'integer', ['notnull' => true]);
        $package->addColumn('position', 'integer', ['notnull' => true]);
        // Empty for the fallback package, which uses no box.
        $package->addColumn('box_name', 'string', ['length' => 255, 'notnull' => false]);
        foreach (['length', 'width', 'height', 'weight'] as $column) {
            $package->addColumn($column, 'float', ['notnull' => true]);
        }
        $package->addColumn('dimension_unit', 'string', ['length' => 8, 'notnull' => true]);
        $package->addColumn('weight_unit', 'string', ['length' => 8, 'notnull' => true]);
        $package->setPrimaryKey(['id']);
        $package->addIndex(['packaging_id'], 'idx_jpmmartin_carrier_package_packaging');
        $package->addForeignKeyConstraint(self::PACKAGING_TABLE, ['packaging_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_jpmmartin_carrier_package_packaging');

        // A join table's index and foreign key names cannot be set from the mapping. These are the names
        // Doctrine derives from the table and column names, so the schema and the mapping stay in step.
        $packageUnit = $schema->createTable(self::PACKAGE_UNIT_TABLE);
        $packageUnit->addColumn('package_id', 'integer', ['notnull' => true]);
        $packageUnit->addColumn('unit_id', 'integer', ['notnull' => true]);
        $packageUnit->setPrimaryKey(['package_id', 'unit_id']);
        $packageUnit->addIndex(['package_id'], 'IDX_1FBCECFFF44CABFF');
        // A unit travels in a single package.
        $packageUnit->addUniqueIndex(['unit_id'], 'UNIQ_1FBCECFFF8BD700D');
        $packageUnit->addForeignKeyConstraint(self::PACKAGE_TABLE, ['package_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_1FBCECFFF44CABFF');
        $packageUnit->addForeignKeyConstraint('sylius_order_item_unit', ['unit_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_1FBCECFFF8BD700D');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::PACKAGE_UNIT_TABLE);
        $schema->dropTable(self::PACKAGE_TABLE);
        $schema->dropTable(self::PACKAGING_TABLE);
    }
}
