<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Creates the carrier credentials table, with the DBAL Schema API so it runs on any platform
 * Doctrine supports (see Version20260812140643).
 */
final class Version20260916144157 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_credentials';

    public function getDescription(): string
    {
        return 'Create the carrier credentials table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable(self::TABLE);

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('carrier', 'string', ['length' => 16, 'notnull' => true]);
        // No default: the environment is always an explicit choice.
        $table->addColumn('environment', 'string', ['length' => 16, 'notnull' => true]);
        // Every value is encrypted by the plugin before it reaches this column.
        $table->addColumn('credentials', 'json', ['notnull' => true]);

        $table->setPrimaryKey(['id']);

        // One set of credentials per carrier.
        $table->addUniqueIndex(['carrier'], 'uniq_jpmmartin_carrier_credentials_carrier');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable(self::TABLE);
    }
}
