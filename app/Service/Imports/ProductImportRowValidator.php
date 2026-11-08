<?php

namespace App\Service\Imports;

use App\Exceptions\ImportRowRejectedException;
use Illuminate\Support\Facades\Validator;

/**
 * Checks one normalised import row before it touches the database, and turns
 * its attributes cell into a name => value map.
 */
final class ProductImportRowValidator
{
    private const MAX_LENGTH = 255;

    // Largest value of the signed integer stock column.
    private const MAX_STOCK = 2147483647;

    /**
     * @return array the row's values, with `attributes` as value keyed by name
     *
     * @throws ImportRowRejectedException with every reason the row fails
     */
    public static function validate(array $row): array
    {
        $validator = Validator::make($row, [
            'product_sku' => 'bail|required|string|max:'.self::MAX_LENGTH,
            'product_name' => 'bail|required|string|max:'.self::MAX_LENGTH,
            'product_status' => 'bail|required|in:active,inactive,draft',
            'currency' => 'bail|required|regex:/^[A-Z]{3}\z/',
            'variant_sku' => 'bail|required|string|max:'.self::MAX_LENGTH,
            'variant_name' => 'bail|required|string|max:'.self::MAX_LENGTH,
            'variant_price' => 'bail|required|numeric|decimal:0,2|min:0|max:99999999.99',
            'variant_stock' => 'bail|required|integer|min:0|max:'.self::MAX_STOCK,
            'attributes' => 'nullable|string',
        ], [
            'product_status.in' => 'The product_status must be active, inactive or draft.',
            'currency.regex' => 'The currency must be 3 uppercase letters.',
        ], array_combine(ProductImportColumns::REQUIRED, ProductImportColumns::REQUIRED));

        $errors = $validator->errors()->all();
        $attributes = [];

        if (! $validator->errors()->has('attributes')) {
            [$attributes, $attributeErrors] = self::parseAttributes((string) ($row['attributes'] ?? ''));
            $errors = [...$errors, ...$attributeErrors];
        }

        if (! empty($errors)) {
            throw new ImportRowRejectedException(implode(' ', $errors));
        }

        return ['attributes' => $attributes] + $row;
    }

    /**
     * Parse `key:value|key:value`. Keys are compared ignoring case, as the
     * database's unique (variant, name) index does.
     *
     * @return array{array<string, string>, list<string>} the attributes, and what's wrong with them
     */
    private static function parseAttributes(string $cell): array
    {
        if (trim($cell) === '') {
            return [[], []];
        }

        $attributes = [];
        $seen = [];
        $errors = [];

        foreach (explode('|', $cell) as $pair) {
            $parts = array_map('trim', explode(':', $pair, 2));

            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                $errors[] = "Attribute '{$pair}' is not a key:value pair with a key and a value.";

                continue;
            }

            [$name, $value] = $parts;
            $key = mb_strtolower($name);

            if (mb_strlen($name) > self::MAX_LENGTH || mb_strlen($value) > self::MAX_LENGTH) {
                $errors[] = "Attribute {$name} is longer than ".self::MAX_LENGTH.' characters.';
            } elseif (isset($seen[$key])) {
                $errors[] = "Attribute {$name} appears more than once.";
            }

            $seen[$key] = true;
            $attributes[$name] = $value;
        }

        return [$attributes, array_values(array_unique($errors))];
    }
}
