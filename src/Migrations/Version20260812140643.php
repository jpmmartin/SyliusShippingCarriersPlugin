<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the carrier shipping origin table.
 *
 * Written against the DBAL Schema API rather than addSql(): whoever installs
 * this plugin may run any platform Doctrine supports, and raw SQL would tie the
 * migration to one of them. Doctrine translates this to the connected platform
 * at run time, so a single migration covers all of them.
 */
final class Version20260812140643 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_shipping_origin';

    public function getDescription(): string
    {
        return 'Create the carrier shipping origin table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE);

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('channel_id', 'integer', ['notnull' => true]);
        $table->addColumn('street', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('city', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('postcode', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('country_code', 'string', ['length' => 2, 'notnull' => false]);
        $table->addColumn('province_code', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('weight_unit', 'string', ['length' => 8, 'notnull' => true]);
        $table->addColumn('dimension_unit', 'string', ['length' => 8, 'notnull' => true]);
        $table->addColumn('max_package_weight', 'float', ['notnull' => true]);

        $table->setPrimaryKey(['id']);

        // One origin per channel (CA-2). Enforced by the database, not by a PHP guard.
        $table->addUniqueIndex(['channel_id'], 'uniq_jpmmartin_carrier_origin_channel');

        $table->addForeignKeyConstraint(
            'sylius_channel',
            ['channel_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_jpmmartin_carrier_origin_channel',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::TABLE);
    }
}
