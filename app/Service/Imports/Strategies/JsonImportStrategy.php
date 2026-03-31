<?php

namespace App\Service\Imports\Strategies;

use App\Contracts\Imports\ImporterInterface;
use InvalidArgumentException;

class JsonImportStrategy implements ImporterInterface
{
    public function parse(string $filePath): iterable
    {
        if (!file_exists($filePath)) {
            throw new InvalidArgumentException("File not found: {$filePath}");
        }

        $content = file_get_contents($filePath);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new InvalidArgumentException("Invalid JSON file: {$filePath}");
        }

        foreach ($data as $row) {
            if (is_array($row)) {
                yield $row;
            }
        }
    }
}
