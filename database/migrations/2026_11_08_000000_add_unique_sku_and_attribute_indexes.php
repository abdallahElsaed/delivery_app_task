<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEDUPE_BATCH_SIZE = 10000;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // MySQL can't roll back schema changes, so refuse up front rather than
        // fail half-way with attributes already deleted.
        $this->ensureSkusAreUnique('products');
        $this->ensureSkusAreUnique('product_variants');

        $this->deleteDuplicateAttributes();

        Schema::table('products', function (Blueprint $table) {
            $table->unique('sku');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique('sku');
        });

        // Add the unique index before dropping the old one, so the
        // product_variant_id foreign key always has an index to use.
        Schema::table('variant_attributes', function (Blueprint $table) {
            $table->unique(['product_variant_id', 'name']);
        });

        Schema::table('variant_attributes', function (Blueprint $table) {
            $table->dropIndex(['product_variant_id', 'name', 'value']);
        });
    }

    /**
     * Reverse the migrations. Deleted duplicate attributes are not restored.
     */
    public function down(): void
    {
        Schema::table('variant_attributes', function (Blueprint $table) {
            $table->index(['product_variant_id', 'name', 'value']);
        });

        Schema::table('variant_attributes', function (Blueprint $table) {
            $table->dropUnique(['product_variant_id', 'name']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['sku']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['sku']);
        });
    }

    private function ensureSkusAreUnique(string $table): void
    {
        $duplicates = DB::table($table)
            ->select('sku')
            ->groupBy('sku')
            ->havingRaw('COUNT(*) > 1')
            ->limit(5)
            ->pluck('sku');

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException(
                "Cannot add a unique index on {$table}.sku: duplicate SKUs exist (e.g. {$duplicates->implode(', ')}). Resolve them and re-run the migration."
            );
        }
    }

    /**
     * Keep the newest row (highest id) of every (product_variant_id, name)
     * pair, deleting the rest in batches to keep each statement short. Walks
     * forward by id so each pass only scans rows it hasn't checked yet.
     */
    private function deleteDuplicateAttributes(): void
    {
        $lastId = 0;

        do {
            $ids = DB::table('variant_attributes as older')
                ->where('older.id', '>', $lastId)
                ->whereExists(fn (Builder $query) => $query
                    ->select(DB::raw(1))
                    ->from('variant_attributes as newer')
                    ->whereColumn('newer.product_variant_id', 'older.product_variant_id')
                    ->whereColumn('newer.name', 'older.name')
                    ->whereColumn('newer.id', '>', 'older.id'))
                ->orderBy('older.id')
                ->limit(self::DEDUPE_BATCH_SIZE)
                ->pluck('older.id');

            if ($ids->isNotEmpty()) {
                DB::table('variant_attributes')->whereIn('id', $ids)->delete();
                $lastId = $ids->last();
            }
        } while ($ids->isNotEmpty());
    }
};
