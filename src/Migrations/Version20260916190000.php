<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds how packages reach each carrier to its credentials, with the DBAL Schema API so it runs on any
 * platform Doctrine supports (see Version20260812140643).
 */
final class Version20260916190000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_credentials';

    public function getDescription(): string
    {
        return 'Add how packages reach each carrier to its credentials.';
    }

    public function up(Schema $schema): void
    {
        // No default: it changes the rates, so the administrator always chooses it.
        $schema->getTable(self::TABLE)->addColumn('pickup_type', 'string', ['length' => 16, 'notnull' => true]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(self::TABLE)->dropColumn('pickup_type');
    }
}
