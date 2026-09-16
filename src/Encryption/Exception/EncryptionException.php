<?php

declare(strict_types=1);

namespace JpmMartin\SyliusShippingCarriersPlugin\Encryption\Exception;

final class EncryptionException extends \RuntimeException
{
    public static function cannotEncrypt(\Throwable $previous): self
    {
        return new self('Cannot encrypt data.', 0, $previous);
    }

    public static function cannotDecrypt(\Throwable $previous): self
    {
        return new self('Cannot decrypt data.', 0, $previous);
    }

    public static function invalidKey(\Throwable $previous): self
    {
        return new self('Invalid encryption key.', 0, $previous);
    }
}
