<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Label;

use JpmMartin\SyliusShippingCarriersPlugin\Carrier\Label\IssuedLabel;
use League\Flysystem\FilesystemException;
use League\Flysystem\FilesystemOperator;

/**
 * Keeps the label files in the plugin's private storage, which lives outside the published directory.
 *
 * The path of a label is its shipment, the carrier's number for the parcel and its place in the shipment. The
 * tracking number is in it so that issuing a shipment again after cancelling the first one writes somewhere
 * else: the file of a cancelled label is still evidence until the retention takes it.
 */
final readonly class LabelStorage
{
    public function __construct(
        private FilesystemOperator $storage,
    ) {
    }

    /**
     * @param int|string $shipmentId The shipment the label belongs to
     *
     * @return string The path the file was written to
     *
     * @throws FilesystemException When it could not be written
     */
    public function write(IssuedLabel $label, int|string $shipmentId): string
    {
        $path = sprintf(
            'labels/%s/%s-%d.%s',
            self::slug((string) $shipmentId),
            self::slug($label->trackingNumber),
            $label->position,
            self::extension($label->format),
        );

        $this->storage->write($path, $label->contents);

        return $path;
    }

    /**
     * Deleting what is not there is not a failure: what mattered was that it is gone.
     *
     * @throws FilesystemException When it could not be deleted
     */
    public function delete(string $path): void
    {
        $this->storage->delete($path);
    }

    /**
     * What the carrier prints is not always a format with an obvious extension — UPS's SPL and EPL are its own
     * — so the format is used as it comes, lowercased, and anything that is not a letter or a digit is dropped
     * rather than trusted in a path.
     */
    private static function extension(string $format): string
    {
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $format) ?? '');

        return '' === $extension ? 'bin' : $extension;
    }

    private static function slug(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]/', '', $value) ?? '';

        return '' === $slug ? 'unknown' : $slug;
    }
}
