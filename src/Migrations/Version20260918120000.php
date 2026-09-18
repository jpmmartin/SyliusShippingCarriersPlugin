<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the customs data of a product variant, with the DBAL Schema API so it runs on any platform
 * Doctrine supports (see Version20260812140643).
 */
final class Version20260918120000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_customs_data';

    public function getDescription(): string
    {
        return 'Create the customs data of a product variant.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('variant_id', 'integer', ['notnull' => true]);
        $table->addColumn('hs_code', 'string', ['length' => 16, 'notnull' => false]);
        $table->addColumn('country_of_origin', 'string', ['length' => 2, 'notnull' => false]);
        $table->setPrimaryKey(['id']);
        // One set of customs data per variant.
        $table->addUniqueIndex(['variant_id'], 'uniq_jpmmartin_carrier_customs_variant');
        $table->addForeignKeyConstraint('sylius_product_variant', ['variant_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_jpmmartin_carrier_customs_variant');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::TABLE);
    }
}
