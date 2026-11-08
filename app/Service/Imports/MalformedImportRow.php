<?php

namespace App\Service\Imports;

/**
 * A row an import strategy could read but not map to columns (e.g. a CSV line
 * with the wrong number of fields). It is recorded as a failed row instead of
 * being dropped.
 */
final class MalformedImportRow
{
    public function __construct(
        public readonly array $values,
        public readonly string $reason,
    ) {}
}
