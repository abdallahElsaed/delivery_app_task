<?php

namespace App\Contracts\Imports;

use App\Service\Imports\MalformedImportRow;

interface ImporterInterface
{
    /**
     * Yield the file's data rows keyed by their position in the file (the line
     * number for line-based formats), as column => value arrays, or as a
     * MalformedImportRow when a row can't be mapped to columns.
     *
     * @return iterable<int, array<string, mixed>|MalformedImportRow>
     *
     * @throws \InvalidArgumentException when the file itself can't be read
     */
    public function parse(string $filePath): iterable;
}
