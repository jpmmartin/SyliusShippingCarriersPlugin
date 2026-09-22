<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds to the shipment export the customs document the carrier printed with the labels, with the DBAL Schema API
 * so it runs on any platform Doctrine supports (see Version20260812140643).
 *
 * Nullable: a shipment that never leaves its country has no customs document, and neither does one exported
 * before this column existed.
 */
final class Version20260922090000 extends AbstractMigration
{
    private const TABLE = 'jpmmartin_carrier_shipment_export';

    public function getDescription(): string
    {
        return 'Add the customs document to the shipment export.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $table->addColumn('customs_document_path', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('customs_document_format', 'string', ['length' => 16, 'notnull' => false]);
        $table->addColumn('customs_document_purged_at', 'datetime_immutable', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $table = $schema->getTable(self::TABLE);
        $table->dropColumn('customs_document_path');
        $table->dropColumn('customs_document_format');
        $table->dropColumn('customs_document_purged_at');
    }
}
