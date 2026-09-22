<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds to the carrier credentials who pays the duties and taxes of an international shipment, with the DBAL
 * Schema API so it runs on any platform Doctrine supports (see Version20260812140643).
 *
 * Not null with a default: credentials saved before the choice existed get the recipient, which is what an order
 * whose checkout charged no duties implies, and keep issuing labels without anyone opening them.
 */
final class Version20260921180000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_credentials';

    public function getDescription(): string
    {
        return 'Add who pays the duties and taxes to the carrier credentials.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable(self::TABLE)->addColumn('duties_payer', 'string', [
            'length' => 16,
            'notnull' => true,
            'default' => 'recipient',
        ]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable(self::TABLE)->dropColumn('duties_payer');
    }
}
