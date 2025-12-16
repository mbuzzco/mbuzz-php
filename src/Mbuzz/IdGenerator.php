<?php

declare(strict_types=1);

namespace Mbuzz;

final class IdGenerator
{
    /**
     * Generate 64-character hex string (256 bits of entropy)
     */
    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }
}
