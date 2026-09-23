<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\Migrations;

use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The mapping is the truth; the migrations are held to it here.
 *
 * For each of the plugin's tables — the ones its entities map to and the join tables between them — the table the
 * ORM expects is compared with the table that exists, and any statement the platform would need to bring the
 * second in line with the first is a failure: a column, a default, an index, a foreign-key rule. The comparison is
 * the one `doctrine:schema:validate` performs, narrowed to this plugin's tables so that Sylius's own schema is not
 * this test's verdict.
 *
 * It means something only on a database the plugin's migrations built. Continuous integration builds the test
 * database with `doctrine:migrations:migrate` before PHPUnit runs, on PostgreSQL and on MySQL, and `composer
 * database-reset` does the same locally. On a database made by `doctrine:schema:create` this passes without proving
 * anything about the migrations.
 */
final class MigrationMatchesMappingTest extends KernelTestCase
{
    private const TABLE_PREFIX = 'jpmmartin_carrier_';

    public function testEveryTableTheMigrationsBuiltIsTheOneTheMappingDescribes(): void
    {
        self::bootKernel();
        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');

        $metadata = array_values(array_filter(
            $manager->getMetadataFactory()->getAllMetadata(),
            static fn (ClassMetadata $class): bool => str_starts_with($class->getName(), 'JpmMartin\\SyliusShippingCarriersPlugin\\'),
        ));
        $expected = array_filter(
            (new SchemaTool($manager))->getSchemaFromMetadata($metadata)->getTables(),
            static fn (Table $table): bool => str_starts_with($table->getName(), self::TABLE_PREFIX),
        );
        self::assertCount(11, $expected, 'The plugin maps eleven tables: nine for its entities and two join tables.');

        $connection = $manager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        $differences = [];
        foreach ($expected as $table) {
            if (!$schemaManager->tablesExist([$table->getName()])) {
                $differences[$table->getName()] = ['the table does not exist: was the database built by the migrations?'];

                continue;
            }

            $difference = $schemaManager->createComparator()->compareTables($schemaManager->introspectTable($table->getName()), $table);
            $statements = $connection->getDatabasePlatform()->getAlterTableSQL($difference);
            if ([] !== $statements) {
                $differences[$table->getName()] = $statements;
            }
        }

        self::assertSame([], $differences, 'What it would take for each table the migrations built to match the mapping.');
    }
}
