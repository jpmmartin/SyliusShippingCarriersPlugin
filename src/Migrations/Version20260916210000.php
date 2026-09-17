<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the destination type the buyer chose for each order, with the DBAL Schema API so it runs on
 * any platform Doctrine supports (see Version20260812140643).
 */
final class Version20260916210000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_order_destination';

    public function getDescription(): string
    {
        return 'Create the destination type the buyer chose for each order.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE);
        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('order_id', 'integer', ['notnull' => true]);
        $table->addColumn('type', 'string', ['length' => 16, 'notnull' => true]);
        $table->setPrimaryKey(['id']);
        // One choice per order.
        $table->addUniqueIndex(['order_id'], 'uniq_jpmmartin_carrier_destination_order');
        $table->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE'], 'fk_jpmmartin_carrier_destination_order');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::TABLE);
    }
}
