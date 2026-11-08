<?php

namespace App\Service\Imports\Strategies;

use App\Contracts\Imports\ImporterInterface;
use App\Service\Imports\MalformedImportRow;
use InvalidArgumentException;
use RuntimeException;
use SplFileObject;

class CsvImportStrategy implements ImporterInterface
{
    public function parse(string $filePath): iterable
    {
        try {
            $file = new SplFileObject($filePath);
        } catch (RuntimeException) {
            throw new InvalidArgumentException('Could not open the file.');
        }

        $file->setFlags(
            SplFileObject::READ_CSV |
            SplFileObject::SKIP_EMPTY |
            SplFileObject::DROP_NEW_LINE
        );
        $header = $this->getHeader($file);
        if (empty($header) || $header === [null]) {
            throw new InvalidArgumentException('Empty file: it has no header row.');
        }

        $file->seek(1); // to skip the header line

        // key() counts CSV records, not lines; add the line breaks found
        // inside quoted fields of earlier records to get the real file line.
        $embeddedLineBreaks = 0;

        while (! $file->eof()) {
            $row = $file->current();
            $line = $file->key() + 1 + $embeddedLineBreaks;

            if (is_array($row) && $row !== [null]) {
                yield $line => count($header) === count($row)
                    ? array_combine($header, $row)
                    : new MalformedImportRow($row, 'Expected '.count($header).' columns, found '.count($row).'.');

                $embeddedLineBreaks += substr_count(implode('', $row), "\n");
            }
            $file->next();
        }
    }

    private function getHeader(SplFileObject $file): array
    {
        $file->rewind();

        return $file->current() ?: [];
    }
}

// /Users/abdallahelsaed/Herd/delivery_app_task/storage/app/imports/products_test_v2.csv

// /Users/abdallahelsaed/Herd/delivery_app_task/storage/app/private/imports/products_test_v2.csv
