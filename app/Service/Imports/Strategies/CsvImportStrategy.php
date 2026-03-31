<?php

namespace App\Service\Imports\Strategies;

use App\Contracts\Imports\ImporterInterface;
use InvalidArgumentException;
use SplFileObject;

class CsvImportStrategy implements ImporterInterface
{
    public function parse(string $filePath): iterable
    {
        $file = new SplFileObject($filePath);
        $file->setFlags(
            SplFileObject::READ_CSV |
            SplFileObject::SKIP_EMPTY |
            SplFileObject::DROP_NEW_LINE
        );

        $header = $this->getHeader($file);
        if (empty($header) || !is_array($header)) {
            throw new InvalidArgumentException("Invalid or empty CSV file: {$filePath}");
        }

        $file->seek(1); // to skip the header line

        while(!$file->eof()){
            $row = $file->current();
            if (is_array($row) && count($header) === count($row)) {
                yield array_combine($header, $row);
            }
            $file->next();
        }
    }
    private function getHeader(SplFileObject $file): array
    {
        $file->rewind();
        return $file->current()?: [];
    }

}
