<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

/**
 * What a purge did, for whoever asked for it to say so.
 *
 * @internal
 */
final readonly class PurgeReport
{
    public function __construct(
        /** Files that are no longer stored. */
        public int $deletedFiles = 0,
        /** Files the storage would not delete. They stay, and so does the record pointing at them. */
        public int $failedFiles = 0,
        /** Shipments at least one file of which is gone. */
        public int $shipments = 0,
        /** Files left waiting by an issue that never got as far as its row, now collected. */
        public int $temporaryFiles = 0,
    ) {
    }
}
