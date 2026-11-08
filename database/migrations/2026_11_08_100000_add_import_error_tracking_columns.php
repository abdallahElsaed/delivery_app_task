<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('import_batches', function (Blueprint $table) {
            $table->text('error_message')->nullable()->after('status');
        });

        Schema::table('import_batch_rows', function (Blueprint $table) {
            $table->boolean('retryable')->default(true)->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('import_batch_rows', function (Blueprint $table) {
            $table->dropColumn('retryable');
        });

        Schema::table('import_batches', function (Blueprint $table) {
            $table->dropColumn('error_message');
        });
    }
};
