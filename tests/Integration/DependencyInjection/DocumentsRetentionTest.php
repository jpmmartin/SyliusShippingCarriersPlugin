<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusShippingCarriersPlugin\Integration\DependencyInjection;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class DocumentsRetentionTest extends KernelTestCase
{
    /**
     * An application that configures nothing keeps a label for 180 days: the longest a shipment can be
     * cancelled for, so nothing is ever deleted while it can still be sent back.
     */
    public function testAnApplicationWithoutConfigurationKeepsADocumentForOneHundredAndEightyDays(): void
    {
        self::bootKernel();

        self::assertSame(180 * 24 * 60 * 60, self::getContainer()->getParameter('jpmmartin_carrier.documents_retention'));
    }

    /**
     * A file still waiting to be named by a row is left alone for a day. Issuing takes seconds, so anything
     * older than that was abandoned by an issue that never finished.
     */
    public function testAnApplicationWithoutConfigurationLeavesAWaitingDocumentForADay(): void
    {
        self::bootKernel();

        self::assertSame(24 * 60 * 60, self::getContainer()->getParameter('jpmmartin_carrier.temporary_documents_retention'));
    }
}
