<?php

declare(strict_types=1);

namespace Moztopia\GeoLookup\Exceptions;

use RuntimeException;

/**
 * Describe a lookup failure without leaking internal dataset paths or SQL.
 */

final class LookupException extends RuntimeException
{
    /**
     * Construct a stable error usable by PHP and HTTP consumers.
     *
     * @param array<string, list<string>> $fields Field validation errors.
     */

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
