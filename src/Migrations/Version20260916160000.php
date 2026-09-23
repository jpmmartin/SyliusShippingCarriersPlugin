<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the package box catalog and the optional restriction of an origin to part of it, with the
 * DBAL Schema API so it runs on any platform Doctrine supports (see Version20260812140643).
 */
final class Version20260916160000 extends AbstractMigration
{
    private const BOX_TABLE = 'jpmmartin_carrier_package_box';

    private const ORIGIN_BOX_TABLE = 'jpmmartin_carrier_shipping_origin_box';

    public function getDescription(): string
    {
        return 'Create the package box catalog and the origin to box restriction.';
    }

    public function up(Schema $schema): void
    {
        $box = $schema->createTable(self::BOX_TABLE);
        $box->addColumn('id', 'integer', ['autoincrement' => true]);
        $box->addColumn('name', 'string', ['length' => 255, 'notnull' => true]);
        foreach (['inner_length', 'inner_width', 'inner_height', 'outer_length', 'outer_width', 'outer_height', 'empty_weight', 'max_weight'] as $column) {
            $box->addColumn($column, 'float', ['notnull' => true]);
        }
        $box->setPrimaryKey(['id']);

        // A join table's index and foreign key names cannot be set from the mapping. These are the names
        // Doctrine derives from the table and column names, so the schema and the mapping stay in step.
        $originBox = $schema->createTable(self::ORIGIN_BOX_TABLE);
        $originBox->addColumn('origin_id', 'integer', ['notnull' => true]);
        $originBox->addColumn('box_id', 'integer', ['notnull' => true]);
        $originBox->setPrimaryKey(['origin_id', 'box_id']);
        $originBox->addIndex(['origin_id'], 'IDX_E6415F4356A273CC');
        $originBox->addIndex(['box_id'], 'IDX_E6415F43D8177B3F');
        $originBox->addForeignKeyConstraint('jpmmartin_carrier_shipping_origin', ['origin_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_E6415F4356A273CC');
        $originBox->addForeignKeyConstraint(self::BOX_TABLE, ['box_id'], ['id'], ['onDelete' => 'CASCADE'], 'FK_E6415F43D8177B3F');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::ORIGIN_BOX_TABLE);
        $schema->dropTable(self::BOX_TABLE);
    }
}
