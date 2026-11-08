<?php

namespace App\Service\Imports\Strategies;

use App\Contracts\Imports\ImporterInterface;
use App\Service\Imports\MalformedImportRow;
use InvalidArgumentException;

class JsonImportStrategy implements ImporterInterface
{
    public function parse(string $filePath): iterable
    {
        if (! file_exists($filePath)) {
            throw new InvalidArgumentException('Could not open the file.');
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Invalid JSON file: it could not be parsed.');
        }
        if (! array_is_list($data)) {
            throw new InvalidArgumentException('Invalid JSON file: expected a list of rows.');
        }

        $position = 0;

        foreach ($data as $row) {
            $position++;

            yield $position => is_array($row)
                ? $row
                : new MalformedImportRow([$row], 'Expected an object of columns.');
        }
    }
}
