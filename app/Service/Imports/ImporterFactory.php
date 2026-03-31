<?php

namespace App\Service\Imports;

use App\Contracts\Imports\ImporterInterface;
use App\Service\Imports\Strategies\CsvImportStrategy;
use App\Service\Imports\Strategies\JsonImportStrategy;
use InvalidArgumentException;

class ImporterFactory
{
    /**
     * Map file extension => strategy class.
     *
     * Add new formats here (e.g. 'xlsx' => XlsxImportStrategy::class).
     *
     * @var array<string, class-string<ImporterInterface>>
     */
    private const STRATEGY_MAP = [
        'csv'  => CsvImportStrategy::class,
        'json' => JsonImportStrategy::class,
    ];

    public function make(string $extension): ImporterInterface
    {
        // $extension = strtolower((string) pathinfo($filePath, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new InvalidArgumentException('File format is not supported (missing extension).');
        }

        /** @var class-string<ImporterInterface>|null $strategyClass */
        $strategyClass = self::STRATEGY_MAP[$extension] ?? null;
        if ($strategyClass === null) {
            throw new InvalidArgumentException("File format '{$extension}' is not supported.");
        }

        if (!is_subclass_of($strategyClass, ImporterInterface::class)) {
            throw new InvalidArgumentException("Importer for '{$extension}' is not supported.");
        }

        return app()->make($strategyClass);
    }
}
