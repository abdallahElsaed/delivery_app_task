<?php

namespace App\Service\Imports;

/**
 * The columns a product import row must have, and how incoming column names
 * are matched to them (any format, any order, extra columns ignored).
 */
final class ProductImportColumns
{
    public const REQUIRED = [
        'product_sku',
        'product_name',
        'product_status',
        'currency',
        'variant_sku',
        'variant_name',
        'variant_price',
        'variant_stock',
        'attributes',
    ];

    /** Columns describing the product itself; every row of a product must agree on them. */
    public const PRODUCT_DETAILS = ['product_name', 'product_status', 'currency'];

    /**
     * Key a row by the required column names: names are matched after trimming
     * whitespace and a UTF-8 byte-order mark, ignoring case. Unknown columns
     * are dropped.
     */
    public static function normalise(array $row): array
    {
        $normalised = [];

        foreach ($row as $name => $value) {
            $name = strtolower(trim(str_replace("\u{FEFF}", '', (string) $name)));

            if (in_array($name, self::REQUIRED, true)) {
                $normalised[$name] = $value;
            }
        }

        return $normalised;
    }

    /**
     * Required columns absent from an already-normalised row, in spec order.
     *
     * @return list<string>
     */
    public static function missingFrom(array $normalisedRow): array
    {
        return array_values(array_diff(self::REQUIRED, array_keys($normalisedRow)));
    }
}
