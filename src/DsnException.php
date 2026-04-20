<?php

declare(strict_types=1);

namespace Alby\Report;

/**
 * Thrown when a DSN string cannot be parsed.
 */
final class DsnException extends \InvalidArgumentException
{
    public function __construct(string $reason)
    {
        parent::__construct('[alby] invalid DSN: ' . $reason);
    }
}
